<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class UsuariosController extends Controller
{
    public function index(): View
    {
        return view('pages.painel.usuarios');
    }

    public function data(Request $request): JsonResponse
    {
        $query = User::query()->select(['id', 'nome', 'email', 'whatsapp', 'role', 'status', 'saldo_disponivel', 'created_at']);

        $filters = $request->input('filters', []);
        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return DataTables::eloquent($query)
            ->editColumn('saldo_disponivel', fn ($u) => 'R$ ' . number_format((float) $u->saldo_disponivel, 2, ',', '.'))
            ->editColumn('created_at', fn ($u) => $u->created_at?->format('d/m/Y'))
            ->editColumn('role', fn ($u) => '<span class="status-badge ' . ($u->isAdmin() ? 'ativo' : 'rascunho') . '">' . ucfirst($u->role) . '</span>')
            ->addColumn('status_badge', function ($u) {
                $map = [
                    'pendente' => ['warning', 'Pendente'],
                    'aprovado' => ['success', 'Aprovado'],
                    'bloqueado' => ['danger', 'Bloqueado'],
                ];
                [$cor, $txt] = $map[$u->status] ?? ['secondary', ucfirst($u->status)];
                return '<span class="badge bg-' . $cor . '-subtle text-' . $cor . '-emphasis">' . $txt . '</span>';
            })
            ->addColumn('acoes', function ($u) {
                $btns = '';
                if ($u->status === 'pendente') {
                    $btns .= '<button class="btn btn-sm btn-success me-1 js-aprovar" data-id="' . $u->id . '" title="Aprovar"><i class="bi bi-check-lg"></i></button>';
                    $btns .= '<button class="btn btn-sm btn-outline-danger me-1 js-bloquear" data-id="' . $u->id . '" title="Bloquear"><i class="bi bi-slash-circle"></i></button>';
                } elseif ($u->status === 'aprovado') {
                    $btns .= '<button class="btn btn-sm btn-outline-warning me-1 js-bloquear" data-id="' . $u->id . '" title="Bloquear"><i class="bi bi-slash-circle"></i></button>';
                } elseif ($u->status === 'bloqueado') {
                    $btns .= '<button class="btn btn-sm btn-success me-1 js-aprovar" data-id="' . $u->id . '" title="Desbloquear"><i class="bi bi-check-lg"></i></button>';
                }
                $btns .= '<button class="btn btn-sm btn-outline-primary me-1 js-edit" data-id="' . $u->id . '"><i class="bi bi-pencil"></i></button>';
                $btns .= '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $u->id . '"><i class="bi bi-trash"></i></button>';
                return $btns;
            })
            ->rawColumns(['role', 'status_badge', 'acoes'])
            ->make(true);
    }

    public function aprovar(User $user): JsonResponse
    {
        if ($user->isAprovado()) {
            return response()->json(['message' => 'Usuário já está aprovado.'], 422);
        }
        $user->update([
            'status' => User::STATUS_APROVADO,
            'aprovado_em' => now(),
            'aprovado_por' => auth()->id(),
        ]);

        try {
            $user->notify(new \App\Notifications\ContaAprovadaNotification());
        } catch (\Throwable $e) {
            \Log::warning('Falha ao enviar email de aprovação', ['user' => $user->id, 'erro' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Usuário aprovado.']);
    }

    public function bloquear(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Você não pode bloquear a si mesmo.'], 422);
        }
        $user->update(['status' => User::STATUS_BLOQUEADO]);

        return response()->json(['message' => 'Usuário bloqueado.']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'in:admin,cliente'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $data['password'] = Hash::make($data['password']);
        // Usuário criado pelo admin já entra aprovado (não vai pra fila de análise)
        $data['status'] = User::STATUS_APROVADO;
        $data['aprovado_em'] = now();
        $data['aprovado_por'] = auth()->id();
        $user = User::create($data);

        return response()->json(['user' => $user, 'message' => 'Usuário criado.'], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'in:admin,cliente'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json(['user' => $user, 'message' => 'Usuário atualizado.']);
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Você não pode remover a si mesmo.'], 422);
        }
        $user->delete();

        return response()->json(['message' => 'Usuário removido.']);
    }
}
