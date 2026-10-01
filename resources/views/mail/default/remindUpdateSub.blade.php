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
                            <td style="font-size:24px;line-height:1.5;color:#000;">套餐开通成功</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px">
                                尊敬的用户 {{$userName}} ，
                                <br />
                                <br />
                                您的服务已经开通，感谢您对 {{$name}} 一如既往的支持。您的服务如下：
                                <br />
                                <br />
                                ###############
                                <br />
                                <br />
                                套餐：{{$planName}}
                                <br />
                                <br />
                                流量：{{$traffic}} GB（若是随用随付套餐，无需理会可用流量的值，您只需保持余额充足即可）
                                <br />
                                <br />
                                到期时间：{{$expiredTime}}
                                <br />
                                <br />
                                ###############
                                <br />
                                <br />
                                为保证服务质量，请您按照官网【使用文档】-【手动更新丁阅方法汇总】的指示，定期更新丁阅。如果您有任何疑问或者需要帮助，欢迎发送工单或邮件联系我们。
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
