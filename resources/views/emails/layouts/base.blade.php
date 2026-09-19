@php
    $message = $message ?? null;
    $company = \App\Support\Company::all();
    // Gönderim sırasında $message vardır: logolar iletiye gömülür (cid:), uzak görselleri engelleyen
    // istemcilerde de görünür. Önizleme/render'da mutlak adres kullanılır.
    $embed = function (string $file) use ($message) {
        $path = public_path($file);
        if ($message !== null && is_file($path)) {
            try {
                return $message->embed($path);
            } catch (\Throwable) {
            }
        }

        return url('/'.$file);
    };
    $logoUrl = $embed('images/logo-dark.png');
    $symbolUrl = $embed('images/dark-symbol-logo.png');
    $siteUrl = rtrim((string) config('app.url'), '/');
    $social = array_filter([
        'Instagram' => \App\Models\CmsContent::getVal('social_instagram'),
        'WhatsApp' => \App\Models\CmsContent::getVal('social_whatsapp'),
        'Telegram' => \App\Models\CmsContent::getVal('social_telegram'),
    ]);
    $etbis = trim((string) \App\Models\CmsContent::getVal('etbis_code'));
@endphp
<!DOCTYPE html>
<html lang="tr" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subjectLine ?? 'NavlunIQ' }}</title>
    <style>
        body { margin: 0; padding: 0; background: #f4f4f6; -webkit-text-size-adjust: 100%; }
        table { border-collapse: collapse; }
        img { border: 0; outline: none; text-decoration: none; display: block; }
        a { color: #ea580c; }
        .btn:hover { background: #ea580c !important; }
        @media only screen and (max-width: 600px) {
            .wrap { width: 100% !important; }
            .pad { padding-left: 20px !important; padding-right: 20px !important; }
            .otp { font-size: 28px !important; letter-spacing: 6px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#f4f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f4f4f6;font-size:1px;line-height:1px;">{{ $preheader ?? '' }}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f6;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" class="wrap" width="560" cellpadding="0" cellspacing="0" style="width:560px;max-width:560px;">
                    <tr>
                        <td align="center" style="padding:0 0 20px;">
                            <a href="{{ $siteUrl }}" style="text-decoration:none;">
                                <img src="{{ $logoUrl }}" width="150" alt="NavlunIQ" style="width:150px;height:auto;margin:0 auto;">
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff;border-radius:20px;border:1px solid #e9e9ee;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="height:5px;background:linear-gradient(90deg,#f97316,#fb923c);border-radius:20px 20px 0 0;font-size:0;line-height:0;">&nbsp;</td>
                                </tr>
                                <tr>
                                    <td class="pad" style="padding:32px 36px 12px;">
                                        {{ $slot }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="pad" style="padding:8px 36px 32px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #ececf1;">
                                            <tr>
                                                <td style="padding-top:20px;font-size:13px;line-height:20px;color:#3f3f46;">
                                                    Saygılarımızla,<br>
                                                    <strong style="color:#18181b;">NavlunIQ Ekibi</strong><br>
                                                    <span style="color:#71717a;">Akıllı Lojistik Ağı</span>
                                                </td>
                                                <td align="right" valign="top" style="padding-top:20px;">
                                                    <img src="{{ $symbolUrl }}" width="56" alt="NIQ" style="width:56px;height:auto;">
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="padding-top:14px;font-size:12px;line-height:18px;color:#71717a;">
                                                    @if($company['phone'])<a href="tel:{{ preg_replace('/\s+/', '', $company['phone']) }}" style="color:#3f3f46;text-decoration:none;">{{ $company['phone'] }}</a> &nbsp;·&nbsp; @endif
                                                    @if($company['email'])<a href="mailto:{{ $company['email'] }}" style="color:#3f3f46;text-decoration:none;">{{ $company['email'] }}</a> &nbsp;·&nbsp; @endif
                                                    <a href="{{ $siteUrl }}" style="color:#ea580c;text-decoration:none;font-weight:600;">navluniq.com</a>
                                                    @if($social !== [])
                                                        <br>
                                                        @foreach($social as $label => $link)
                                                            <a href="{{ $link }}" style="color:#71717a;text-decoration:none;">{{ $label }}</a>@if(! $loop->last) &nbsp;·&nbsp; @endif
                                                        @endforeach
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:22px 24px 0;font-size:11px;line-height:17px;color:#a1a1aa;">
                            {{ $company['name'] }}@if($company['address']) · {{ $company['address'] }}@endif<br>
                            @if($company['tax_office'] || $company['tax_no']){{ $company['tax_office'] }} VD · VKN {{ $company['tax_no'] }}@endif
                            @if($company['mersis_no']) · MERSİS {{ $company['mersis_no'] }}@endif
                            @if($company['trade_registry_no']) · Ticaret Sicil {{ $company['trade_registry_no'] }}@endif
                            @if($etbis !== '') · ETBİS {{ $etbis }}@endif<br>
                            Bu e-posta navluniq.com üzerindeki hesabınızla ilgili işlem bildirimidir; otomatik gönderilmiştir.
                            Sorularınız için <a href="{{ $siteUrl }}/iletisim" style="color:#a1a1aa;">iletişim sayfamızı</a> kullanın.
                            <br><a href="{{ $siteUrl }}/sozlesmeler/kvkk" style="color:#a1a1aa;">KVKK</a> · <a href="{{ $siteUrl }}/sozlesmeler/gizlilik-politikasi" style="color:#a1a1aa;">Gizlilik</a> · © {{ date('Y') }} NavlunIQ
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
