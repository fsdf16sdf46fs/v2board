<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;

// 自研客户端（V2Pass）检查更新：返回后台「系统配置 → APP」里各平台的版本号和下载地址。
// 只挂在需要登录的 user 路由下；版本号和下载地址不要放到 guest 或只凭订阅 token
// 就能访问的 client 接口里。客户端按 <平台>_version / <平台>_download_url 读取，
// 下载地址为空时不提示更新。
class AppController extends Controller
{
    public function getVersion()
    {
        return response([
            'data' => [
                'windows_version' => config('v2board.windows_version'),
                'windows_download_url' => config('v2board.windows_download_url'),
                'macos_version' => config('v2board.macos_version'),
                'macos_download_url' => config('v2board.macos_download_url'),
                'android_version' => config('v2board.android_version'),
                'android_download_url' => config('v2board.android_download_url')
            ]
        ]);
    }
}
