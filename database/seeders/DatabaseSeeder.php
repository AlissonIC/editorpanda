<?php

namespace Database\Seeders;

use App\Models\Album;
use App\Models\Evento;
use App\Models\Plano;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Plano::firstOrCreate(
            ['nome' => 'Inicial'],
            [
                'descricao' => 'Plano ideal para começar a vender suas mídias.',
                'preco' => 99.90,
                'armazenamento_gb' => 750,
                'taxa_por_venda' => 10.00,
                'popular' => false,
                'ativo' => true,
                'ordem' => 1,
            ]
        );

        Plano::firstOrCreate(
            ['nome' => 'Profissional'],
            [
                'descricao' => 'Para profissionais que querem vender suas mídias com reconhecimento facial.',
                'preco' => 199.90,
                'armazenamento_gb' => 1024,
                'taxa_por_venda' => 10.00,
                'popular' => true,
                'ativo' => true,
                'ordem' => 2,
            ]
        );

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
            ]
        );
        // saldo_disponivel é gerenciado só server-side; setamos via forceFill se precisar seed
        // (no seed padrão fica no default 0 do schema)

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
    }
}
