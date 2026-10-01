<div style="background: #eee; padding: 20px 0;">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0" style="background:#fff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
        <tbody>
        <tr>
            <td>
                {{-- Logo --}}
                <div style="padding: 25px 20px; text-align: center; border-bottom: 1px solid #eee;">
                    <h1 style="margin: 0; font-size: 28px; color: #333;">{{ $name }}</h1>
                </div>

                {{-- 主体内容 --}}
                <div style="padding: 30px 40px;">
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tbody>
                        <tr>
                            <td style="font-size:24px; line-height:1.5; color:#000; padding-bottom: 20px; font-weight: bold;">
                                您的 Emby 账号已开通
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:15px; color:#333; line-height: 1.8;">
                                尊敬的用户 <strong>{{$userName}}</strong>，
                                <br />
                                <br />
                                感谢您的购买！您的 Emby 媒体库账号已成功开通，以下是您的账号信息：
                                <br />
                                <br />
                                {{-- 使用一个稍微突出显示的区域来展示账号信息 --}}
                                <div style="background-color: #f8f8f8; padding: 15px 20px; border-radius: 5px; border: 1px solid #eee; margin: 15px 0;">
                                    <p style="margin: 5px 0; font-size: 15px;"><strong>服务器地址:</strong> {{ $emby_server_url }}</p>
                                    <p style="margin: 5px 0; font-size: 15px;"><strong>登录用户名:</strong> {{ $emby_username }}</p>
                                    <p style="margin: 5px 0; font-size: 15px;"><strong>登录密码:</strong> {{ $emby_password }}</p>
                                    <p style="margin: 5px 0; font-size: 15px;"><strong>到期时间:</strong> {{ $emby_expire_time }}</p>
                                </div>
                                <br />
                                请妥善保管您的账号信息。请您在 emby 客户端中进行登录。
                                <br />
                                <br />
                                如果您有任何疑问或者需要帮助，欢迎通过网站工单或邮件联系我们。
                                <br />
                                <br />
                                -----------------
                                <br />
                                <br />
                                {{$name}} 团队敬上<br />祝您观影愉快！
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                {{-- 页脚 --}}
                <div style="background:#f7f7f7; padding: 20px 40px; border-top: 1px solid #eee;">
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tbody>
                        <tr>
                            <td style="text-align: center;">
                                <a href="{{$url}}" style="font-size:14px; color:#929292; text-decoration: none;">访问 {{$name}} 官网</a>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
        </tbody>
    </table>
</div>
