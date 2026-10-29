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
                            <td style="font-size:24px;line-height:1.5;color:#000;">{{ $until ? '账号暂停使用通知' : '账号停用通知' }}</td>
                        </tr>
                        <tr>
                            <td style="font-size:14px;color:#333;padding:24px 40px 0 40px">
                                尊敬的用户 {{$userName}} ：
                                <br />
                                <br />
                                @if ($until)
                                系统检测到您正在通过校园网/科技网连接节点（IP：{{$ip}}），这是第 {{$count}} 次。为了您的安全，账号已暂停使用 {{$duration}}，将于 {{$until}} 自动恢复，无需任何操作。
                                <br />
                                <br />
                                为什么不建议使用校园网等公共网络：
                                <br />
                                · 校园网的流量都经过学校的统一出口，网络管理员能看到您的连接记录。使用代理很容易被识别，可能会被约谈、断网，甚至受到处分。
                                <br />
                                · 图书馆、地铁、商场等公共 Wi-Fi 也有同样的风险，还可能被他人监听，或者被伪造的热点窃取信息。
                                <br />
                                <br />
                                请断开校园网等公共网络，改用手机流量、个人热点或家庭宽带。
                                <br />
                                <br />
                                温馨提示：{{$rules}}
                                @else
                                系统第 {{$count}} 次检测到您通过校园网/科技网连接节点（IP：{{$ip}}）。按照规则，您的账号已被永久停用。如有疑问，请发邮件联系客服。
                                @endif
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
