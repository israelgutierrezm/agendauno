<?php

declare(strict_types=1);

use App\Console\Commands\Demos\DemoGrecon;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| La demo de clases (`agendauno:sembrar-demos`): Grecon Art House en el negocio
| `demo`, con solo lo que da docs/DEMO_GRECON.md (profesores, clases, horario y
| precios de prueba) y 80 miembros inventados con historia. Octubre queda igual que
| la agenda publicada. Con el reloj fijo para no depender del día en que corre.
*/

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    expect(str_replace('\\', '/', storage_path()))->toContain('/storage/testing/');
    File::deleteDirectory(storage_path('tenants'));
});

it('siembra Grecon en «demo» con su agenda de octubre, sus planes y 80 miembros con historia', function (): void {
    Mail::fake();
    $ahora = CarbonImmutable::parse('2026-10-03 13:00', 'America/Mexico_City');

    $resultado = app(DemoGrecon::class)->ejecutar('password', 7, false, $ahora);

    $estudio = $resultado['estudio'];
    expect($estudio->slug)->toBe('demo')
        ->and($estudio->nombre)->toBe('Grecon Art House (demo)')
        ->and($estudio->modalidad()->value)->toBe('clases')
        // Demo de un negocio real: solo con enlace, fuera del directorio.
        ->and($estudio->publicado)->toBeTrue()
        ->and($estudio->privado)->toBeTrue();

    $fuente = json_decode(File::get(database_path('demo/grecon-octubre-2026.json')), true, flags: JSON_THROW_ON_ERROR);
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'demo')->sole(), function () use ($fuente): void {
        // Octubre (dentro de lo sembrado: hasta tres semanas adelante) es la agenda publicada.
        $zona = 'America/Mexico_City';
        $publicadas = array_values(array_filter($fuente['sesiones'], static fn (array $s): bool => $s['fecha'] <= '2026-10-24'));
        $sesiones = SesionTenant::query()->with(['oferta', 'instructor'])
            ->whereBetween('inicia_en', [CarbonImmutable::parse('2026-10-01', $zona)->utc(), CarbonImmutable::parse('2026-10-25', $zona)->utc()])
            ->get();
        expect($sesiones)->toHaveCount(count($publicadas));
        foreach ($publicadas as $s) {
            $inicio = CarbonImmutable::parse($s['fecha'].' '.$s['hora_inicio'], $zona);
            $real = $sesiones->first(fn (SesionTenant $x): bool => $x->oferta->nombre === $s['actividad'] && $x->inicia_en->equalTo($inicio));
            $profe = $s['instructor'] === null ? null : mb_convert_case(mb_strtolower($s['instructor']), MB_CASE_TITLE);
            expect($real)->not->toBeNull()
                ->and($real->instructor?->name)->toBe($profe)
                ->and((int) $real->termina_en->diffInMinutes($real->inicia_en, true))->toBe($s['duracion_minutos'])
                ->and($real->estado->value)->toBe($s['estado_publicado'] === 'programada' ? 'programada' : 'cancelada');
        }

        // Los planes de prueba: los paquetes no incluyen Open Training; la Ilimitada, todo.
        $open = OfertaTenant::query()->where('nombre', 'like', 'OPEN TRAINING%')->pluck('id')->all();
        expect($open)->toHaveCount(2);
        foreach (ProductoTenant::query()->with('ofertas')->get() as $plan) {
            $ofertas = $plan->ofertas->pluck('id')->all();
            if ($plan->nombre === 'Ilimitada') {
                expect($ofertas)->toBe([]);
            } else {
                expect($ofertas)->not->toBe([])->and(array_intersect($ofertas, $open))->toBe([]);
            }
        }
        expect(ProductoTenant::query()->pluck('precio_minor', 'nombre')->all())->toEqual([
            'Paquete 4 clases' => 80000, 'Paquete 8 clases' => 140000, 'Paquete 12 clases' => 180000, 'Ilimitada' => 220000,
        ]);

        // Una operación con historia: miembros, ventas cobradas, por cobrar, asistencia
        // y clases apartadas para las próximas semanas.
        // El dueño (Constantino Escobar) da clases y también es alumno: tres roles,
        // una cuenta y su ficha de alumno.
        $dueno = Usuario::query()->where('email', 'constantino@grecon.test')->sole();
        expect($dueno->roles)->toBe(['propietario', 'instructor', 'miembro'])
            ->and(PersonaTenant::query()->where('usuario_id', $dueno->getKey())->exists())->toBeTrue()
            ->and(SesionTenant::query()->where('instructor_id', $dueno->getKey())->exists())->toBeTrue();

        // 80 miembros inventados más el dueño como alumno.
        expect(PersonaTenant::query()->where('tipo', 'miembro')->count())->toBe(81)
            ->and(DB::connection('tenant')->table('pagos')->count())->toBeGreaterThan(20)
            ->and(OrdenTenant::query()->where('estado', EstadoOrden::Pendiente->value)->count())->toBeGreaterThan(0)
            ->and(DB::connection('tenant')->table('asistencias')->count())->toBeGreaterThan(50)
            ->and(ReservaTenant::query()->where('estado', EstadoReserva::Confirmada->value)
                ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', CarbonImmutable::parse('2026-10-03 13:00', $zona)->utc()))
                ->count())->toBeGreaterThan(10)
            // Nada de la historia queda por avisar.
            ->and(DB::connection('tenant')->table('eventos_outbox')->whereNull('publicado_en')->count())->toBe(0);
    });

    $this->postJson('/api/v1/app/demo/login', ['email' => 'admin@grecon.test', 'password' => 'password'])
        ->assertOk()->assertJsonStructure(['data' => ['token']]);
});
