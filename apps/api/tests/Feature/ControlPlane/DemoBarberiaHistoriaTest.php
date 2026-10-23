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
        // Los bonos se venden en caja y sus cortes se descuentan al agendar.
        $jorge = PersonaTenant::query()->where('email', 'jorge.pineda@correo.test')->sole();
        expect(AcuerdoTenant::query()->where('persona_id', $jorge->getKey())->exists())->toBeTrue()
            // Y siempre tiene su siguiente cita.
            ->and(ReservaTenant::query()->where('persona_id', $jorge->getKey())
                ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', CarbonImmutable::parse('2026-10-03 13:00', 'America/Mexico_City')->utc()))
                ->exists())->toBeTrue()
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
            ->and(Usuario::query()->where('email', 'karla@lanavaja.test')->exists())->toBeTrue()
            // Con dos sedes, todo el personal (menos dueño y administradora) tiene la suya
            // (ADR 0098): sin sucursal no vería nada.
            ->and(DB::connection('tenant')->table('asignaciones_personal')->distinct()->count('usuario_id'))->toBe(7);

        // El dueño corta y también es cliente, con su siguiente cita (bloque 32).
        $ramon = Usuario::query()->where('email', 'ramon@lanavaja.test')->sole();
        $comoCliente = PersonaTenant::query()->where('usuario_id', $ramon->getKey())->sole();
        expect($ramon->roles)->toBe(['propietario', 'instructor', 'miembro'])
            ->and(ReservaTenant::query()->where('persona_id', $comoCliente->getKey())
                ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', CarbonImmutable::parse('2026-10-03 13:00', 'America/Mexico_City')->utc()))
                ->exists())->toBeTrue();

        // Lo que la historia no crea, también con datos.
        $tablas = DB::connection('tenant');
        expect($tablas->table('prospectos')->count())->toBe(8)
            ->and($tablas->table('incidencias_cobro')->where('estado', 'abierta')->count())->toBe(2)
            ->and($tablas->table('waivers')->where('clave', 'aviso-privacidad')->where('activo', true)->exists())->toBeTrue()
            ->and($tablas->table('webhooks_salientes')->where('activo', false)->count())->toBe(1)
            ->and($tablas->table('tutelas')->count())->toBe(0)
            ->and($tablas->table('presupuestos')->pluck('estado')->sort()->values()->all())->toBe(['borrador', 'convertido', 'convertido', 'enviado', 'rechazado'])
            // En línea: uno aceptado y otro rechazado por el cliente.
            ->and($tablas->table('presupuestos')->where('respondido_por', 'cliente')->count())->toBe(2)
            // Por cobrar: lo que ya venció sin pagarse (el presupuesto convertido y bonos apartados).
            ->and($tablas->table('ordenes')->where('estado', 'pendiente')->whereNull('sesion_id')->count())->toBeGreaterThanOrEqual(2)
            // En citas no hay membresías ni permanencia (ADR 0130).
            ->and(AcuerdoTenant::query()->whereNotNull('proxima_cobro_en')->count())->toBe(0)
            // Lo más nuevo: caja de cada sede (ayer cerrada, hoy abierta), bandeja,
            // calendarios, kiosco, widgets, anticipo y datos para facturar.
            ->and($tablas->table('turnos_caja')->where('estado', 'abierta')->count())->toBe(2)
            ->and($tablas->table('turnos_caja')->where('estado', 'cerrada')->count())->toBe(2)
            ->and($tablas->table('conversaciones')->count())->toBe(3)
            ->and($tablas->table('calendarios_externos')->count())->toBe(2)
            ->and($tablas->table('kioscos')->count())->toBe(1)
            ->and($tablas->table('widgets')->pluck('tipo')->sort()->values()->all())->toBe(['agendar', 'planes'])
            ->and($tablas->table('ofertas')->where('anticipo_pct', 30)->count())->toBe(1)
            ->and($tablas->table('datos_fiscales_personas')->count())->toBe(3);
    });

    // El plan más completo de TurnoUno: lealtad y reportes avanzados a la vista.
    expect(Estudio::query()->where('slug', 'barberia')->sole()->plan_nivel)->toBe('pro');

    // Jorge fue prospecto: al entrar ve el bono que pidió (ADR 0137).
    $this->travelTo($ahora);
    $jorge = (string) $this->postJson('/api/v1/app/barberia/login', ['email' => 'jorge.pineda@correo.test', 'password' => 'password'])->json('data.token');
    $this->getJson('/api/v1/app/barberia/mi/interes', ['Authorization' => "Bearer {$jorge}"])
        ->assertOk()->assertJsonPath('data.plan.nombre', fn (string $nombre): bool => str_starts_with($nombre, 'Bono'));
});
