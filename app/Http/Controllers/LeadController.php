<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'required_without:whatsapp', 'email', 'max:255'],
            'whatsapp' => ['nullable', 'required_without:email', 'string', 'max:20'],
        ], [
            'email.required_without' => 'Informe e-mail ou WhatsApp.',
            'whatsapp.required_without' => 'Informe e-mail ou WhatsApp.',
            'email.email' => 'E-mail inválido.',
        ]);

        Lead::create([
            'email' => $validated['email'] ?? null,
            'whatsapp' => $validated['whatsapp'] ?? null,
            'origem' => 'landing',
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json([
            'message' => 'Recebemos seu contato! Avisaremos você no lançamento.',
        ], 201);
    }
}
