<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    const STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function open()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);

        if ($order->refund_amount) {
            $this->user->balance = $this->user->balance + $order->refund_amount;
        }
        DB::beginTransaction();
        if ($order->surplus_order_ids) {
            try {
                Order::whereIn('id', $order->surplus_order_ids)->update([
                    'status' => 4
                ]);
            } catch (\Exception $e) {
                DB::rollback();
                abort(500, '开通失败');
            }
        }
        switch ((string)$order->period) {
            case 'onetime_price':
                $this->buyByOneTime($plan);
                break;
            case 'reset_price':
                $this->buyByResetTraffic();
                break;
            default:
                $this->buyByPeriod($order, $plan);
        }

        switch ((int)$order->type) {
            case 1:
                $this->openEvent(config('v2board.new_order_event_id', 0));
                break;
            case 2:
                $this->openEvent(config('v2board.renew_order_event_id', 0));
                break;
            case 3:
                $this->openEvent(config('v2board.change_order_event_id', 0));
                break;
        }

        $this->setSpeedLimit($plan->speed_limit);

        // 更新用户购买记录，区分新/老用户
        $this->updateHasPurchasedPlanStatus();

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }

        DB::commit();

        ////调用邮件提醒
        $mailService = new MailService();
        $mailService->remindUpdateSub($this->user, $plan);//必须是这个参数
    }

    public function autoRenew()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);

        DB::beginTransaction();

        // 【按周期】续费套餐
        $this->buyByPeriod($order, $plan);
        // 类型是续费
        $this->openEvent(config('v2board.renew_order_event_id', 0));

        $this->setSpeedLimit($plan->speed_limit);

        // 更新用户购买记录，区分新/老用户
        $this->updateHasPurchasedPlanStatus();

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, '自动续费失败');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, '自动续费失败');
        }

        DB::commit();

        ////调用邮件提醒
        $mailService = new MailService();
        $mailService->remindOrderRenewed($this->user, $plan);//必须是这个参数
    }

    public function recharge()
    {
        DB::beginTransaction();
        // 管理员在后台设置的 充值优惠比例 以及 活动门槛
        // 路径：/config/v2board.php
        // 之后，记得修改管理员前端，方便后续修改

        // 门槛： 100 美元   不够 100 则没有优惠，即充多少是多少。
        $discountThreshold = config('v2board.discount_threshold', 100 * 100);
        // 优惠比例： 10% — 代码层硬上限 50%，防止配置失误或后台被入侵导致超额赠送
        $discount = min((float)config('v2board.recharge_discount', 20) * 0.01, 0.5);
        if ($discount < 0) $discount = 0;


        $order = $this->order;
        // 余额是读-改-写，必须在事务内锁行读。
        // 全站的扣款都走 UserService::addBalance()（自带 lockForUpdate），但充值入账原先不参与那把锁，
        // 单边持锁等于没有锁：用陈旧余额写回会把这期间已提交的扣款整笔抹掉，
        // 用户可以一边充值一边用余额下单，白得套餐还保住余额。
        $this->user = User::where('id', $order->user_id)->lockForUpdate()->first();
        if (!$this->user) {
            DB::rollBack();
            abort(500, '充值失败');
        }
        $rechargeAmount = $order->total_amount;
        $rechargeAmountGotten = ($rechargeAmount >= $discountThreshold)? $rechargeAmount * (1 + $discount) : $rechargeAmount;
        $this->user->balance = $this->user->balance + $rechargeAmountGotten;

        // 【动态重算逻辑】充值导致余额增加，PAGO 用户需重算流量
        if($this->user->is_PAGO == 1){
            $this->recalculatePagoTraffic($this->user);
        }

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, '充值失败');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, '充值失败');
        }

        DB::commit();

        ////调用邮件提醒
        $mailService = new MailService();
        $mailService->remindRechargeDone($this->user, $rechargeAmount, $rechargeAmountGotten, $this->user->balance);//必须是这个参数
        ////调用邮件提醒
    }

    public function openPayAsYouGo()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);

        DB::beginTransaction();

        $this->buyByPayAsYouGo($plan, $this->user);
        $this->setSpeedLimit($plan->speed_limit);

        // 更新用户购买记录，区分新/老用户
        $this->updateHasPurchasedPlanStatus();

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }

        DB::commit();

        ////调用邮件提醒
        $mailService = new MailService();
        $mailService->remindUpdateSub($this->user, $plan);//必须是这个参数
        ////调用邮件提醒

    }

    /**
     * 【核心方法】PAGO 用户的流量重算逻辑
     * 注意：此方法只负责修改 User 对象内存中的属性，不执行 save() 操作。
     * 调用方需要在调用此方法后，自行执行 $user->save() 进行持久化。
     */
    private function recalculatePagoTraffic(User $user)
    {
        $plan = Plan::find($user->plan_id);
        // 确保套餐存在且单价配置正确（避免除以零）
        if (!$plan || !$plan->transfer_unit_price) return;

        // 1. 重置已用流量 (因为余额已经作为新的一笔资金来计算了)
        $user->u = 0;
        $user->d = 0;

        // 2. 重新计算总流量限制： 余额(分) / 单价(分/G) = GB -> Bytes
        $user->transfer_enable = round($user->balance / $plan->transfer_unit_price) * 1024 * 1024 * 1024;

        // 3. 重置过期时间 (随用随付无过期时间)
        $user->expired_at = NULL;

    }

    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int)config('v2board.plan_change_enable', 1)) abort(500, '目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = 3;
            if ((int)config('v2board.surplus_enable', 1)) $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount = $order->total_amount - $order->surplus_amount;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = 2;
        } else { // 新购
            $order->type = 1;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0)) return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) return;
        $isCommission = false;
        switch ((int)$inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)config('v2board.commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) return;
        if ($inviter && $inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (config('v2board.invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        $plan = Plan::find($user->plan_id);
        if (!$plan) return;
        // 排除 Pay as you go 套餐
        if ($user->is_PAGO == 1) return;
        // 如果套餐是按流量卖的，没有过期时间，则直接按照剩余流量残值计算
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByTransfer($user, $order, $plan);
            return;
        }

        // 如果套餐是按周期卖的，先计算剩余时间残值，然后加上剩余流量残值
        $this->getSurplusValueByTime($user, $order, $plan);
        $this->getSurplusValueByTransfer($user, $order, $plan);
    }

    private function getSurplusValueByTime(User $user, Order $order, Plan $plan)
    {
        if (!$plan['daily_unit_price']) return;

        $timeLeftDays = ($user['expired_at'] - time()) / 86400;

        if (!$timeLeftDays) return;
        // 如果套餐剩余时长小于 31 天，则不计算时间残值
        if ($timeLeftDays < 31) return;

        // 如果套餐剩余时长大于 31 天，则只计算整月，剩余部分是按剩余流量残值计算
        $realTimeLeftDays = intval($timeLeftDays / 31 ) * 31;

        $dailyUnitPrice = $plan['daily_unit_price'] / 100;
        $order->surplus_amount = $order->surplus_amount + ($realTimeLeftDays * $dailyUnitPrice) * 100;
    }

    private function getSurplusValueByTransfer(User $user, Order $order, Plan $plan)
    {
        if (!$plan['transfer_unit_price']) return;
        $transferLeft = ($user['transfer_enable'] - ($user['u'] + $user['d'])) / 1073741824;
        if (!$transferLeft) return;
        // 如果套餐剩余流量为 0 或者负数，则不计算剩余流量残值
        if ($transferLeft <= 0) return;

        $transferUnitPrice = $plan['transfer_unit_price'] / 100;
        $order->surplus_amount = $order->surplus_amount + ($transferLeft * $transferUnitPrice) * 100;
    }

    public function paid(string $callbackNo)
    {
        $order = $this->order;
        // 「待支付 → 开通中」必须是原子状态迁移，交给数据库的条件更新来做。
        //
        // 旧写法先判断内存里的 status 再 save()，而 Eloquent 的 save() 生成的是
        // `UPDATE ... WHERE id = ?`，不带任何状态前置条件，于是：
        //   1) 两个并发 checkout 都能通过判断，各自 dispatch 一次开通任务，套餐被重复开通；
        //   2) 更严重的是 cancel() 已经把订单置为 2 并退还余额之后，持有陈旧对象的请求仍会把
        //      status 无条件覆写回 1 并开通套餐 —— 用户既拿回余额又拿到套餐，且可无限重复。
        //      InnoDB 的行锁在这里帮的是倒忙：本请求的 UPDATE 会排队等 cancel 提交完退款，
        //      解除阻塞后才覆写，反而让攻击时序变得稳定可复现。
        $affected = Order::where('id', $order->id)
            ->where('status', 0)
            ->update([
                'status' => 1,
                'paid_at' => time(),
                'callback_no' => $callbackNo
            ]);
        if (!$affected) {
            // 订单已不在待支付状态。重复回调按幂等处理并返回成功，避免支付网关无限重试；
            // 但若它既不是「开通中」也不是「已完成」，说明订单已被取消却仍收到支付成功回调，
            // 属于必须人工介入的异常（可能要退款或补开通），留痕。
            // 这里不硬编码「状态 2」：status=4 目前不可达，但不该把这个前提焊进安全日志。
            $status = (int)Order::where('id', $order->id)->value('status');
            if ($status !== 1 && $status !== 3) {
                Log::error("订单 {$order->trade_no} 当前状态为 {$status}，却收到支付成功回调 callback_no={$callbackNo}，请人工核对是否需要退款或补开通");
            }
            return true;
        }
        // 只有真正完成 0 → 1 迁移的调用才会走到这里。
        // 同步内存对象后立刻 syncOriginal()，把模型标记为「干净」：
        // 否则调用方之后若再 save() 一次，又会变成一条无条件的 status=1 写入，
        // 把已经开通完成（status=3）的订单打回开通中，正是本次要根除的那类问题。
        $order->status = 1;
        $order->paid_at = time();
        $order->callback_no = $callbackNo;
        $order->syncOriginal();
        try {
            OrderHandleJob::dispatchNow($order->trade_no);
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    public function cancel(): bool
    {
        DB::beginTransaction();
        $order = Order::where('id', $this->order->id)->lockForUpdate()->first();
        if (!$order || $order->status !== 0) {
            DB::rollBack();
            return false;
        }
        $order->status = 2;
        if (!$order->save()) {
            DB::rollBack();
            return false;
        }
        if ($order->balance_amount) {
            $userService = new UserService();
            if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                DB::rollBack();
                return false;
            }

            $user = User::find($order->user_id);
            if ($user && $user->is_PAGO == 1) {
                $this->recalculatePagoTraffic($user);
                $user->save();
            }
        }
        DB::commit();
        return true;
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function buyByResetTraffic()
    {
        $this->user->u = 0;
        $this->user->d = 0;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL) $this->buyByResetTraffic();
        // 新购
        if ($order->type === 1) $this->buyByResetTraffic();


        // 到期当天续费刷新流量
        $expireDay = date('d', $this->user->expired_at);
        $expireMonth = date('m', $this->user->expired_at);
        $today = date('d');
        $currentMonth = date('m');
        if ($order->type === 2 && $expireMonth == $currentMonth && $expireDay === $today ) {
            $this->buyByResetTraffic();
        }


        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
        $this->user->is_PAGO = 0;
    }

    private function buyByOneTime(Plan $plan)
    {
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
        $this->user->is_PAGO = 0;
    }

    private function buyByPayAsYouGo(Plan $plan, User $user)
    {
        $this->buyByResetTraffic();
        // 初始购买时，根据当前余额计算
        $this->user->transfer_enable = round($user->balance / $plan->transfer_unit_price) * 1024 * 1024 * 1024;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
        $this->user->is_PAGO = 1;
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }

    private function openEvent($eventId)
    {
        switch ((int)$eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }

    private function updateHasPurchasedPlanStatus()
    {
        //如果 $this->user->has_Purchased_Plan_Before 的值为 0，它会将其设置为 1；如果已经是 1，则保持不变。
        $this->user->has_Purchased_Plan_Before |= 1;
    }
}
