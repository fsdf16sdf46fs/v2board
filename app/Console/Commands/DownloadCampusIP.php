<?php

namespace App\Console\Commands;

use App\Utils\CampusNetwork;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DownloadCampusIP extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ipdb:campus';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新校园网 IP 段（教育网 CERNET + 科技网 CSTNET，IPv4/IPv6），订阅时用于识别校园网';

    // gaoyifan/china-operator-ip：基于 BGP 路由表按 ASN 归类，每日更新
    const MIRRORS = [
        'https://raw.githubusercontent.com/gaoyifan/china-operator-ip/ip-lists/',
        'https://cdn.jsdelivr.net/gh/gaoyifan/china-operator-ip@ip-lists/',
    ];

    const FILES = ['cernet.txt', 'cernet6.txt', 'cstnet.txt', 'cstnet6.txt'];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $cidrs = [];
        foreach (self::FILES as $file) {
            $lines = $this->download($file);
            if (empty($lines)) {
                $this->error("{$file} 下载失败或为空，保留现有数据");
                return 1;
            }
            foreach ($lines as $line) {
                $cidr = CampusNetwork::parseCidr($line);
                // 前缀过短说明上游数据异常（如 0.0.0.0/0），写进去会把所有用户都当成校园网
                if ($cidr === null || $cidr[1] < (strlen($cidr[0]) === 4 ? 8 : 16)) {
                    $this->error("{$file} 含异常条目 \"{$line}\"，保留现有数据");
                    return 1;
                }
                $cidrs[] = trim($line);
            }
        }

        $path = storage_path('app/campus.txt');
        $content = "# 校园网 IP 段：教育网 CERNET/CERNET2 + 科技网 CSTNET（IPv4 + IPv6）\n"
            . "# 来源 https://github.com/gaoyifan/china-operator-ip ip-lists 分支\n"
            . '# 由 php artisan ipdb:campus 生成于 ' . date('Y-m-d H:i:s') . "\n"
            . implode("\n", $cidrs) . "\n";
        // 先写临时文件再 rename，订阅请求不会读到写了一半的文件
        if (file_put_contents($path . '.tmp', $content) === false || !rename($path . '.tmp', $path)) {
            $this->error("写入 {$path} 失败");
            return 1;
        }

        $this->info('校园网 IP 段已更新：' . count($cidrs) . ' 条');
        return 0;
    }

    private function download(string $file): array
    {
        foreach (self::MIRRORS as $mirror) {
            try {
                $response = Http::timeout(30)->get($mirror . $file);
                if ($response->successful()) {
                    return preg_split('/\R/', trim($response->body()), -1, PREG_SPLIT_NO_EMPTY);
                }
                $this->warn("{$mirror}{$file} HTTP {$response->status()}");
            } catch (\Exception $e) {
                $this->warn("{$mirror}{$file} {$e->getMessage()}");
            }
        }
        return [];
    }
}
