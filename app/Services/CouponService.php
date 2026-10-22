<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class CouponService
{
    public $coupon;
    public $planId;
    public $userId;
    public $period;
    public $userOrderStatus;
    public $userInviterId;

    public function __construct($code)
    {
        $this->coupon = Coupon::where('code', $code)
            ->lockForUpdate()
            ->first();
    }

    public function use(Order $order):bool
    {
        $this->setPlanId($order->plan_id);
        $this->setUserId($order->user_id);
        $this->setPeriod($order->period);
        $this->check();
        switch ($this->coupon->type) {
            case 1:
                $order->discount_amount = $this->coupon->value;
                break;
            case 2:
                $order->discount_amount = $order->total_amount * ($this->coupon->value / 100);
                break;
        }
        if ($order->discount_amount > $order->total_amount) {
            $order->discount_amount = $order->total_amount;
        }
        if ($this->coupon->limit_use !== NULL) {
            if ($this->coupon->limit_use <= 0) return false;
            $this->coupon->limit_use = $this->coupon->limit_use - 1;
            if (!$this->coupon->save()) {
                return false;
            }
        }
        return true;
    }

    public function getId()
    {
        return $this->coupon->id;
    }

    public function getCoupon()
    {
        return $this->coupon;
    }

    public function setPlanId($planId)
    {
        $this->planId = $planId;
    }

    public function setUserId($userId)
    {
        $this->userId = $userId;
    }

    public function setPeriod($period)
    {
        $this->period = $period;
    }

    public function setUserOrderStatus($user)
    {
        $this->userOrderStatus = ($user->has_Purchased_Plan_Before) ? 1 : 0;
    }

    public function setUserInviterId($user)
    {
        $this->userInviterId = $user->invite_user_id;
    }

    public function checkLimitUseWithUser():bool
    {
        $usedCount = Order::where('coupon_id', $this->coupon->id)
            ->where('user_id', $this->userId)
            ->whereNotIn('status', [0, 2])
            ->count();
        if ($usedCount >= $this->coupon->limit_use_with_user) return false;
        return true;
    }

    public function check()
    {
        if (!$this->coupon || !$this->coupon->show) {
            abort(500, __('Invalid coupon'));
        }
        if ($this->coupon->limit_use <= 0 && $this->coupon->limit_use !== NULL) {
            abort(500, __('This coupon is no longer available'));
        }
        if (time() < $this->coupon->started_at) {
            abort(500, __('This coupon has not yet started'));
        }
        if (time() > $this->coupon->ended_at) {
            abort(500, __('This coupon has expired'));
        }
        if ($this->coupon->limit_plan_ids && $this->planId) {
            // 双向转 int 再严格比较：admin UI 保存的 limit_plan_ids 可能是字符串数组（["2","5"]）
            // 而 $this->planId 来自 Order::plan_id 是 int；严格比较 5 !== "5" 会错杀。
            // cast 后再 strict：既不让 false/数组等类型混淆绕过，又允许合法 int/string 匹配。
            $allowedPlanIds = array_map('intval', $this->coupon->limit_plan_ids);
            if (!in_array((int)$this->planId, $allowedPlanIds, true)) {
                abort(500, __('The coupon code cannot be used for this subscription'));
            }
        }
        if ($this->coupon->limit_period && $this->period) {
            // 同款防御：双向转 string 后严格比较
            $allowedPeriods = array_map('strval', $this->coupon->limit_period);
            if (!in_array((string)$this->period, $allowedPeriods, true)) {
                abort(500, __('The coupon code cannot be used for this period'));
            }
        }
        if ($this->coupon->limit_use_with_user !== NULL && $this->userId) {
            if (!$this->checkLimitUseWithUser()) {
                abort(500, __('The coupon can only be used :limit_use_with_user per person', [
                    'limit_use_with_user' => $this->coupon->limit_use_with_user
                ]));
            }
        }

        // 检查用户是否为新注册用户（从来没有买过套餐）
        if ($this->coupon->only_for_new_user) {
            if($this->userOrderStatus) abort(500, __('该优惠券仅限新用户使用'));
        }

        // 检查邀请人限制
        if ($this->coupon->limit_inviter_ids) {
            $inviterIds = explode(',', $this->coupon->limit_inviter_ids);
            if(empty($this->userInviterId)){
                abort(500, __('由于您没有邀请人，无法判断您是否有资格使用本优惠券'));
            }
            if (!in_array((string)$this->userInviterId, $inviterIds, true)) {
                abort(500, __('您没有资格使用该邀请人的优惠券'));
            }
        }

    }


}
