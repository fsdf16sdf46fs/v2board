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
                            <td style="font-size:24px;line-height:1.5;color:#000;">邮箱验证码</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px">
                                尊敬的用户 {{$userName}} ，
                                <br />
                                <br />
                                您正在尝试验证邮箱，请使用以下验证码完成验证：
                                <div style="background-color: #46a2a0; color: white; text-align: center; font-size: 24px; padding: 10px; margin: 20px auto; width: 200px; border-radius: 5px;">
                                    {{$code}}
                                </div>
                                请在 5 分钟内使用该验证码，以确保账户安全。如果您未尝试此操作，请忽略此邮件。
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
                            <td style="padding:20px 40px;font-size:12px;color:#999;line-height:20px;background:#f7f7f7"><a href="{{$url}}" style="font-size:14px;color:#929292">返回{{$name}}</a></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
        </tbody>
    </table>
</div>
