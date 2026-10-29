<?php

namespace App\Utils;

/**
 * 校园网 IP 识别：教育网 CERNET / CERNET2、科技网 CSTNET（IPv4 + IPv6）
 *
 * 数据由 `php artisan ipdb:campus` 每周更新到 storage/app/campus.txt，
 * 该文件不存在时使用仓库自带的 resources/ipdata/campus.txt。
 */
class CampusNetwork
{
    private static $cidrs;

    public static function contains($ip): bool
    {
        $ip = @inet_pton((string)$ip);
        if ($ip === false) {
            return false;
        }
        // IPv4-mapped IPv6（::ffff:a.b.c.d）按 IPv4 匹配
        if (strlen($ip) === 16 && strncmp($ip, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            $ip = substr($ip, 12);
        }

        foreach (self::cidrs() as $cidr) {
            if (self::inCidr($ip, $cidr[0], $cidr[1])) {
                return true;
            }
        }
        return false;
    }

    // 网段只解析一次，本次请求内复用（alivelist 一次要判断所有在线用户的所有 IP）
    private static function cidrs(): array
    {
        if (self::$cidrs === null) {
            self::$cidrs = [];
            foreach (self::lines() as $line) {
                $cidr = self::parseCidr($line);
                if ($cidr !== null) {
                    self::$cidrs[] = $cidr;
                }
            }
        }
        return self::$cidrs;
    }

    /**
     * 解析 CIDR，返回 [二进制网络地址, 前缀长度]；非法行（含注释、空行）返回 null
     */
    public static function parseCidr($cidr): ?array
    {
        $parts = explode('/', trim($cidr));
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return null;
        }
        $net = @inet_pton($parts[0]);
        $bits = (int)$parts[1];
        if ($net === false || $bits > strlen($net) * 8) {
            return null;
        }
        return [$net, $bits];
    }

    public static function dataPath(): string
    {
        $path = storage_path('app/campus.txt');
        return is_file($path) ? $path : resource_path('ipdata/campus.txt');
    }

    private static function lines(): array
    {
        $lines = @file(self::dataPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $lines === false ? [] : $lines;
    }

    private static function inCidr(string $ip, string $net, int $bits): bool
    {
        if (strlen($ip) !== strlen($net)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (strncmp($ip, $net, $bytes) !== 0) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ip[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
