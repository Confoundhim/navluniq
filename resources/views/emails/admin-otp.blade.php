<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f7;
            color: #1d1d1f;
            margin: 0;
            padding: 40px 20px;
        }

        .container {
            max-width: 500px;
            background-color: #ffffff;
            border-radius: 24px;
            padding: 40px;
            margin: 0 auto;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
            border: 1px solid #f5f5f7;
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 30px;
        }

        .logo-iq {
            color: #f97316;
            /* Lojistik Turuncusu */
        }

        .content {
            font-size: 14px;
            line-height: 1.6;
            color: #515154;
            text-align: center;
        }

        .otp-box {
            background-color: #f5f5f7;
            border-radius: 16px;
            padding: 20px;
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 6px;
            text-align: center;
            color: #1d1d1f;
            margin: 30px 0;
            border: 1px solid #e5e5ea;
        }

        .footer {
            font-size: 11px;
            color: #86868b;
            text-align: center;
            margin-top: 40px;
            border-top: 1px solid #f5f5f7;
            padding-top: 20px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="logo">
            <span>Navlun</span><span class="logo-iq">IQ</span>
        </div>
        <div class="content">
            <p>Merhaba,</p>
            <p>NavlunIQ Yönetim Paneline giriş yapmak için tek kullanımlık güvenlik kodunuz aşağıdadır. Bu kod 5 dakika
                boyunca geçerlidir.</p>

            <!-- OTP Kodu Alanı -->
            <div class="otp-box">
                {{ $otpCode }}
            </div>

            <p>Eğer bu işlemi siz gerçekleştirmediyseniz, lütfen sistem yöneticinizle iletişime geçin.</p>
        </div>
        <div class="footer">
            © {{ date('Y') }} NavlunIQ Akıllı Lojistik Teknolojileri A.Ş.<br>
            Bu e-posta otomatik olarak gönderilmiştir, lütfen yanıtlamayınız.
        </div>
    </div>
</body>

</html>
