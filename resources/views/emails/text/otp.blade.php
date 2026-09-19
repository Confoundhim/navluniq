Doğrulama kodunuz

Merhaba{{ $recipientName ? ' '.$recipientName : '' }},

{{ $intro }}

Kod: {{ $otpCode }}

Kod {{ $ttl }} dakika geçerlidir ve yalnız bir kez kullanılabilir. Kimseyle paylaşmayın.
{{ $warning }}

NavlunIQ Ekibi · {{ rtrim((string) config('app.url'), '/') }}
