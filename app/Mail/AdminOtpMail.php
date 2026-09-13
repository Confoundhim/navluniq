<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $otpCode) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'NavlunIQ Yönetici Giriş Doğrulama Kodu');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp', with: [
            'intro' => 'NavlunIQ Yönetim Paneline giriş yapmak için tek kullanımlık güvenlik kodunuz aşağıdadır. Bu kod 5 dakika boyunca geçerlidir.',
            'warning' => 'Eğer bu işlemi siz gerçekleştirmediyseniz, lütfen sistem yöneticinizle iletişime geçin.',
        ]);
    }
}
