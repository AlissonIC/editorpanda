<?php

namespace Database\Seeders;

use App\Models\Album;
use App\Models\Evento;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@panda.test'],
            [
                'nome' => 'Admin Panda',
                'whatsapp' => '(11) 99999-0000',
                'password' => Hash::make('admin1234'),
                'role' => User::ROLE_ADMIN,
            ]
        );

        $cliente = User::firstOrCreate(
            ['email' => 'cliente@panda.test'],
            [
                'nome' => 'Jonathan Lanke',
                'whatsapp' => '(11) 99888-7777',
                'password' => Hash::make('cliente1234'),
                'role' => User::ROLE_CLIENTE,
                'saldo_disponivel' => 0,
            ]
        );

        $evento = Evento::firstOrCreate(
            ['user_id' => $cliente->id, 'nome' => 'CAMPEOES'],
            [
                'localizacao_cidade' => 'Porto Alegre',
                'localizacao_estado' => 'Rio Grande do Sul',
                'data' => '2026-03-11',
                'status' => 'ativo',
            ]
        );

        Album::firstOrCreate(
            ['user_id' => $cliente->id, 'evento_id' => $evento->id, 'nome' => 'ENTREVISTAS SANTO ANGELO'],
            [
                'subtitulo' => null,
                'preco' => 99.90,
                'status' => 'publicado',
            ]
        );

        Lead::firstOrCreate(
            ['email' => 'lead-exemplo@panda.test'],
            ['whatsapp' => '(11) 91234-5678', 'origem' => 'landing']
        );
    }
}
