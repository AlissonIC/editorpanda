<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Cliente final que compra vídeos. Autenticação passwordless via magic link.
 */
class Comprador extends Authenticatable
{
    use Notifiable;

    protected $table = 'compradores';

    protected $fillable = ['email', 'nome', 'whatsapp'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    public function pedidosPagos(): HasMany
    {
        return $this->pedidos()->where('status', 'pago');
    }

    /** Notification routing (para MagicLinkNotification, CompraFinalizadaNotification) */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }
}
