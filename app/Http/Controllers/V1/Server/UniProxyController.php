<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\CampusNetwork;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class UniProxyController extends Controller
{
    private $nodeType;
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    public function __construct(Request $request)
    {
        $token = $request->input('token');
        if (empty($token)) {
            abort(500, 'token is null');
        }
        if ($token !== config('v2board.server_token')) {
            abort(500, 'token is error');
        }
        $this->nodeType = $request->input('node_type');
        if ($this->nodeType === 'v2ray') $this->nodeType = 'vmess';
        $this->nodeId = $request->input('node_id');
        $this->serverService = new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, $this->nodeType);
        if (!$this->nodeInfo) abort(500, 'server is not exist');
    }

    // 后端获取用户
    public function user(Request $request)
    {
        ini_set('memory_limit', -1);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);
        $users = $this->serverService->getAvailableUsers($this->nodeInfo->group_id);
        $users = $users->toArray();

        $response['users'] = $users;

        $eTag = sha1(json_encode($response));
        if (strpos($request->header('If-None-Match'), $eTag) !== false ) {
            abort(304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    // 后端提交数据
    public function push(Request $request)
    {
        $data = file_get_contents('php://input');
        $data = json_decode($data, true);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_ONLINE_USER', $this->nodeInfo->id), count($data), 3600);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_PUSH_AT', $this->nodeInfo->id), time(), 3600);
        $userService = new UserService();
        $userService->trafficFetch($this->nodeInfo->toArray(), $this->nodeType, $data);

        return response([
            'data' => true
        ]);
    }

    // 后端获取配置
    public function config(Request $request)
    {
        switch ($this->nodeType) {
            case 'shadowsocks':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'cipher' => $this->nodeInfo->cipher,
                    'obfs' => $this->nodeInfo->obfs,
                    'obfs_settings' => $this->nodeInfo->obfs_settings
                ];

                if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
                }
                if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
                }
                break;
            case 'vmess':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'network' => $this->nodeInfo->network,
                    'network_settings' => $this->nodeInfo->network_settings,
                    'tls' => $this->nodeInfo->tls,
                    'tls_settings' => $this->nodeInfo->tls_sttings
                ];
                break;
            case "vless":
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'network' => $this->nodeInfo->network,
                    'network_settings' => $this->nodeInfo->network_settings,
                    'flow' => $this->nodeInfo->flow,
                    'tls' => $this->nodeInfo->tls,
                    'tls_settings' => $this->nodeInfo->tls_settings
                ];
                break;
            case 'trojan':
                $response = [
                    'host' => $this->nodeInfo->host,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                ];
                break;
            case 'hysteria':
                $response = [
                    'host' => $this->nodeInfo->host,
                    'server_port' => $this->nodeInfo->server_port,
                    'port' => $this->nodeInfo->port,
                    'server_name' => $this->nodeInfo->server_name,
                    'up_mbps' => $this->nodeInfo->up_mbps,
                    'down_mbps' => $this->nodeInfo->down_mbps,
                    'obfs' => Helper::getServerKey($this->nodeInfo->created_at, 16),
                    'obfs_type' => $this->nodeInfo->obfs_type,
                    'ignore_client_bandwidth' => !!$this->nodeInfo->ignore_client_bandwidth
                ];
                break;
        }
        $response['base_config'] = [
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60)
        ];
        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }
        $eTag = sha1(json_encode($response));
        if (strpos($request->header('If-None-Match'), $eTag) !== false ) {
            abort(304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    // 后端上报每节点的活跃 IP 并获取集群合并后的 IP 列表（用于跨机 IP 数量限制去重）
    // 请求体: [{"Uid": 123, "Ips": ["1.2.3.4", ...]}, ...]
    // 响应体: 相同形状，但 Ips 是集群所有节点对该 user 的并集
    public function alivelist(Request $request)
    {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }

        $nodeRef = strtolower($this->nodeType) . '_' . $this->nodeId;
        // TTL 至少 120s，也可覆盖 pull_interval 的 3 倍，容忍节点短暂下线
        $pullInterval = (int)config('v2board.server_pull_interval', 60);
        $ttl = max(120, $pullInterval * 3);

        $result = [];
        $userService = new UserService();
        $campusBanEnable = (int)config('v2board.campus_ban_enable', 1);

        foreach ($data as $entry) {
            $uid = (int)($entry['Uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $ips = $entry['Ips'] ?? [];
            if (!is_array($ips)) {
                $ips = [];
            }

            // 用校园网 IP 连接节点：封禁该用户。封禁中的用户这次上报不写入也不返回，
            // 避免旧的校园网 IP 留在 Redis 里，解封后经集群合并回到节点、又被记一次
            $campusIp = $campusBanEnable ? $this->findCampusIp($ips) : null;
            if ($campusIp !== null && $userService->banCampusUser($uid, $campusIp, $nodeRef)) {
                continue;
            }

            // Key 命名走 CacheKey 中心化注册；Value 层面仍用 Redis 原生集合操作，
            // 因为多节点并发上报时需要 SADD/SMEMBERS 的原子语义（Cache::put
            // 只能单键原子读写，做集合合并会有丢数据的竞态）。
            $nodeKey = CacheKey::get('ALIVE_IP_USER_NODE', "{$uid}_{$nodeRef}");
            $nodesKey = CacheKey::get('ALIVE_IP_USER_NODES', $uid);

            // 1) 替换本节点对该用户的 IP 报告（旧的先删，避免污染）
            Redis::del($nodeKey);
            if (count($ips) > 0) {
                Redis::sadd($nodeKey, ...$ips);
                Redis::expire($nodeKey, $ttl);
            }

            // 2) 把本节点登记到该用户的"节点集合"里，供其他节点读取时发现
            Redis::sadd($nodesKey, $nodeRef);
            Redis::expire($nodesKey, $ttl);

            // 3) 聚合：遍历该用户登记过的所有节点，union 未过期的 IP 集合
            $allNodes = Redis::smembers($nodesKey);
            $merged = [];
            foreach ($allNodes as $otherNodeRef) {
                $otherKey = CacheKey::get('ALIVE_IP_USER_NODE', "{$uid}_{$otherNodeRef}");
                if (Redis::exists($otherKey)) {
                    $nodeIps = Redis::smembers($otherKey);
                    if (is_array($nodeIps) && count($nodeIps) > 0) {
                        $merged = array_merge($merged, $nodeIps);
                    }
                } else {
                    // 节点的 IP 集合已过期 → 从用户的 nodes 集合里剔除，避免无限增长
                    Redis::srem($nodesKey, $otherNodeRef);
                }
            }
            $merged = array_values(array_unique($merged));

            $result[] = [
                'Uid' => $uid,
                'Ips' => $merged,
            ];
        }

        return response()->json($result);
    }

    private function findCampusIp(array $ips): ?string
    {
        foreach ($ips as $ip) {
            if (CampusNetwork::contains($ip)) {
                return (string)$ip;
            }
        }
        return null;
    }
}
