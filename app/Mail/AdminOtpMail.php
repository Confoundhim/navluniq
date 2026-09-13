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

    // Şablona göndereceğimiz OTP kodu değişkeni
    public string $otpCode;

    /**
     * Sınıf kurucu metodu (Constructor)
     */
    public function __construct(string $otpCode)
    {
        $this->otpCode = $otpCode;
    }

    /**
     * E-Posta başlık ve konu ayarları
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'NavlunIQ Yönetici Giriş Doğrulama Kodu',
        );
    }

    /**
     * E-Posta içeriğinin bağlanacağı blade tasarımı
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-otp',
        );
    }
}
