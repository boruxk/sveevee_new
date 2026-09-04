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

class AccountStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly string $subjectLine;

    public readonly string $heading;

    public readonly string $body;

    public readonly string $actionLabel;

    public function __construct(
        public string $notificationType,
        public string $pageName,
        public string $actionUrl,
        public string $returnPath,
        public string $messageLocale,
        public array $context,
        public string $notificationId,
    ) {
        $baseKey = 'mail.account.'.$notificationType;
        $parameters = [
            'page' => $pageName,
            'replaced_page' => (string) ($context['replaced_page_name'] ?? ''),
        ];
        $bodyKey = match (true) {
            $notificationType === 'page_claim_approved' && filled($context['replaced_page_name'] ?? null) => $baseKey.'.body_replaced',
            $notificationType === 'page_claim_rejected' && ($context['reason'] ?? null) === 'claimed_by_another' => $baseKey.'.body_claimed',
            default => $baseKey.'.body',
        };

        $this->subjectLine = Lang::get($baseKey.'.subject', $parameters, $messageLocale);
        $this->heading = Lang::get($baseKey.'.heading', $parameters, $messageLocale);
        $this->body = Lang::get($bodyKey, $parameters, $messageLocale);
        $this->actionLabel = Lang::get($baseKey.'.action', $parameters, $messageLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: [new Address(config('mail.reply_to.address'), config('mail.reply_to.name'))],
            subject: $this->subjectLine,
            using: [function (Email $message): void {
                $message->returnPath($this->returnPath);
                $message->getHeaders()->addTextHeader('X-Sveevee-Message-Type', 'account-status');
                $message->getHeaders()->addTextHeader('X-Sveevee-Notification-ID', $this->notificationId);
            }],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.account-status',
            text: 'mail.account-status-text',
        );
    }
}
