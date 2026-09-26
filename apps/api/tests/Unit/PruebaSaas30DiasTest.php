<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\TarifaSaas;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('publica 30 días sin cambiar precios ni versiones anteriores y es idempotente', function (): void {
    // Base efímera exclusiva de esta prueba: no migrar ni limpiar bases del entorno.
    config(['database.connections.trial_memory' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $anterior = DB::getDefaultConnection();
    DB::setDefaultConnection('trial_memory');
    try {
        Schema::create('tarifas_saas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('ulid')->unique();
            $tabla->string('modalidad');
            $tabla->integer('version');
            $tabla->json('definicion');
            $tabla->timestamp('vigente_desde');
            $tabla->timestamps();
            $tabla->unique(['modalidad', 'version']);
        });
        $definicion = ['dias_prueba' => 14, 'iva_porcentaje' => 16, 'tramos' => [['hasta' => 1, 'unitario_minor' => 26900]]];
        foreach (['clases' => 30, 'citas' => 14] as $modalidad => $dias) {
            DB::table('tarifas_saas')->insert([
                'ulid' => $modalidad, 'modalidad' => $modalidad, 'version' => 1,
                'definicion' => json_encode(array_replace($definicion, ['dias_prueba' => $dias])),
                'vigente_desde' => now()->subDay(),
            ]);
        }
        $migracion = require database_path('migrations/2026_09_26_100000_prueba_saas_30_dias.php');
        $migracion->up();
        $tarifas = DB::table('tarifas_saas')->where('modalidad', 'citas')->orderBy('version')->get();
        expect($tarifas)->toHaveCount(2);
        expect(json_decode($tarifas[0]->definicion, true))->toBe($definicion);
        expect(json_decode($tarifas[1]->definicion, true))->toBe(array_replace($definicion, ['dias_prueba' => 30]));
        expect(DB::table('tarifas_saas')->where('modalidad', 'clases')->count())->toBe(1);
        $migracion->up();
        expect(DB::table('tarifas_saas')->count())->toBe(3);
    } finally {
        DB::purge('trial_memory');
        DB::setDefaultConnection($anterior);
    }
});

it('usa 30 días si la definición no trae duración y respeta una tarifa explícita', function (): void {
    expect((new TarifaSaas(['definicion' => []]))->diasPrueba())->toBe(30);
    expect((new TarifaSaas(['definicion' => ['dias_prueba' => 45]]))->diasPrueba())->toBe(45);
});
