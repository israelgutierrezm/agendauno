<?php

declare(strict_types=1);

use App\Console\Commands\Demos\DemoBarberia;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| La demo de citas (`agendauno:sembrar-demos`): Barbería La Navaja en el negocio
| `barberia`, con clientes que vuelven con su barbero, bonos de cortes que se
| descuentan al agendar (ADR 0091) y datos en cada apartado. Con el reloj fijo.
*/

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    expect(str_replace('\\', '/', storage_path()))->toContain('/storage/testing/');
    File::deleteDirectory(storage_path('tenants'));
});

it('siembra la barbería con citas, bonos que se descuentan y datos en cada apartado', function (): void {
    Mail::fake();
    $ahora = CarbonImmutable::parse('2026-10-03 13:00', 'America/Mexico_City');

    $resultado = app(DemoBarberia::class)->ejecutar('password', 7, false, $ahora);

    expect($resultado['estudio']->slug)->toBe('barberia')
        ->and($resultado['estudio']->modalidad()->value)->toBe('citas');

    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'barberia')->sole(), function (): void {
        // El bono y la membresía se venden en caja y sus cortes se descuentan al agendar.
        $jorge = PersonaTenant::query()->where('email', 'jorge.pineda@correo.test')->sole();
        expect(AcuerdoTenant::query()->where('persona_id', $jorge->getKey())->exists())->toBeTrue()
            ->and(ReservaTenant::query()->whereNull('orden_id')
                ->whereHas('sesion.oferta', fn ($q) => $q->where('nombre', 'Corte con bono'))
                ->exists())->toBeTrue();

        foreach ([
            'articulos', 'ventas_pos', 'esquemas_pago', 'roles', 'tareas', 'notas_persona', 'tipos_documento', 'documentos',
            'aceptaciones_waiver', 'formularios', 'respuestas_formulario', 'promociones', 'recompensas_lealtad',
            'movimientos_puntos', 'difusiones', 'mensajes', 'reglas_automatizacion', 'facturas', 'productos_comerciales',
        ] as $tabla) {
            expect(DB::connection('tenant')->table($tabla)->count())->toBeGreaterThan(0, "Sin datos en {$tabla}");
        }
        expect(PersonaTenant::query()->whereNull('genero')->count())->toBe(0)
            ->and(DB::connection('tenant')->table('eventos_outbox')->whereNull('publicado_en')->count())->toBe(0)
            ->and(Usuario::query()->where('email', 'karla@lanavaja.test')->exists())->toBeTrue();
    });
});
