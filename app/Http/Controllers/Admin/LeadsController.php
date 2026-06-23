<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class LeadsController extends Controller
{
    public function index(): View
    {
        $total = Lead::count();

        return view('pages.painel.leads', compact('total'));
    }

    public function data(Request $request): JsonResponse
    {
        $query = Lead::query()->select(['id', 'email', 'whatsapp', 'origem', 'ip', 'created_at']);

        return DataTables::eloquent($query)
            ->editColumn('email', fn ($l) => $l->email ?: '—')
            ->editColumn('whatsapp', fn ($l) => $l->whatsapp ?: '—')
            ->editColumn('created_at', fn ($l) => $l->created_at?->format('d/m/Y H:i'))
            ->make(true);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete();

        return response()->json(['message' => 'Lead removido.']);
    }
}
