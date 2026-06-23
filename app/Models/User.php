<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_CLIENTE = 'cliente';

    protected $fillable = [
        'nome',
        'email',
        'whatsapp',
        'password',
        'role',
        'saldo_disponivel',
    ];

    public function getNameAttribute(): ?string
    {
        return $this->attributes['nome'] ?? null;
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'saldo_disponivel' => 'decimal:2',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isCliente(): bool
    {
        return $this->role === self::ROLE_CLIENTE;
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(Evento::class);
    }

    public function albuns(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    public function saques(): HasMany
    {
        return $this->hasMany(Saque::class);
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', self::ROLE_ADMIN);
    }

    public function scopeClientes($query)
    {
        return $query->where('role', self::ROLE_CLIENTE);
    }
}
