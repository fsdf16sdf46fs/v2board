<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function check(Request $request)
    {
        if (empty($request->input('code'))) {
            abort(500, __('Coupon cannot be empty'));
        }
        $couponService = new CouponService($request->input('code'));
        $couponService->setPlanId($request->input('plan_id'));
        $couponService->setUserId($request->user['id']);
        $user = User::find($request->user['id']);
        $couponService->setUserOrderStatus($user);
        $couponService->setUserInviterId($user);
        $couponService->setPeriod($request->input('period'));
        $couponService->check();
        return response([
            'data' => $couponService->getCoupon()
        ]);
    }
}
