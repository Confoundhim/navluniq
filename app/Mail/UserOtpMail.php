<?php

namespace App\Mail;

use App\Services\OtpService;
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
        public ?string $recipientName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'NavlunIQ | Doğrulama kodunuz: '.$this->otpCode);
    }

    public function content(): Content
    {
        $with = [
            'intro' => $this->purpose.' için tek kullanımlık güvenlik kodunuz aşağıdadır.',
            'warning' => 'Bu işlemi siz başlatmadıysanız bu e-postayı yok sayabilirsiniz; kod olmadan hesabınıza erişilemez.',
            'ttl' => OtpService::TTL_MINUTES,
        ];

        return new Content(view: 'emails.otp', text: 'emails.text.otp', with: $with);
    }
}
