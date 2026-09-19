<x-mail-base :message="$message ?? null" :subject-line="$subjectLine" :preheader="$lines[0] ?? $subjectLine">
    <h1 style="margin:0 0 6px;font-size:20px;line-height:28px;font-weight:800;color:#18181b;letter-spacing:-0.2px;">{{ $subjectLine }}</h1>
    <p style="margin:0 0 18px;font-size:14px;line-height:22px;color:#52525b;">Merhaba{{ $recipientName ? ' '.$recipientName : '' }},</p>
    @foreach($lines as $line)
        <p style="margin:0 0 12px;font-size:14px;line-height:22px;color:#3f3f46;">{{ $line }}</p>
    @endforeach
    @if($actionUrl)
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 8px;">
            <tr>
                <td class="btn" style="background:#f97316;border-radius:12px;">
                    <a href="{{ $actionUrl }}" style="display:inline-block;padding:13px 26px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;">{{ $actionText ?? 'Panele git' }}</a>
                </td>
            </tr>
        </table>
        <p style="margin:0 0 8px;font-size:11px;line-height:16px;color:#a1a1aa;">Düğme çalışmazsa bu adresi tarayıcınıza yapıştırın:<br><a href="{{ $actionUrl }}" style="color:#a1a1aa;word-break:break-all;">{{ $actionUrl }}</a></p>
    @endif
</x-mail-base>
