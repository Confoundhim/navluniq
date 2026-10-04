{{-- Hata sayfası düzeni: veritabanı ve oturum gerektirmez (500/503'te de çalışır); telefonda 390 px'e uyar. --}}
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#f97316">
    <title>{{ $title }} | NavlunIQ</title>
    <link rel="icon" type="image/png" href="/images/fav-ico.png">
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; font-family: -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: #fafaf9; color: #171717; }
        .card { width: 100%; max-width: 440px; background: #fff; border: 1px solid #e7e5e4; border-radius: 24px; padding: 32px 24px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,.05); }
        .code { display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px; border-radius: 999px; background: #fff7ed; color: #f97316; font-weight: 800; font-size: 14px; letter-spacing: .02em; }
        h1 { font-size: 20px; margin: 18px 0 8px; letter-spacing: -.01em; }
        p { margin: 0; font-size: 14px; line-height: 1.6; color: #57534e; }
        .actions { margin-top: 22px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
        a.btn { display: inline-block; padding: 10px 18px; border-radius: 14px; font-size: 13px; font-weight: 600; text-decoration: none; }
        a.primary { background: #f97316; color: #fff; }
        a.ghost { background: #f5f5f4; color: #292524; }
        .brand { margin-top: 24px; font-size: 11px; color: #a8a29e; }
        @media (prefers-color-scheme: dark) {
            body { background: #0a0a0a; color: #fafafa; }
            .card { background: #171717; border-color: #262626; box-shadow: none; }
            .code { background: rgba(249,115,22,.12); }
            p { color: #a3a3a3; }
            a.ghost { background: #262626; color: #e5e5e5; }
        }
    </style>
    @isset($refresh)<meta http-equiv="refresh" content="{{ $refresh }}">@endisset
</head>
<body>
    <main class="card">
        <span class="code">{{ $code }}</span>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <div class="actions">
            @isset($primary)<a class="btn primary" href="{{ $primary[0] }}">{{ $primary[1] }}</a>@endisset
            <a class="btn ghost" href="/">Ana sayfa</a>
        </div>
        <div class="brand">NavlunIQ</div>
    </main>
</body>
</html>
