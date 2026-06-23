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
        $query = User::query()->select(['id', 'nome', 'email', 'whatsapp', 'role', 'saldo_disponivel', 'created_at']);

        return DataTables::eloquent($query)
            ->editColumn('saldo_disponivel', fn ($u) => 'R$ ' . number_format((float) $u->saldo_disponivel, 2, ',', '.'))
            ->editColumn('created_at', fn ($u) => $u->created_at?->format('d/m/Y'))
            ->editColumn('role', fn ($u) => '<span class="status-badge ' . ($u->isAdmin() ? 'ativo' : 'rascunho') . '">' . ucfirst($u->role) . '</span>')
            ->addColumn('acoes', function ($u) {
                return '<button class="btn btn-sm btn-outline-primary me-1 js-edit" data-id="' . $u->id . '"><i class="bi bi-pencil"></i></button>'
                    . '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $u->id . '"><i class="bi bi-trash"></i></button>';
            })
            ->rawColumns(['role', 'acoes'])
            ->make(true);
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
