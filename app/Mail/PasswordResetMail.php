<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Markalı şifre sıfırlama bağlantısı (Laravel varsayılan bildirimi yerine). */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'NavlunIQ | Şifre sıfırlama bağlantınız');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reset-password', text: 'emails.text.reset-password', with: [
            'recipientName' => $this->user->first_name,
            'ttl' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
        ]);
    }
}
