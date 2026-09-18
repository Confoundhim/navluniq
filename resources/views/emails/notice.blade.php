<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f5f5f7; color: #1d1d1f; margin: 0; padding: 40px 20px; }
        .container { max-width: 520px; background-color: #ffffff; border-radius: 24px; padding: 40px; margin: 0 auto; border: 1px solid #f5f5f7; }
        .logo { font-size: 24px; font-weight: bold; text-align: center; margin-bottom: 24px; }
        .logo-iq { color: #f97316; }
        h1 { font-size: 18px; margin: 0 0 16px; }
        p { font-size: 14px; line-height: 1.6; color: #515154; margin: 0 0 12px; }
        .btn { display: inline-block; margin-top: 12px; padding: 12px 22px; background: #f97316; color: #ffffff !important; text-decoration: none; border-radius: 12px; font-weight: bold; font-size: 14px; }
        .footer { font-size: 11px; color: #86868b; text-align: center; margin-top: 32px; border-top: 1px solid #f5f5f7; padding-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo"><span>Navlun</span><span class="logo-iq">IQ</span></div>
        <h1>{{ $subjectLine }}</h1>
        @foreach($lines as $line)
            <p>{{ $line }}</p>
        @endforeach
        @if($actionUrl)
            <a class="btn" href="{{ $actionUrl }}">{{ $actionText ?? 'Panele git' }}</a>
        @endif
        <div class="footer">
            © {{ date('Y') }} {{ \App\Support\Company::get('name') }}<br>
            Bu e-posta otomatik olarak gönderilmiştir, lütfen yanıtlamayınız.
        </div>
    </div>
</body>
</html>
