<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Http\Requests\User\RechargeSave;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\TelegramService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Library\BitpayX;
use Library\Epay;
use Library\MGate;
use Omnipay\Omnipay;
use Stripe\Source;
use Stripe\Stripe;

class OrderController extends Controller
{
    public function fetch(Request $request)
    {
        $model = Order::where('user_id', $request->user['id'])
            ->orderBy('created_at', 'DESC');
        if ($request->input('status') !== null) {
            $model->where('status', $request->input('status'));
        }
        $order = $model->get();
        $plan = Plan::get();
        for ($i = 0; $i < count($order); $i++) {
            for ($x = 0; $x < count($plan); $x++) {
                if ($order[$i]['plan_id'] === $plan[$x]['id']) {
                    $order[$i]['plan'] = $plan[$x];
                }
            }
        }
        return response([
            'data' => $order->makeHidden(['id', 'user_id'])
        ]);
    }

    public function detail(Request $request)
    {
        $order = Order::where('user_id', $request->user['id'])
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        $order['plan'] = Plan::find($order->plan_id);
        $order['try_out_plan_id'] = (int)config('v2board.try_out_plan_id');
        if (!$order['plan']) {
            abort(500, __('Subscription plan does not exist'));
        }
        // 结算页预览用：换成该用户实际能拿到的赠送天数
        PlanService::applyUserGift($order['plan'], $request->user['id']);
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        // 排除'user_ip'键
        unset($order['user_ip']);
        return response([
            'data' => $order
        ]);
    }

    public function save(OrderSave $request)
    {
        if (!filter_var($request->ip(), FILTER_VALIDATE_IP)) {
            abort(500, '非法IP地址');
        }

        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($request->user['id'])) {
            abort(500, __('You have an unpaid or pending order, please try again later or cancel it'));
        }

        $planService = new PlanService($request->input('plan_id'));
        $plan = $planService->plan;
        $user = User::find($request->user['id']);

        if (!$plan) {
            abort(500, __('Subscription plan does not exist'));
        }

        // 防止 Pay as you go 套餐重复购买
        if ($plan->setup_price > 0 && $user->is_PAGO == 1) {
            abort(500, __('This plan does not require repeated purchases; just maintain a sufficient balance'));
        }

        if ($user->plan_id !== $plan->id && !$planService->haveCapacity() && $request->input('period') !== 'reset_price') {
            abort(500, __('Current product is sold out'));
        }

        if ($plan[$request->input('period')] === NULL) {
            abort(500, __('This payment period cannot be purchased, please choose another period'));
        }

