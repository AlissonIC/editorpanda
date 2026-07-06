<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Configuracao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConfiguracoesController extends Controller
{
    public function index(): View
    {
        $storageDisk = Configuracao::storageDisk();

        $s3Configurado = ! empty(config('filesystems.disks.s3.key'))
            && ! empty(config('filesystems.disks.s3.secret'))
            && ! empty(config('filesystems.disks.s3.bucket'));

        return view('pages.painel.configuracoes', compact('storageDisk', 's3Configurado'));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'storage_disk' => ['required', 'in:local,s3'],
        ]);

        Configuracao::set(Configuracao::CHAVE_STORAGE_DISK, $data['storage_disk']);

        return response()->json(['message' => 'Configurações salvas.']);
    }
}
