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
    // 旧码剩余不足这么多秒就换新码，避免把一个马上过期的码展示给用户
    const BIND_CODE_REUSE_MIN_TTL = 60;
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

        // 每人同时只保留一个有效码；有效期内重复请求返回同一个码，不重新生成
        $userKey = CacheKey::get('TELEGRAM_BIND_CODE_USER', $user->id);
        $old = Cache::get($userKey);
        // 兼容旧格式：之前这里直接存的是码字符串
        $oldCode = is_array($old) ? $old['code'] : $old;
        if (is_array($old)) {
            $remaining = $old['expires_at'] - time();
            if ($remaining > self::BIND_CODE_REUSE_MIN_TTL
                && (int)Cache::get(CacheKey::get('TELEGRAM_BIND_CODE', $oldCode)) === (int)$user->id) {
                return response([
                    'data' => [
                        'code' => $oldCode,
                        'expires_in' => $remaining
                    ]
                ]);
            }
        }
        if ($oldCode) {
            Cache::forget(CacheKey::get('TELEGRAM_BIND_CODE', $oldCode));
        }

        $code = '';
        $max = strlen(self::BIND_CODE_ALPHABET) - 1;
        for ($i = 0; $i < 8; $i++) {
            $code .= self::BIND_CODE_ALPHABET[random_int(0, $max)];
        }
        Cache::put(CacheKey::get('TELEGRAM_BIND_CODE', $code), $user->id, self::BIND_CODE_TTL);
        Cache::put($userKey, [
            'code' => $code,
            'expires_at' => time() + self::BIND_CODE_TTL
        ], self::BIND_CODE_TTL);

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