        if ($request->input('period') === 'reset_price') {
            if (!$userService->isAvailable($user) || $plan->id !== $user->plan_id) {
                abort(500, __('Subscription has expired or no active subscription, unable to purchase Data Reset Package'));
            }
        }

        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            if ($request->input('period') !== 'reset_price') {
                abort(500, __('This subscription has been sold out, please choose another subscription'));
            }
        }

        if (!$plan->renew && $user->plan_id == $plan->id && $request->input('period') !== 'reset_price') {
            abort(500, __('This subscription cannot be renewed, please change to another subscription'));
        }

        if (!$plan->show && $plan->renew && !$userService->isAvailable($user)) {
            abort(500, __('This subscription has expired, please change to another subscription'));
        }

        // 记录一次性套餐用户的流量
        if($user->plan_id != NULL){
            $planService2 = new PlanService($user->plan_id);
            $currentPlan = $planService2->plan;
            $remainTransfer = ($user->transfer_enable - $user->u - $user->d) / (1024*1024*1024);
            if($currentPlan->onetime_price > 0 && $remainTransfer > 0 && $plan->onetime_price > 0){
                $now = time();
                $datetime = date("Y-m-d H:i:s", $now);
                $telegramService = new TelegramService();
                $notification = "✍️记录【按流量】套餐的用户的剩余可用流量\n"
                    . "———————————————\n"
                    . "记录时间： `" . $datetime . "`\n"
                    . "邮箱： `{$user->email}`\n"
                    . "剩余流量： `" . $remainTransfer . "` GB\n";
                $telegramService->sendMessageWithAdmin($notification, true);
            }
        }

        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $request->user['id'];
        //记录下单IP
        $client_ip = $request->ip();
        if(!$user->is_admin) $order->user_ip = $client_ip;

        $order->plan_id = $plan->id;
        $order->period = $request->input('period');
        $order->trade_no = Helper::generateOrderNo();
        $order->total_amount = $plan[$request->input('period')];

        if ($request->input('coupon_code')) {
            $couponService = new CouponService($request->input('coupon_code'));
            if (!$couponService->use($order)) {
                DB::rollBack();
                abort(500, __('Coupon failed'));
            }
            $order->coupon_id = $couponService->getId();
        }

        $orderService->setVipDiscount($user);
        $orderService->setOrderType($user);
        $orderService->setInvite($user);

        if ($user->balance && $order->total_amount > 0) {
            $remainingBalance = $user->balance - $order->total_amount;
            $userService = new UserService();
            if ($remainingBalance > 0) {
                if (!$userService->addBalance($order->user_id, - $order->total_amount)) {
                    DB::rollBack();
                    abort(500, __('Insufficient balance'));
                }
                $order->balance_amount = $order->total_amount;
                $order->total_amount = 0;
            } else {
                if (!$userService->addBalance($order->user_id, - $user->balance)) {
                    DB::rollBack();
                    abort(500, __('Insufficient balance'));
                }
                $order->balance_amount = $user->balance;
                $order->total_amount = $order->total_amount - $user->balance;
            }
        }

        if (!$order->save()) {
            DB::rollback();
            abort(500, __('Failed to create order'));
        }

        DB::commit();

        return response([
            'data' => $order->trade_no
        ]);
    }

    public function saveForRecharge(RechargeSave $request)
    {
        if (!filter_var($request->ip(), FILTER_VALIDATE_IP)) {
            abort(500, '非法IP地址');
        }

        $user = User::find($request->user['id']);
        $userService = new UserService();

        // 确保充值前至少有过一次套餐购买记录，确保佣金发放
        if($user->has_Purchased_Plan_Before == 0){
            abort(500, __('Please purchase a plan first before topping up'));
        }

        if ($userService->isNotCompleteOrderByUserId($request->user['id'])) {
            abort(500, __('You have an unpaid or pending order, please try again later or cancel it'));
        }

        // 获取货币单位
        $currency = config('v2board.currency') == 'USD' ? "美元" : "元";

        //注意：前端提交的数据已经乘以过100了，如用户充值5元，下面获取到的是 500
        $rechargeAmount = $request->input('recharge_amount');
        $telegramService = new TelegramService();
        $notification = "✍️记录充值历史\n"
            . "———————————————\n"
            . "邮箱： `{$user->email}`\n"
            . "原始余额： `" . ($user->balance / 100) . " $currency`\n"
            . "欲充值金额： `" . ($rechargeAmount / 100) . " $currency`\n";

        $telegramService->sendMessageWithAdmin($notification, true);

        DB::beginTransaction();
        $order = new Order();
        $order->user_id = $request->user['id'];
        //记录下单IP
        if(!$user->is_admin) $order->user_ip = $request->ip();

        // 管理员需要在后台新增一个套餐。
        // 套餐名字可取为：充值
        // 套餐价格随意填，因为订单金额不从套餐里获取，而是从前端提交的数据获取。
        // 套餐ID需到数据库改一个大一点的，防止冲突，如 100
        $order->plan_id = 100;
        // 既然是充值，所以强制设置为 一次性套餐
        $order->period = 'onetime_price';
        $order->trade_no = Helper::generateOrderNo();
        $order->total_amount = $rechargeAmount;
        // 直接设置成 续费，防止前端提示：您是否要更换套餐？ 从而防止增加不必要的误会
        $order->type = 2;

        if (!$order->save()) {
            DB::rollback();
            abort(500, __('Failed to create order'));
        }

        DB::commit();

        return response([
            'data' => $order->trade_no
        ]);
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->where('status', 0)
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        // 余额比订单价格大 直接用余额支付全部款项
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no)) abort(500, '');
            $this->notify($order);
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $payment = Payment::find($method);
        if (!$payment || $payment->enable !== 1) abort(500, __('Payment method is not available'));
        $paymentService = new PaymentService($payment->payment, $payment->id);
        $order->handling_amount = NULL;
        if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
            $order->handling_amount = round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
        }
        $order->payment_id = $method;
        if (!$order->save()) abort(500, __('Request failed, please try again later'));

        // origin site — 自动用当前请求的 Host，即用户在哪个域名下单就跳回哪个域名
        // 安全性依赖：nginx server_name 限定合法域名 + HTTPS 证书阻止任意 Host
        $origin = $request->getSchemeAndHttpHost();

        $result = $paymentService->pay([
            'trade_no' => $tradeNo,
            'total_amount' => isset($order->handling_amount) ? ($order->total_amount + $order->handling_amount) : $order->total_amount,
            'user_id' => $order->user_id,
            'stripe_token' => $request->input('token'),
            'origin' => $origin
        ]);
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        return response([
            'data' => $order->status
        ]);
    }

    public function getPaymentMethod()
    {
        $methods = Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        return response([
            'data' => $methods
        ]);
    }


    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            abort(500, __('Invalid parameter'));
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        if ($order->status !== 0) {
            abort(500, __('You can only cancel pending orders'));
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            abort(500, __('Cancel failed'));
        }

        $user = User::find($order->user_id);
        $telegramService = new TelegramService();

        // 获取货币单位
        $currency = config('v2board.currency') == 'USD' ? "美元" : "元";

        $notification = "❌订单取消\n"
            . "———————————————\n"
            . "订单号： `{$request->input('trade_no')}`\n"
            . "邮箱： `{$user->email}`\n"
            . "余额： `" . ($user->balance / 100) . "` $currency\n";

        $telegramService->sendMessageWithAdmin($notification, true);

        return response([
            'data' => true
        ]);
    }

    private function notify(Order $order){
        // type
        $types = [1 => "新购", 2 => "续费", 3 => "变更" , 4 => "流量包"];
        $type = $types[$order->type] ?? "未知";

        // planName
        $planName = "";
        $plan = Plan::find($order->plan_id);
        if ($plan) {
            $planName = $plan->name;
        }

        // period
        // 定义英文到中文的映射关系
        $periodMapping = [
            'month_price' => '月付',
            'quarter_price' => '季付',
            'half_year_price' => '半年付',
            'year_price' => '年付',
            'two_year_price' => '2年付',
            'three_year_price' => '3年付',
            'onetime_price' => '一次性付款',
            'setup_price' => '设置费',
            'reset_price' => '流量重置包'
        ];
        $period = $periodMapping[$order->period] ?? "未知";

        // email
        $userEmail = "";
        $user = User::find($order->user_id);
        if ($user){
            $userEmail = $user->email;
        }

        // inviterEmail  inviterCommission
        $inviterEmail = '';
        $getAmount = 0; // 本次佣金
        $anotherInfo = "邀请人：该用户不存在邀请人";

        // 获取货币单位
        $currency = config('v2board.currency') == 'USD' ? "美元" : "元";

        if (!empty($order->invite_user_id)) {
            $inviter = User::find($order->invite_user_id);
            if ($inviter) {
                $inviterEmail = $inviter->email;
                $getAmount = $this->getCommission($inviter->id, $order); // 本次佣金

                if ((int)config('v2board.withdraw_close_enable', 0)) {
                    $inviterBalance = $inviter->balance / 100 + $getAmount; // 总余额 （关闭提现）
                    $anotherInfo = "邀请人总余额：" . $inviterBalance. " $currency";
                } else {
                    $inviterCommissionBalance = $inviter->commission_balance / 100 + $getAmount; // 总佣金 （允许提现）
                    $anotherInfo = "邀请人总佣金：" . $inviterCommissionBalance. " $currency";
                }
            }
        }

        $message = sprintf(
            "💰成功收款 %s $currency\n———————————————\n订单号：`%s`\n邮箱： `%s`\n套餐：%s\n类型：%s\n周期：%s\n邀请人邮箱： `%s`\n本次佣金：%s $currency\n%s",
            $order->total_amount / 100,
            $order->trade_no,
            $userEmail,
            $planName,
            $type,
            $period,
            $inviterEmail,
            $getAmount,
            $anotherInfo
        );
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message,true);
    }

    private function getCommission($inviteUserId, $order)
    {
        $getAmount = 0;
        $level = 3;
        if ((int)config('v2board.commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int)config('v2board.commission_distribution_l1'),
                1 => (int)config('v2board.commission_distribution_l2'),
                2 => (int)config('v2board.commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [
                0 => 100
            ];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = User::find($inviteUserId);
            if (!$inviter) continue;
            if (!isset($commissionShareLevels[$l])) continue;
            $getAmount = $order->commission_balance * ($commissionShareLevels[$l] / 100);
            if (!$getAmount) continue;
        }
        return $getAmount / 100;
    }
}
