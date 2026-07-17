<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NovoClientePendenteNotification extends Notification
{
    use Queueable;

    public function __construct(public User $cliente) {}

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        $urlPendentes = route('painel.usuarios.index');
        $criadoEm = $this->cliente->created_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i');

        return (new MailMessage)
            ->subject('Novo cadastro aguardando aprovação — ' . config('app.name'))
            ->greeting('Olá, admin!')
            ->line('Um novo cliente se cadastrou e está aguardando aprovação:')
            ->line('**Nome:** ' . $this->cliente->nome)
            ->line('**E-mail:** ' . $this->cliente->email)
            ->line('**WhatsApp:** ' . ($this->cliente->whatsapp ?: '—'))
            ->line('**Cadastrado em:** ' . $criadoEm)
            ->action('Ver pendentes no painel', $urlPendentes)
            ->line('Você pode aprovar, bloquear ou editar diretamente pela lista de usuários.')
            ->salutation('Sistema ' . config('app.name'));
    }
}
