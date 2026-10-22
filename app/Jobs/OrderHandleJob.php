<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OrderHandleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $order;

    public $tries = 3;
    public $timeout = 5;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($tradeNo)
    {
        $this->onQueue('order_handle');
        $this->order = Order::where('trade_no', $tradeNo)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (!$this->order) return;
        $orderService = new OrderService($this->order);
        switch ($this->order->status) {
            // 取消订单
            case 0:
                if ($this->order->created_at <= (time() - 3600 * 2)) {
                    $orderService->cancel();
                }
                break;
            //开通订单
            case 1:
                if ($this->order->plan_id == 100) {
                    // 充值余额
                    $orderService->recharge();
                } else {
                    // 正常购买/续费 VPN 套餐
                    $this->handlePurchase($this->order, $orderService);
                }
                break;
        }
    }


    private function handlePurchase(Order $order, OrderService $orderService)
    {
        // 动态判断：只要周期类型是 setup_price，就视为 Pay As You Go 套餐
        if ($order->period == "setup_price") {
            // 开通【随用随付】
            $orderService->openPayAsYouGo();
        } elseif ($order->callback_no == 'auto_renew') {
            // 自动续费【按周期】套餐
            $orderService->autoRenew();
        } else {
            // 正常开通：【按周期】、【按流量】套餐
            $orderService->open();
        }
    }
}
