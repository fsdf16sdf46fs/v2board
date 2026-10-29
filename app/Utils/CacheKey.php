<?php

namespace App\Utils;

class CacheKey
{
    CONST KEYS = [
        'EMAIL_VERIFY_CODE' => '邮箱验证码',
        'LAST_SEND_EMAIL_VERIFY_TIMESTAMP' => '最后一次发送邮箱验证码时间',
        'SERVER_VMESS_ONLINE_USER' => '节点在线用户',
        'SERVER_VMESS_LAST_CHECK_AT' => '节点最后检查时间',
        'SERVER_VMESS_LAST_PUSH_AT' => '节点最后推送时间',
        'SERVER_TROJAN_ONLINE_USER' => 'trojan节点在线用户',
        'SERVER_TROJAN_LAST_CHECK_AT' => 'trojan节点最后检查时间',
        'SERVER_TROJAN_LAST_PUSH_AT' => 'trojan节点最后推送时间',
        'SERVER_SHADOWSOCKS_ONLINE_USER' => 'ss节点在线用户',
        'SERVER_SHADOWSOCKS_LAST_CHECK_AT' => 'ss节点最后检查时间',
        'SERVER_SHADOWSOCKS_LAST_PUSH_AT' => 'ss节点最后推送时间',
        'SERVER_HYSTERIA_ONLINE_USER' => 'hysteria节点在线用户',
        'SERVER_HYSTERIA_LAST_CHECK_AT' => 'hysteria节点最后检查时间',
        'SERVER_HYSTERIA_LAST_PUSH_AT' => 'hysteria节点最后推送时间',
        'SERVER_VLESS_ONLINE_USER' => 'vless节点在线用户',
        'SERVER_VLESS_LAST_CHECK_AT' => 'vless节点最后检查时间',
        'SERVER_VLESS_LAST_PUSH_AT' => 'vless节点最后推送时间',
        'TEMP_TOKEN' => '临时令牌',
        'LAST_SEND_EMAIL_REMIND_TRAFFIC' => '最后发送流量邮件提醒',
        'SCHEDULE_LAST_CHECK_AT' => '计划任务最后检查时间',
        'REGISTER_IP_RATE_LIMIT' => '注册频率限制',
        'LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP' => '最后一次发送登入链接时间',
        'PASSWORD_ERROR_LIMIT' => '密码错误次数限制',
        'USER_SESSIONS' => '用户session',
        'FORGET_REQUEST_LIMIT' => '找回密码次数限制',
        'Domain_IP' => '域名对应的IP',
        'PLAN' => '套餐信息',
        'LOGIN_UPDATES' => '登录信息更新',
        'BLACKLISTED_SESSIONS' => '黑名单会话',
        'USD_TO_CNY_RATE' => '美元兑人民币汇率',
        'IP_SENTINEL_BLACKLIST' => 'IP哨兵黑名单缓存',
        'ALIVE_IP_USER_NODE' => '某节点对某用户报告的活跃IP集合（跨机IP去重）',
        'ALIVE_IP_USER_NODES' => '某用户当前被哪些节点报告过活跃IP（跨机IP去重索引）',
        'USER_RECENT_SERVER_TRAFFIC' => '某用户在某10分钟时间段内各节点的流量（后台展示近期节点）',
        'EMAIL_VERIFY_IP_RATE_LIMIT' => '邮箱验证码-按IP冷却（10秒）',
        'EMAIL_VERIFY_IP_DAILY_COUNT' => '邮箱验证码-按IP每日上限计数',
        'PV_DAILY_LIMIT_IP' => '邀请码PV-按IP每日上限计数',
    ];

    public static function get(string $key, $uniqueValue)
    {
        if (!in_array($key, array_keys(self::KEYS))) {
            abort(500, 'key is not in cache key list');
        }
        return $key . '_' . $uniqueValue;
    }
}
