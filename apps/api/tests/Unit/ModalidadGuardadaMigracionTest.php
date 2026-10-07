<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('llena la modalidad de cada negocio desde su giro y desde entonces es obligatoria', function (): void {
    // Base efímera exclusiva de esta prueba: no migrar ni limpiar bases del entorno.
    config(['database.connections.modalidad_memoria' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $anterior = DB::getDefaultConnection();
    DB::setDefaultConnection('modalidad_memoria');
    try {
        Schema::create('estudios', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('slug')->unique();
            $tabla->string('perfil_negocio')->default('general');
        });
        foreach (['barberia', 'spa', 'salud', 'pilates', 'general', 'giro-retirado'] as $perfil) {
            DB::table('estudios')->insert(['slug' => $perfil, 'perfil_negocio' => $perfil]);
        }

        $migracion = require database_path('migrations/2026_10_07_000200_modalidad_de_los_negocios.php');
        $migracion->up();

        expect(DB::table('estudios')->pluck('modalidad', 'slug')->all())->toBe([
            'barberia' => 'citas',
            'spa' => 'citas',
            'salud' => 'citas',
            'pilates' => 'clases',
            'general' => 'clases',
            // Un giro que ya no existe trabaja con clases, como `general`.
            'giro-retirado' => 'clases',
        ]);
        expect(fn () => DB::table('estudios')->insert(['slug' => 'sin-modalidad', 'perfil_negocio' => 'spa']))
            ->toThrow(QueryException::class);

        $migracion->down();
        expect(Schema::hasColumn('estudios', 'modalidad'))->toBeFalse();
    } finally {
        DB::purge('modalidad_memoria');
        DB::setDefaultConnection($anterior);
    }
});
