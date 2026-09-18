<?php

namespace App\Mail;

use App\Support\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Genel işlem bildirimi: başlık, paragraflar, isteğe bağlı düğme. HTML + düz metin. */
class SystemNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public array $lines,
        public ?string $actionUrl = null,
        public ?string $actionText = null,
        public ?string $recipientName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'NavlunIQ | '.$this->subjectLine,
            replyTo: array_filter([Company::get('email') ? new Address(Company::get('email'), 'NavlunIQ Destek') : null]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notice', text: 'emails.text.notice');
    }
}
