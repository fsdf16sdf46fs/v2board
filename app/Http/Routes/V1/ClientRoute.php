<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ClientRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'client',
            'middleware' => 'client'
        ], function ($router) {
            // 处理可选的 sub_path（去掉首尾 / 以免出现双斜杠）
            $subPath      = trim(config('v2board.sub_path', ''), '/');
            $subscribeUri = $subPath === '' ? 'subscribe' : "subscribe/{$subPath}";
            // Client
            $router->get($subscribeUri, 'V1\\Client\\ClientController@subscribe')
                ->name('client.subscribe');   // <— 追加这一句
            // 旧 Tidalab 客户端的 /app/getConfig、/app/getVersion 已删除：getConfig 只凭订阅
            // token 就下发全部节点，绕过了订阅接口的 UA、封禁、IP 数和校园网检查。
            // 自研客户端检查更新走 user/app/getVersion（需要登录）。
        });
    }
}
