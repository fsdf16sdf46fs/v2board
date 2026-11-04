<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TelegramController extends Controller
{
    const BIND_CODE_TTL = 600;
    // 去掉 0/O/1/I 等易混字符；8 位 = 2^40 种组合，10 分钟内无法穷举
    const BIND_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function getBotInfo()
    {
        $telegramService = new TelegramService();
        $response = $telegramService->getMe();
        return response([
            'data' => [
                'username' => $response->result->username
            ]
        ]);
    }

    // 一次性绑定码，替代原来的 "/bind 订阅链接"，避免在网页上展示订阅链接
    public function getBindCode(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($user->telegram_id) {
            abort(500, '该账号已经绑定了Telegram账号');
        }

        // 每人同时只保留一个有效码
        $userKey = CacheKey::get('TELEGRAM_BIND_CODE_USER', $user->id);
        $oldCode = Cache::get($userKey);
        if ($oldCode) {
            Cache::forget(CacheKey::get('TELEGRAM_BIND_CODE', $oldCode));
        }

        $code = '';
        $max = strlen(self::BIND_CODE_ALPHABET) - 1;
        for ($i = 0; $i < 8; $i++) {
            $code .= self::BIND_CODE_ALPHABET[random_int(0, $max)];
        }
        Cache::put(CacheKey::get('TELEGRAM_BIND_CODE', $code), $user->id, self::BIND_CODE_TTL);
        Cache::put($userKey, $code, self::BIND_CODE_TTL);

        return response([
            'data' => [
                'code' => $code,
                'expires_in' => self::BIND_CODE_TTL
            ]
        ]);
    }

    public function unbind(Request $request)
    {
        $user = User::where('user_id', $request->user['id'])->first();
    }
}
