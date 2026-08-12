<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your sveevee password was changed')
            ->greeting('Hello '.$notifiable->display_name.',')
            ->line('Your sveevee password was changed successfully.')
            ->line('If this was not you, please reset your password immediately.');
    }
}
