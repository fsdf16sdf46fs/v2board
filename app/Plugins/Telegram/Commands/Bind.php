<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class Bind extends Telegram {
    public $command = '/bind';
    public $description = '将Telegram账号绑定到网站';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        if (!isset($message->args[0])) {
            abort(500, '参数有误，请发送 /bind 绑定码（在官网 → 个人中心 → Telegram 绑定 获取）');
        }
        // 只认网页生成的一次性绑定码（见 V1\User\TelegramController@getBindCode），不再接受订阅链接
        $code = strtoupper(trim($message->args[0]));
        if (!preg_match('/^[A-Z0-9]{8}$/', $code)) {
            abort(500, '绑定码格式不正确，请在官网个人中心重新获取');
        }
        $codeKey = CacheKey::get('TELEGRAM_BIND_CODE', $code);
        $userId = Cache::get($codeKey);
        if (!$userId) {
            abort(500, '绑定码无效或已过期，请在官网个人中心重新获取');
        }
        $user = User::find($userId);
        if (!$user) {
            abort(500, '用户不存在');
        }
        if ($user->telegram_id) {
            abort(500, '该账号已经绑定了Telegram账号');
        }
        $user->telegram_id = $message->chat_id;

        // 记录用户的 telegram ID 以及 @用户名
        if(!$user->is_admin){
            $newRemarks = "Telegram ID: {$message->chat_id}";
            if (isset($message->get_username)) {
                $newRemarks .= ", 用户名: @{$message->get_username}";
            }
            $user->remarks = $user->remarks ? $user->remarks . " | " . $newRemarks : $newRemarks;
        }

        if (!$user->save()) {
            abort(500, '设置失败');
        }
        Cache::forget($codeKey);
        Cache::forget(CacheKey::get('TELEGRAM_BIND_CODE_USER', $user->id));

        $telegramService = $this->telegramService;
        $telegramService->sendMessage($message->chat_id, '绑定成功');
    }
}
