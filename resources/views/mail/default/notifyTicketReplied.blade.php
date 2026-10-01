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
                            <td style="font-size:24px;line-height:1.5;color:#000;">工单已更新</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px">
                                尊敬的用户 {{$userName}} ，
                                <br />
                                <br />
                                {!! nl2br(e($content)) !!}
                                <br />
                                <br />
                                {{$name}} 团队敬上 祝您生活愉快
                                <br />
                                <br />
                                ----------------------------------
                                <br />
                                <br />
                                工单ID： {{$ticketID}}
                                <br />
                                <br />
                                主题：{{$subject}}
                                <br />
                                <br />
                                状态：已回复
                                <br />
                                <br />
                                ----------------------------------
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
