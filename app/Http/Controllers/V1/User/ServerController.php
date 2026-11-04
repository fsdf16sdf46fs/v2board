<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServerController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user['id']);
        $servers = [];
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
        }
        $eTag = sha1(json_encode(array_column($servers, 'cache_key')));
        if (strpos($request->header('If-None-Match'), $eTag) !== false ) {
            abort(304);
        }

        // 只给名字和在线状态：完整行里有 host/port/REALITY 公钥/server_key 等，
        // 配合用户 uuid 就能绕过订阅接口手搓出全部节点
        $servers = array_map(function ($server) {
            return [
                'name' => $server['name'],
                'is_online' => $server['is_online'],
            ];
        }, $servers);

        return response([
            'data' => $servers
        ])->header('ETag', "\"{$eTag}\"");
    }
}
