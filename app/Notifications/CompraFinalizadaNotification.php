<?php

namespace App\Notifications;

use App\Models\Pedido;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompraFinalizadaNotification extends Notification
{
    use Queueable;

    public function __construct(public Pedido $pedido, public string $urlAcesso) {}

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        $qtd = $this->pedido->itens()->count();
        $total = 'R$ ' . number_format((float) $this->pedido->total, 2, ',', '.');

        return (new MailMessage)
            ->subject('Compra confirmada — ' . config('app.name'))
            ->greeting('Obrigado pela compra!')
            ->line("Seu pedido #{$this->pedido->id} foi confirmado.")
            ->line("Quantidade de vídeos: {$qtd}")
            ->line("Total: {$total}")
            ->action('Acessar meus vídeos', $this->urlAcesso)
            ->line('O link acima leva direto ao painel de suas compras.')
            ->salutation('Equipe ' . config('app.name'));
    }
}
