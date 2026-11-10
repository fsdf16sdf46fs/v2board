<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlanService
{
    // 可设置赠送天数的周期；套餐字段为 {周期}_gift_days（平时）和 promo_{周期}_gift_days（限时活动）
    const GIFT_PERIODS = ['month', 'quarter', 'half_year', 'year', 'two_year', 'three_year'];
    const GIFT_DAYS_MAX = 366;

    public $plan;

    public function __construct(int $planId)
    {
        $this->plan = Plan::lockForUpdate()->find($planId);
    }

    public function haveCapacity(): bool
    {
        if ($this->plan->capacity_limit === NULL) return true;
        $count = self::countActiveUsers();
        $count = $count[$this->plan->id]['count'] ?? 0;
        return ($this->plan->capacity_limit - $count) > 0;
    }

    private static function clampGiftDays($days)
    {
        $days = (int)$days;
        if ($days <= 0) return 0;
        return min($days, self::GIFT_DAYS_MAX);
    }

    // 限时活动是否进行中：开始、截止时间都填了且当前在区间内
    public static function isPromoActive(Plan $plan)
    {
        $start = (int)$plan->promo_start_at;
        $end = (int)$plan->promo_end_at;
        if ($start <= 0 || $end <= 0) return false;
        $now = time();
        return $now >= $start && $now <= $end;
    }

    // 用户在该套餐的活动时间段内是否已领过活动赠送（全站任意套餐领过都算，每人限 1 次）
    public static function hasUsedPromo(Plan $plan, $userId)
    {
        return Order::where('user_id', $userId)
            ->whereBetween('gift_promo_at', [(int)$plan->promo_start_at, (int)$plan->promo_end_at])
            ->exists();
    }

    // 活动进行中且该用户还没领过
    private static function canUsePromo(Plan $plan, $userId)
    {
        return self::isPromoActive($plan) && !self::hasUsedPromo($plan, $userId);
    }

    /**
     * 某用户购买某周期实际能拿到的赠送天数。
     * 活动进行中、该周期设置了活动天数、且用户还没领过 → 活动天数；否则按平时的天数。
     * @return array ['days' => int, 'promo' => bool]
     */
    public static function resolveGift(Plan $plan, $period, $userId)
    {
        $key = substr((string)$period, 0, -6);
        if (substr((string)$period, -6) !== '_price' || !in_array($key, self::GIFT_PERIODS, true)) {
            return ['days' => 0, 'promo' => false];
        }
        $promoDays = self::clampGiftDays($plan->{'promo_' . $key . '_gift_days'});
        if ($promoDays > 0 && self::canUsePromo($plan, $userId)) {
            return ['days' => $promoDays, 'promo' => true];
        }
        return ['days' => self::clampGiftDays($plan->{$key . '_gift_days'}), 'promo' => false];
    }

    /**
     * 返回给用户前处理套餐：把 *_gift_days 换成该用户实际能拿到的天数，
     * 并附上活动信息（gift_promo_end_at / gift_promo_periods）。只改内存里的对象，不要对它调用 save()。
     */
    public static function applyUserGift(Plan $plan, $userId)
    {
        $promoPeriods = [];
        $hidden = ['promo_start_at', 'promo_end_at'];
        $canUsePromo = null;
        foreach (self::GIFT_PERIODS as $key) {
            $hidden[] = 'promo_' . $key . '_gift_days';
            $promoDays = self::clampGiftDays($plan->{'promo_' . $key . '_gift_days'});
            if ($promoDays <= 0) continue;
            if ($canUsePromo === null) $canUsePromo = self::canUsePromo($plan, $userId);
            if (!$canUsePromo) continue;
            $plan->{$key . '_gift_days'} = $promoDays;
            $promoPeriods[] = $key . '_price';
        }
        $plan->gift_promo_end_at = $promoPeriods ? (int)$plan->promo_end_at : null;
        $plan->gift_promo_periods = $promoPeriods;
        $plan->makeHidden($hidden);
        return $plan;
    }

    public static function countActiveUsers()
    {
        return User::select(
            DB::raw("plan_id"),
            DB::raw("count(*) as count")
        )
            ->where('plan_id', '!=', NULL)
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->groupBy("plan_id")
            ->get()
            ->keyBy('plan_id');
    }
}
