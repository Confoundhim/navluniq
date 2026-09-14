<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otpCode,
        public string $purpose = 'Hesabınızı doğrulamak',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'NavlunIQ Doğrulama Kodu');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp', with: [
            'intro' => $this->purpose.' için tek kullanımlık güvenlik kodunuz aşağıdadır. Bu kod 5 dakika boyunca geçerlidir.',
            'warning' => 'Bu işlemi siz başlatmadıysanız bu e-postayı yok sayabilirsiniz; kod olmadan hesabınıza erişilemez.',
        ]);
    }
}
