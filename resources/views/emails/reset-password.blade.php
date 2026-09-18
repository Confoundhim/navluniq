<x-mail-base subject-line="Şifrenizi sıfırlayın" preheader="Şifre sıfırlama bağlantınız 60 dakika geçerlidir.">
    <h1 style="margin:0 0 6px;font-size:20px;line-height:28px;font-weight:800;color:#18181b;">Şifrenizi sıfırlayın</h1>
    <p style="margin:0 0 18px;font-size:14px;line-height:22px;color:#52525b;">Merhaba{{ $recipientName ? ' '.$recipientName : '' }},</p>
    <p style="margin:0 0 12px;font-size:14px;line-height:22px;color:#3f3f46;">Hesabınız için şifre sıfırlama talebi aldık. Yeni şifrenizi belirlemek için aşağıdaki düğmeye tıklayın. Bağlantı <strong>{{ $ttl }} dakika</strong> boyunca geçerlidir.</p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 8px;">
        <tr>
            <td class="btn" style="background:#f97316;border-radius:12px;">
                <a href="{{ $url }}" style="display:inline-block;padding:13px 26px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;">Yeni şifre belirle</a>
            </td>
        </tr>
    </table>
    <p style="margin:0 0 14px;font-size:11px;line-height:16px;color:#a1a1aa;">Düğme çalışmazsa bu adresi tarayıcınıza yapıştırın:<br><a href="{{ $url }}" style="color:#a1a1aa;word-break:break-all;">{{ $url }}</a></p>
    <p style="margin:0;font-size:13px;line-height:20px;color:#71717a;">Bu talebi siz yapmadıysanız hiçbir işlem yapmanıza gerek yok; şifreniz değişmez. Şüpheli bir durum görürseniz destek ekibimize yazın.</p>
</x-mail-base>
