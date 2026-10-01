<div style="background: #eee">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0">
        <tbody>
        <tr>
            <td>
                <div style="background:#fff">
                    <div style="padding: 20px; text-align: center;">
                        <h1 style="margin: 0; font-size: 28px; color: #333;">{{ config('v2board.app_name', 'V2Board') }}</h1>
                    </div>
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tbody>
                        <tr style="padding:20px 40px 0 40px;display:table-cell">
                            <td style="font-size:24px;line-height:1.5;color:#000;">登录通知</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px">
                                尊敬的用户 {{$userName}} ，
                                <br />
                                <br />
                                您正在登入 {{$name}}，如果不是您本人登录，则您的账号有可能被盗，请及时修改密码。
                                <br />
                                <br />
                                本次登录信息：
                                <br />
                                登录时间：{{$datetime}}
                                <br />
                                IP：{{$ipInfo}} {{$ip}}
                                <br />
                                设备：{{$device}}
                                <br />
                                操作系统：{{$platform}} / {{$platformVersion}}
                                <br />
                                浏览器：{{$platform}} / {{$browserVersion}}
                                <br />
                                <br />
                                如果您有任何疑问或者需要帮助，欢迎发送工单或邮件联系我们。
                                <br />
                                <br />
                                -----------------
                                <br />
                                <br />
                                {{$name}} 团队敬上 祝您生活愉快
                            </td>
                        </tr>
                        <tr style="padding:40px;display:table-cell">
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tbody>
                        <tr>
                            <td style="padding:20px 40px;font-size:12px;color:#999;line-height:20px;background:#f7f7f7"><a href="{{$url}}" style="font-size:14px;color:#929292">点我返回最新{{$name}}官网</a></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
        </tbody>
    </table>
</div>
