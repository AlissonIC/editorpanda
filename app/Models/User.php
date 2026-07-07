<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'foto_perfil',
        'password',
        'role',
        'plano_id',
        // Endereço
        'cep',
        'logradouro',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'estado',
        'plano_expira_em',
        // NÃO adicione `armazenamento_bytes` nem `saldo_disponivel` aqui:
        // são gerenciados exclusivamente por operações server-side via
        // DB::table('users')->update(...) para prevenir mass assignment.
    ];

    public function getFotoUrlAttribute(): ?string
    {
        if (! $this->foto_perfil) {
            return null;
        }
        return asset('storage/' . ltrim($this->foto_perfil, '/'));
    }

    public function getIniciaisAttribute(): string
    {
        return collect(preg_split('/\s+/', trim((string) $this->nome)))
            ->filter()
            ->take(2)
            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
            ->implode('') ?: 'U';
    }

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
            'armazenamento_bytes' => 'integer',
            'plano_expira_em' => 'datetime',
        ];
    }

    /** Limite do plano em bytes, ou null se sem plano (ilimitado). */
    public function armazenamentoLimiteBytes(): ?int
    {
        if (! $this->plano) return null;
        return (int) $this->plano->armazenamento_gb * 1024 * 1024 * 1024;
    }

    public function armazenamentoPercentual(): float
    {
        $limite = $this->armazenamentoLimiteBytes();
        if (! $limite) return 0.0;
        return min(100, ($this->armazenamento_bytes / $limite) * 100);
    }

    public function podeArmazenar(int $bytesAdicionais): bool
    {
        // Sem plano ativo → não pode enviar nada. Admin passa (isAdmin() = true).
        if (! $this->isAdmin() && ! $this->temPlanoAtivo()) {
            return false;
        }
        $limite = $this->armazenamentoLimiteBytes();
        if ($limite === null) return true;
        return ($this->armazenamento_bytes + $bytesAdicionais) <= $limite;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isCliente(): bool
    {
        return $this->role === self::ROLE_CLIENTE;
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class);
    }

    public function assinaturas(): HasMany
    {
        return $this->hasMany(Assinatura::class)->latest('id');
    }

    /** Assinatura ativa atual (ou null se expirada/cancelada/inexistente). */
    public function assinaturaAtiva(): ?Assinatura
    {
        return $this->assinaturas()->ativas()->first();
    }

    public function temPlanoAtivo(): bool
    {
        return $this->assinaturaAtiva() !== null;
    }

    public function diasRestantesPlano(): ?int
    {
        $a = $this->assinaturaAtiva();
        if (! $a) return null;
        return max(0, (int) ceil(now()->diffInDays($a->expira_em, false)));
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
