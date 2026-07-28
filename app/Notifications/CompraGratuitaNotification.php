<?php

namespace App\Notifications;

use App\Models\Pedido;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email enviado quando o comprador "compra" (grátis) videos/fotos num evento
 * gratuito. Traz links assinados temporários de download direto — o comprador
 * não precisa criar conta nem clicar em magic link.
 *
 * `$linksDownload` é um array [['nome' => str, 'url' => str], ...] com um item
 * por vídeo/foto. As URLs já vêm assinadas com validade longa (dias).
 */
class CompraGratuitaNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Pedido $pedido,
        public array $linksDownload,
    ) {}

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        $qtd = count($this->linksDownload);

        $msg = (new MailMessage)
            ->subject('Seus vídeos gratuitos — ' . config('app.name'))
            ->greeting('Obrigado!')
            ->line("Seu pedido #{$this->pedido->id} está pronto — {$qtd} arquivo(s) para download.")
            ->line('Clique em cada link abaixo para baixar. Os links expiram em 30 dias.');

        foreach ($this->linksDownload as $item) {
            $msg->line("**{$item['nome']}**: [Baixar]({$item['url']})");
        }

        return $msg
            ->line('Se precisar dos arquivos novamente após 30 dias, entre em contato com o organizador do evento.')
            ->salutation('Equipe ' . config('app.name'));
    }
}
