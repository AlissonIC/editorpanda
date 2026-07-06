<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLinkNotification extends Notification
{
    use Queueable;

    public function __construct(public string $url) {}

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject('Seu link de acesso — ' . config('app.name'))
            ->greeting('Olá!')
            ->line('Use o botão abaixo para acessar suas compras. O link expira em 30 minutos.')
            ->action('Acessar minhas compras', $this->url)
            ->line('Se você não solicitou este link, pode ignorar este e-mail.')
            ->salutation('Equipe ' . config('app.name'));
    }
}
