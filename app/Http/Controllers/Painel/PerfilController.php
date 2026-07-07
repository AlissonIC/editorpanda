<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Support\StorageCleanup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PerfilController extends Controller
{
    public function edit(): View
    {
        return view('pages.painel.perfil', ['user' => auth()->user()]);
    }

    public function updateDados(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'whatsapp' => ['nullable', 'string', 'max:20'],
        ]);

        $user->update($data);

        return response()->json([
            'message' => 'Dados atualizados.',
            'user' => $user->only(['id', 'nome', 'email', 'whatsapp']),
        ]);
    }

    public function updateEndereco(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'cep' => ['nullable', 'string', 'max:9'],
            'logradouro' => ['nullable', 'string', 'max:150'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:100'],
            'bairro' => ['nullable', 'string', 'max:100'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', 'max:100'],
        ]);

        $user->update($data);

        return response()->json(['message' => 'Endereço atualizado.']);
    }

    public function updateSenha(Request $request): JsonResponse
    {
        $request->validate([
            'senha_atual' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'senha_atual.current_password' => 'Senha atual incorreta.',
        ]);

        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json(['message' => 'Senha atualizada.']);
    }

    public function updateFoto(Request $request): JsonResponse
    {
        $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        if ($user->foto_perfil) {
            StorageCleanup::deleteAndVerify('public', $user->foto_perfil, 'perfil_foto_replace');
        }

        $path = $request->file('foto')->store('avatars', 'public');
        $user->update(['foto_perfil' => $path]);

        return response()->json([
            'message' => 'Foto atualizada.',
            'foto_url' => $user->foto_url,
        ]);
    }

    public function deleteFoto(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->foto_perfil) {
            StorageCleanup::deleteAndVerify('public', $user->foto_perfil, 'perfil_foto_delete');
            $user->update(['foto_perfil' => null]);
        }

        return response()->json(['message' => 'Foto removida.', 'iniciais' => $user->iniciais]);
    }
}
