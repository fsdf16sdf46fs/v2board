<?php

namespace App\Console;

use App\Models\Tokenrequest;
use App\Services\UserService;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // v2board
        $schedule->command('v2board:statistics')->dailyAt('0:10');
        // check
        $schedule->command('check:order')->everyMinute();

        //自动续费
        $schedule->command('check:autoRenew')->everyMinute();

        $schedule->command('check:commission')->everyMinute();
        $schedule->command('check:ticket')->everyMinute();
        // reset
        $schedule->command('reset:traffic')->daily();
        $schedule->command('reset:log')->daily();
        // send
        $schedule->command('send:remindMail')->dailyAt('11:30');
        $schedule->command('send:remindMail3')->cron('40 11 */3 * *');
        $schedule->command('send:remindMail7')->cron('50 11 */7 * *');
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes();

        // custom function
        $schedule->command('customFunction:replenish 2')->dailyAt('0:0'); //一次性套餐补货 2 个
        $schedule->command('clear:inviteCode')->dailyAt('2:25'); //删除邀请码
        //$schedule->command('changePort:vmess 1')->dailyAt('2:30'); //VMess节点ID 1 更换端口
        //$schedule->command('customFunction:addCoupon 108 3')->dailyAt('12:00'); // ID 108 优惠券补充 1-5 张
        $schedule->command('customFunction:tongjicaibao')->dailyAt('0:11'); //推送前一天的财报给管理员
        $schedule->command('customFunction:kick 7')->dailyAt('23:45'); //移除群组中过期超过 7 天的用户
        $schedule->command('customFunction:gerUserCommission')->dailyAt('0:05'); //群通知：统计佣金
        $schedule->command('customFunction:sendTrafficStatisticsToGroup')->dailyAt('0:13'); //推送流量排行到用户群
        $schedule->command('ipdb:download')->weeklyOn(1, '03:00');// 每周一 03:00 执行下载任务
        $schedule->command('ipdb:campus')->weeklyOn(1, '03:10');// 每周一 03:10 更新校园网 IP 段（订阅拦截用）
        $schedule->command('emby:check-expiration')->dailyAt('03:00'); //检查 emby 过期，则删除用户
        $schedule->command('emby:check-availability')->everyTenMinutes(); //检查 emby 上游 API 有效性

        //delete user token request more than 3 days ago
        $schedule->call(function () {
            $hourAgo = time() - 86400 * 3; // 3天前前的时间
            TokenRequest::where('requested_at', '<', $hourAgo)->delete();
        })->everyFiveMinutes();

        // 每5分钟检查一次是否有待处理的登录信息
        $schedule->call(function () {
            $key = CacheKey::get('LOGIN_UPDATES','TIME&IP');
            $currentBatch = Cache::get($key, []);
            if (!empty($currentBatch)) {
                $userService = new UserService();
                $userService->updateLoginRecords($currentBatch);
                Cache::forget($key);
            }
        })->everyFiveMinutes();

        // 添加：每日检查并发送 Emby 到期提醒邮件 (默认提前3天)
        $schedule->call(function () {
            // 实例化 MailService
            $mailService = app(\App\Services\MailService::class);
            // 只查询 emby_expired_at 不为空且未过期的用户，并分块处理
            \App\Models\User::whereNotNull('emby_expired_at')
                ->where('emby_expired_at', '>', time())
                ->chunkById(200, function ($users) use ($mailService) { // 使用 chunkById 提高效率
                    foreach ($users as $user) {
                        try {
                            $mailService->remindEmbyExpire($user);
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error("发送 Emby 到期提醒邮件失败 (用户ID: {$user->id}): " . $e->getMessage());
                        }
                    }
                });
            \Illuminate\Support\Facades\Log::info('每日 Emby 到期提醒邮件检查任务执行完毕。');
        })->dailyAt('10:00'); // 设置合适的执行时间，例如每天早上10点



    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
