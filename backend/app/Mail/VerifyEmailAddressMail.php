<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\Mime\Email;

class VerifyEmailAddressMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $verificationUrl,
        public string $returnPath,
        public string $messageLocale,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: [new Address(config('mail.reply_to.address'), config('mail.reply_to.name'))],
            subject: Lang::get('mail.verification.subject', locale: $this->messageLocale),
            using: [function (Email $message): void {
                $message->returnPath($this->returnPath);
                $message->getHeaders()->addTextHeader('X-Sveevee-Message-Type', 'email-verification');
            }],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.verify-email',
            text: 'mail.verify-email-text',
        );
    }
}
