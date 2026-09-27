<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CalcularRentaSaas;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('versiona rangos sin alterar historial ni citas y protege tarifas personalizadas o futuras', function (string $caso): void {
    config(['database.connections.rangos_memory' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $anterior = DB::getDefaultConnection();
    DB::setDefaultConnection('rangos_memory');
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
        $definicion = ['dias_prueba' => 30, 'iva_porcentaje' => 16, 'bandas' => [
            ['hasta' => 40, 'monto_minor' => 33900],
            ['hasta' => 80, 'monto_minor' => 63900],
            ['hasta' => 120, 'monto_minor' => 90900],
            ['hasta' => 200, 'monto_minor' => 135900],
            ['hasta' => 300, 'monto_minor' => 178900],
            ['hasta' => 500, 'monto_minor' => 264900],
            ['hasta' => null, 'monto_minor' => 288900],
        ]];
        if ($caso === 'personalizada') {
            $definicion['bandas'][0]['monto_minor'] = 99900;
        }
        $json = json_encode($definicion, JSON_THROW_ON_ERROR);
        foreach (['clases', 'citas'] as $modalidad) {
            DB::table('tarifas_saas')->insert([
                'ulid' => $modalidad, 'modalidad' => $modalidad, 'version' => 1,
                'definicion' => $json, 'vigente_desde' => now()->subDay(),
            ]);
        }
        if ($caso === 'futura') {
            DB::table('tarifas_saas')->insert([
                'ulid' => 'futura', 'modalidad' => 'clases', 'version' => 2,
                'definicion' => $json, 'vigente_desde' => now()->addDay(),
            ]);
        }
        $migracion = require database_path('migrations/2026_09_26_200000_rangos_alumnos_saas.php');
        if ($caso !== 'normal') {
            expect(fn () => $migracion->up())->toThrow(RuntimeException::class);
            expect(DB::table('tarifas_saas')->count())->toBe($caso === 'futura' ? 3 : 2);

            return;
        }
        $migracion->up();
        $tarifas = DB::table('tarifas_saas')->where('modalidad', 'clases')->orderBy('version')->get();
        expect($tarifas)->toHaveCount(2);
        expect($tarifas[0]->definicion)->toBe($json);
        expect(DB::table('tarifas_saas')->where('modalidad', 'citas')->sole()->definicion)->toBe($json);
        $nueva = json_decode($tarifas[1]->definicion, true, 512, JSON_THROW_ON_ERROR);
        expect($nueva['dias_prueba'])->toBe(30)->and($nueva['iva_porcentaje'])->toBe(16);
        expect(array_column($nueva['bandas'], 'monto_minor'))->not->toContain(135900);
        $calcular = new CalcularRentaSaas;
        foreach ([0 => 0, 1 => 33900, 49 => 33900, 50 => 63900, 99 => 63900,
            100 => 90900, 199 => 90900, 200 => 178900, 300 => 178900,
            301 => 264900, 500 => 264900, 501 => 288900, 2000 => 288900] as $alumnos => $monto) {
            expect($calcular->clases($nueva, $alumnos)['subtotal_minor'])->toBe($monto);
        }
        $migracion->up();
        expect(DB::table('tarifas_saas')->count())->toBe(3);
    } finally {
        DB::purge('rangos_memory');
        DB::setDefaultConnection($anterior);
    }
})->with(['normal', 'personalizada', 'futura']);
