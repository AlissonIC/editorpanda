<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContaAprovadaNotification extends Notification
{
    use Queueable;

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject('Sua conta foi aprovada — ' . config('app.name'))
            ->greeting('Bem-vindo(a)!')
            ->line('Boas notícias — sua conta foi aprovada e já está ativa.')
            ->line('Você já pode entrar na plataforma e começar a subir seus vídeos.')
            ->action('Acessar minha conta', route('login'))
            ->salutation('Equipe ' . config('app.name'));
    }
}
