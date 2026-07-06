<?php

namespace App\Http\Controllers;

use App\Models\Plano;
use Illuminate\View\View;

class PublicController extends Controller
{
    public function home(): View
    {
        $planos = Plano::ativos()->ordenados()->get();

        return view('pages.public.home', compact('planos'));
    }
}
