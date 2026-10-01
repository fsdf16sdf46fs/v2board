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
                            <td style="font-size:24px;line-height:1.5;color:#000;">工单已建立</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px">
                                尊敬的用户 {{$userName}} ，
                                <br />
                                <br />
                                感谢您通过工单联系我们。您的工单请求我们已经收到。您的工单详情如下：
                                <br />
                                <br />
                                主题：{{$subject}}
                                <br />
                                <br />
                                优先级别：{{$level}}
                                <br />
                                <br />
                                状态：已建立
                                <br />
                                <br />
                                {!! nl2br(e($content)) !!}
                                <br />
                                <br />
                                -------------------------------------------------------------
                                <br />
                                <br />
                                我们会及时通过工单回复您的请求。请勿回复本邮件。 {{$name}} 团队敬上 祝您生活愉快
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
