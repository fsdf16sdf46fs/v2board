<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Jobs\UploadSubscribeRecordsJob;
use App\Models\Tokenrequest;
use App\Protocols\General;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\CampusNetwork;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Ip2Region;
use GeoIp2\Database\Reader;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {

        $client_ip = $request->ip();

        if (!filter_var($client_ip, FILTER_VALIDATE_IP)) {
            $response = [
                'error' => '非法IP地址'
            ];
            return response()->json($response, Response::HTTP_FORBIDDEN);
        }

        $servers = [];
        $user = $request->user;
        $userService = new UserService();

        // === IP Sentinel: 黑名单检查 (暂时禁用，先测试记录上传功能) ===
        // if (!$user->is_admin) {
        //     $sentinelUrl = config('v2board.ip_sentinel_url');
        //     $sentinelKey = config('v2board.ip_sentinel_api_key');
        //     if ($sentinelUrl && $sentinelKey) {
        //         $blacklistCacheKey = CacheKey::get('IP_SENTINEL_BLACKLIST', $client_ip);
        //         $cachedResult = Cache::get($blacklistCacheKey);
        //
        //         if ($cachedResult === null) {
        //             try {
        //                 $httpClient = new \GuzzleHttp\Client(['timeout' => 3]);
        //                 $resp = $httpClient->get(rtrim($sentinelUrl, '/') . '/api/check/' . $client_ip, [
        //                     'headers' => ['X-API-Key' => $sentinelKey],
        //                 ]);
        //                 $result = json_decode($resp->getBody(), true);
        //                 $isBlacklisted = $result['blacklisted'] ?? false;
        //                 Cache::put($blacklistCacheKey, $isBlacklisted ? '1' : '0', 300);
        //                 if ($isBlacklisted) {
        //                     return redirect('https://bilibili.com');
        //                 }
        //             } catch (\Exception $e) {
        //                 // 失败开放: ip-sentinel 不可达时放行, 短 TTL
        //                 Cache::put($blacklistCacheKey, '0', 60);
        //             }
        //         } elseif ($cachedResult === '1') {
        //             return redirect('https://bilibili.com');
        //         }
        //     }
        // }

        // 自研客户端：UA 与后台「自研客户端 UA 白名单」中的某一行完全一致
        $isSelfClient = $this->isSelfClientUA((string)$request->header('User-Agent'));

        $ua = (string)$this->antiXss->xss_clean((string)$request->header('User-Agent'));

        // 后台「仅允许自研客户端及指定第三方客户端」总开关：开启时只有自研客户端和
        // 「第三方 UA 白名单关键词」命中的客户端（小火箭还要满足最低版本）能拿到真实节点
        $restrict = (int)config('v2board.client_restrict_enable', 0) === 1;
        $isAllowedThirdParty = $restrict && !$isSelfClient && $this->matchThirdPartyUA($ua);

        // UA过滤（白名单关键词命中的客户端不受内置列表限制）
        if (!$isSelfClient && !$isAllowedThirdParty && !$this->checkUA($ua)) {
            return redirect('https://bilibili.com');
        }

        // 不放行的已知客户端（Clash Verge、旧版小火箭等）只下发提示节点，让旧节点被替换掉、
        // 用户立刻知道要换客户端/升级。放在多 IP 统计之前：没换客户端的旧订阅还在后台
        // 自动更新，不能占用户的 IP 名额。
        if ($restrict && !$isSelfClient) {
            $notice = null;
            if (!$isAllowedThirdParty) {
                $notice = ['本客户端已停止支持', '请前往官网下载 V2Pass 客户端', '如需帮助，可工单/邮件联系'];
            } elseif (stripos($ua, 'shadowrocket') !== false
                && $this->extractShadowrocketBuild($ua) < (int)config('v2board.shadowrocket_min_build', 3378)) {
                $notice = ['您的小火箭版本过低', '请到 App Store 更新小火箭', '然后再更新订阅'];
            }
            if ($notice !== null) {
                $servers = array_map(function ($name) {
                    return [
                        'type' => 'shadowsocks',
                        'host' => 'baidu.com',
                        'port' => 8888,
                        'cipher' => 'aes-128-gcm',
                        'name' => $name,
                    ];
                }, $notice);
                $flag = strtolower($this->antiXss->xss_clean($request->input('flag'))
                    ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
                $this->output($user, $servers, $flag);
            }
        }

        // 过滤封禁用户
        if ($userService->isBanned($user)){
            $response = [
                'error' => '您已被封禁'
            ];
            return response()->json($response, Response::HTTP_FORBIDDEN);
        }

        $userIP = $client_ip;
        $userID = $user->id;

        // 获取用户IP所在的地区
        $userISPInfo = Helper::getUserISP($userIP);

        // 禁止多IP更新，管理员除外
        if(!$user->is_admin){
            if (!$this->checkTokenRequest($userID, $userIP, $userISPInfo)) {
                $response = [
                    'error' => '您的请求IP过多，已暂时禁止您更新订阅'
                ];
                return response()->json($response, Response::HTTP_FORBIDDEN);
            }
        }

        // === IP Sentinel: 记录缓冲上传 ===
        if (config('v2board.ip_sentinel_url')) {
            $record = json_encode([
                'timestamp' => time(),
                'email' => $user->email,
                'ip' => $client_ip,
                'user_agent' => $ua,
            ]);
            $bufferKey = 'ip_sentinel_records_buffer';
            Redis::rpush($bufferKey, $record);
            $bufferLen = Redis::llen($bufferKey);
            if ($bufferLen >= 50) {
                $items = Redis::lrange($bufferKey, 0, 49);
                Redis::ltrim($bufferKey, 50, -1);
                $records = array_map(function ($item) {
                    return json_decode($item, true);
                }, $items);
                UploadSubscribeRecordsJob::dispatch($records);
            }
        }

        if ($restrict) {
            // 开关开启时走到这里的只有放行的客户端：只认 UA、忽略 flag 参数。
            // 否则伪装成小火箭 UA 再带 &flag=meta 就能拿到完整的 Clash Meta 配置
            $flag = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        } else {
            //优先识别 flag 然后识别 UA
            $flag = strtolower($this->antiXss->xss_clean($request->input('flag'))
                ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }

        // 检查 3 种情况： 【按周期】套餐过期、【按流量】套餐满流量、【随用随付】套餐没余额

        $hasDirectPlan = $userService->hasDirectPlan($user);

        $URL = config('v2board.app_url');
        $commonArray = [
            'type' => 'shadowsocks',
            'host' => 'baidu.com',
            'port' => 8888,
            'cipher' => 'aes-128-gcm',
        ];

        $array1 = $commonArray;
        $array2 = $commonArray;
        $array3 = $commonArray;

        // 下面的分支里，只有最后的 else 下发真实节点；其余分支只下发"提示节点"
        $noticeOnly = true;

        // 校园网（教育网/科技网）IP 优先拦截：只下发提示节点，不给真实节点
        if ((int)config('v2board.campus_block_enable', 1) && CampusNetwork::contains($client_ip)) {
            $array1['name'] = "请断开校园网等公共网络";
            $array2['name'] = "使用自身流量或热点";

            $servers[] = $array1;
            $servers[] = $array2;
        }elseif($userService->hasPlanButExpired($user)){
            $array1['name'] = "您的套餐已过期";
            $array2['name'] = "请到官网续费";
            $array3['name'] = "如需帮助，可工单/邮件联系";

            $servers[] = $array1;
            $servers[] = $array2;
            $servers[] = $array3;
        }elseif ($userService->hasPlanButExhausted($user)){
            $array1['name'] = "您的流量已耗尽";
            $array2['name'] = "请到官网续费";
            $array3['name'] = "如需帮助，可工单/邮件联系";

            $servers[] = $array1;
            $servers[] = $array2;
            $servers[] = $array3;
        }elseif (($user->is_PAGO == 1 && $user->balance == 0)){
            $array1['name'] = "您的余额不足";
            $array2['name'] = "请到官网充值";
            $array3['name'] = "如需帮助，可工单/邮件联系";

            $servers[] = $array1;
            $servers[] = $array2;
            $servers[] = $array3;
        }elseif ($hasDirectPlan && !$isSelfClient && !$this->supportRalityAndHisteria2($flag) ){
            $array1['name'] = "本客户端不支持直连套餐";
            $array2['name'] = "请您下载其他客户端";
            $array3['name'] = "然后在新的客户端导入订阅";

            $servers[] = $array1;
            $servers[] = $array2;
            $servers[] = $array3;
        } else{
            $noticeOnly = false;
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            // 自研客户端在界面里展示套餐/流量，不需要"信息节点"
            if (!$isSelfClient) {
                $this->setSubscribeInfoToServers($servers, $user, $userISPInfo);
            }
        }

        // 自研客户端固定下发 mihomo(ClashMeta) 配置；提示信息走响应头，不混进节点列表
        if ($isSelfClient) {
            if ($noticeOnly) {
                $notice = implode('，', array_column($servers, 'name'));
                header('X-Client-Notice: ' . rawurlencode($notice));
                $servers = [];
            }
            $class = new \App\Protocols\ClashMeta($user, $servers);
            die($class->handle());
        }

        $this->output($user, $servers, $flag);
    }

    // 按 flag（已转小写）选协议格式输出，匹配不到时用通用格式
    private function output($user, array $servers, string $flag)
    {
        if ($flag) {
            foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                $file = 'App\\Protocols\\' . basename($file, '.php');
                $class = new $file($user, $servers);
                if (strpos($flag, $class->flag) !== false) {
                    die($class->handle());
                }
            }
        }
        $class = new General($user, $servers);
        die($class->handle());
    }

    private function setSubscribeInfoToServers(&$servers, $user, $info)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);

        $rate=config('v2board.invite_commission');

        array_unshift($servers, array_merge($servers[0], [
            'name' => "💰 邀请好友得 {$rate} % 佣金 ",
        ]));

        array_unshift($servers, array_merge($servers[0], [
            'name' => "⏳ 套餐到期：{$expiredDate}",
        ]));

        if($user->expired_at !== NULL){
            $expireMonth = date('m', $user->expired_at);
            $currentMonth = date('m');
            if ($expireMonth != $currentMonth) {
                if ($resetDay) {
                    array_unshift($servers, array_merge($servers[0], [
                        'name' => "🔄 距离下次重置剩余：{$resetDay} 天",
                    ]));
                }
            }
        }

        array_unshift($servers, array_merge($servers[0], [
            'name' => "📶 您的网络：{$info} ",
        ]));
    }


    // 自研客户端 UA 白名单：后台每行一个，大小写敏感、完全匹配
    private function isSelfClientUA(string $ua): bool
    {
        $ua = trim($ua);
        if ($ua === '') {
            return false;
        }
        $lines = preg_split('/\r\n|\r|\n/', (string)config('v2board.client_ua_whitelist', ''));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && hash_equals($line, $ua)) {
                return true;
            }
        }
        return false;
    }

    //过滤 UA 白名单
    private function checkUA($UA): bool
    {
        $UA = strtolower($UA);
        $allowedFlags = ['clash', 'clashforandroid', 'meta', 'shadowrocket', 'sing-box', 'SFA', 'clashforwindows', 'clash-verge', 'hiddify', 'loon',  'quantumult', 'sagerNet', 'surge', 'v2ray', 'passwall', 'ssrplus', 'shadowsocks', 'netch', 'nyanpasu', 'streisand'];

        foreach ($allowedFlags as $allowedFlag) {
            if (strpos($UA, $allowedFlag) !== false) {
                return true;
            }
        }

        return false;
    }


    private function checkTokenRequest($userID, $userIP, $userISPInfo): bool
    {
        $hourAgo = time() - 6 * 3600; // 6小时前的时间
        $tokenRequest = Tokenrequest::firstOrCreate(
            ['user_id' => strval($userID), 'ip' => strval($userIP)],
            ['requested_at' => time(), 'location' => $userISPInfo]
        );

        $requests = Tokenrequest::where('user_id', $userID)
            ->where('requested_at', '>', $hourAgo)
            ->distinct('ip')
            ->count('ip');

        if ($requests >= 12) {
            // 禁止该Token请求
            // 可以在这里记录禁止请求的日志或执行其他逻辑

            return false;
        }

        return true;
    }

    // 第三方客户端白名单：后台英文逗号分隔的关键词，UA 包含任一关键词即命中（不区分大小写）
    private function matchThirdPartyUA(string $ua): bool
    {
        $ua = strtolower($ua);
        if ($ua === '') {
            return false;
        }
        $keywords = explode(',', (string)config('v2board.client_ua_third_party', 'shadowrocket'));
        foreach ($keywords as $keyword) {
            $keyword = strtolower(trim($keyword));
            if ($keyword !== '' && strpos($ua, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    // 小火箭 UA 形如 "Shadowrocket/3488 CFNetwork/..."，取 build 号；取不到返回 0（视为最低）
    private function extractShadowrocketBuild(string $ua): int
    {
        return preg_match('/Shadowrocket\/(\d+)/i', $ua, $matches) ? (int)$matches[1] : 0;
    }

    private function supportRalityAndHisteria2($flag) {
        $keywords = ['verge', 'meta', 'nyanpasu', 'hiddify', 'sing', 'passwall', 'shadowrocket', 'streisand'];
        foreach ($keywords as $keyword) {
            if (strpos($flag, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

}
