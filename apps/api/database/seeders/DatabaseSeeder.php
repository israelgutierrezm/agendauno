<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Sin datos globales que sembrar: cada estudio vive en su propia base y se crea al
 * registrarse. Los demos se siembran con `php artisan agendauno:sembrar-demos`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void {}
}
