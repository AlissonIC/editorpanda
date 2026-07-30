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
        $album = $this->pedido->album;
        $edicaoManual = $album?->ehEdicaoManual() ?? false;

        $mail = (new MailMessage)
            ->subject('Compra confirmada — ' . config('app.name'))
            ->greeting('Obrigado pela compra!')
            ->line("Seu pedido #{$this->pedido->id} foi confirmado.")
            ->line("Quantidade de vídeos: {$qtd}")
            ->line("Total: {$total}");

        if ($edicaoManual) {
            // Fluxo edição manual: sem link de download — comprador aguarda contato.
            $prazo = $album->tempo_edicao_dias;
            $prazoMsg = $prazo
                ? "O envio será feito em até {$prazo} " . ($prazo === 1 ? 'dia' : 'dias') . " após a confirmação do pagamento."
                : "Você receberá o prazo estimado no primeiro contato.";
            $mail
                ->line('---')
                ->line('**Seus vídeos serão editados manualmente pelo responsável.**')
                ->line($prazoMsg)
                ->line("O responsável entrará em contato pelo seu e-mail (**{$this->pedido->comprador_email}**)"
                    . ($this->pedido->comprador_whatsapp ? " ou WhatsApp (**{$this->pedido->comprador_whatsapp}**)" : '')
                    . ' pra enviar os vídeos editados de forma personalizada, fora da plataforma.')
                ->line('Não é necessário acessar o site pra receber — mantenha esses contatos monitorados.');
        } else {
            $mail
                ->action('Acessar meus vídeos', $this->urlAcesso)
                ->line('O link acima leva direto ao painel de suas compras.');
        }

        return $mail->salutation('Equipe ' . config('app.name'));
    }
}
