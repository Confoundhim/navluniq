<x-mail-base subject-line="Doğrulama kodunuz" :preheader="'Doğrulama kodunuz: '.$otpCode.' (5 dakika geçerli)'">
    <h1 style="margin:0 0 6px;font-size:20px;line-height:28px;font-weight:800;color:#18181b;">Doğrulama kodunuz</h1>
    <p style="margin:0 0 18px;font-size:14px;line-height:22px;color:#52525b;">Merhaba{{ $recipientName ? ' '.$recipientName : '' }},</p>
    <p style="margin:0 0 12px;font-size:14px;line-height:22px;color:#3f3f46;">{{ $intro }}</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;">
        <tr>
            <td align="center" class="otp" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:16px;padding:22px 12px;font-size:34px;line-height:40px;font-weight:800;letter-spacing:10px;color:#18181b;font-family:'SF Mono',Menlo,Consolas,monospace;">{{ $otpCode }}</td>
        </tr>
    </table>
    <p style="margin:0 0 12px;font-size:13px;line-height:20px;color:#52525b;">Kod <strong>{{ $ttl }} dakika</strong> geçerlidir ve yalnız bir kez kullanılabilir. NavlunIQ ekibi sizden bu kodu asla telefonla ya da mesajla istemez; kimseyle paylaşmayın.</p>
    <p style="margin:0;font-size:13px;line-height:20px;color:#71717a;">{{ $warning }}</p>
</x-mail-base>
