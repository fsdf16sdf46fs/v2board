<?php

namespace App\Payments;
use App\Utils\CacheKey;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;

class EPay {
    private $config;
    private $client;

    public function __construct($config)
    {
        $this->config = $config;
        $this->client = new Client();
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        // 默认情况下，$money 为人民币
        $money = $order['total_amount'] / 100;

        // 如果是美元，则按照汇率换算成人民币
        if(config('v2board.currency') === 'USD'){
            $rate = $this->get_usd_to_cny_rate();
            $rate = $rate ?? config('v2board.default_usd_to_cny_rate', 7.15); // api 出错后，默认7.15
            // 上浮 3毛5
            //重要：如果更改了数值，记得手动重启PHP！！！ 否则有缓存，新值不生效
            $money = round($money * ($rate + 0.35), 2);
        }

        $mailName = config('v2board.email_from_address');
        $name = "使用QQ/微信很危险，如有订单问题请发邮件联系：" . $mailName . "，其他联系方式均无效！";

        $params = [
            'money' => $money,
            'name' => $name,
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];
        if (isset($this->config['type'])){
            $params['type']=$this->config['type'];
        }
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $this->config['url'] . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        // 仅处理支付成功的回调，防止未完成订单被错误入账
        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return false;
        }

        // 签名校验
        $sign = $params['sign'];
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        if (!hash_equals(md5($str), $sign)) {
            return false;
        }

        // 防 partial-pay：把订单"应付人民币"重算一遍，与 EPay 上报金额对比（都是 CNY 元）
        $expectedYuan = $this->calcYuan($params['out_trade_no']);
        $reportedYuan = isset($params['money']) ? (float)$params['money'] : 0.0;
        if ($expectedYuan === null) {
            return false;
        }
        // 容差：CNY 面板 0.01 元兜底浮点；USD 面板汇率在 pay/notify 之间可能波动，给 1% 容差
        $tolerance = 0.01;
        if (config('v2board.currency') === 'USD') {
            $tolerance = max(0.01, $expectedYuan * 0.01);
        }
        if ($reportedYuan + $tolerance < $expectedYuan) {
            return false;
        }

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }

    /**
     * 重算订单应付给 EPay 的人民币金额（与 pay() 同一份转换逻辑）
     * 返回元（含小数），找不到订单返回 null
     */
    private function calcYuan(string $tradeNo)
    {
        $order = \App\Models\Order::where('trade_no', $tradeNo)->first();
        if (!$order) return null;
        $yuan = $order->total_amount / 100;
        if (config('v2board.currency') === 'USD') {
            $rate = $this->get_usd_to_cny_rate() ?? config('v2board.default_usd_to_cny_rate', 7.15);
            $yuan = round($yuan * ($rate + 0.35), 2);
        }
        return $yuan;
    }

    private function get_usd_to_cny_rate()
    {
        $cacheKey = CacheKey::get('USD_TO_CNY_RATE', 'global');
        $rate = Cache::get($cacheKey);

        if (!$rate) {
            // quoteMinAmountPerOrder 语义是"交易额"，取 1000 元档过滤掉小额溢价商家（100 元档普遍高 0.07~0.08）
            $url = 'https://www.okx.com/v3/c2c/tradingOrders/books?quoteCurrency=CNY&baseCurrency=USDT&side=sell&paymentMethod=aliPay&userType=all&receivingAds=false&quoteMinAmountPerOrder=1000&t=' . time();

            try {
                $response = $this->client->get($url);
                $data = json_decode($response->getBody()->getContents(), true);

                // 获取第一个卖家的汇率价格 (sell-1) sell-0 表示第一个商家，为了避免商家出低价，选择第二个，即 sell-1
                if (isset($data['data']['sell'][1]['price'])) {
                    $rate = $data['data']['sell'][1]['price'];
                    Cache::put($cacheKey, $rate, 15); // 缓存汇率
                } else {
                    \Log::error("Failed to retrieve USD to CNY rate from the API response.");
                    return null;
                }
            } catch (GuzzleException $e) {
                \Log::error("Attempt to fetch from $url failed: " . $e->getMessage(), ['exception' => $e]);
                return null;
            } catch (\Exception $e) {
                \Log::error("Error during rate fetching from $url: " . $e->getMessage(), ['exception' => $e]);
                return null;
            }
        }

        return (float) $rate;
    }


}
