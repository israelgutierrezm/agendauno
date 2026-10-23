<?php

declare(strict_types=1);

use App\Console\Commands\Demos\DemoGrecon;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\CargoRenta;
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

        // Los planes de prueba: los paquetes no incluyen Open Training; la Ilimitada y la
        // membresía, todo.
        $open = OfertaTenant::query()->where('nombre', 'like', 'OPEN TRAINING%')->pluck('id')->all();
        expect($open)->toHaveCount(2);
        foreach (ProductoTenant::query()->with('ofertas')->get() as $plan) {
            $ofertas = $plan->ofertas->pluck('id')->all();
            if (in_array($plan->nombre, ['Ilimitada', 'Membresía Ilimitada'], true)) {
                expect($ofertas)->toBe([]);
            } else {
                expect($ofertas)->not->toBe([])->and(array_intersect($ofertas, $open))->toBe([]);
            }
        }
        expect(ProductoTenant::query()->pluck('precio_minor', 'nombre')->all())->toEqual([
            'Paquete 4 clases' => 80000, 'Paquete 8 clases' => 140000, 'Paquete 12 clases' => 180000, 'Ilimitada' => 220000,
            'Membresía Ilimitada' => 200000,
        ]);
        // La membresía del estudio: se renueva cada mes, 12 meses de permanencia y, si se
        // da de baja antes, el 50 % de lo que falte (ADR 0137).
        expect(ProductoTenant::query()->where('nombre', 'Membresía Ilimitada')->sole())
            ->toMatchArray(['permanencia_meses' => 12, 'penalizacion_porcentaje' => 50, 'cobra_inscripcion' => true]);

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

        // Los planes valen hasta fin de mes: nadie compra uno que le dure pocos días
        // (p. ej. un paquete de 12 el día 31).
        $cortos = DB::connection('tenant')->table('derechos')->whereNotNull('acuerdo_id')->get(['valido_desde', 'valido_hasta'])
            ->filter(fn (object $d): bool => CarbonImmutable::parse($d->valido_desde)->diffInDays(CarbonImmutable::parse($d->valido_hasta)) < 7);
        expect($cortos)->toHaveCount(0);

        // Cada apartado tiene datos (lo que el documento no da es inventado), y cada
        // miembro su género para nombrarla Alumna o Alumno.
        foreach ([
            'articulos', 'ventas_pos', 'esquemas_pago', 'niveles', 'recursos', 'roles', 'tareas', 'notas_persona',
            'tipos_documento', 'documentos', 'aceptaciones_waiver', 'formularios', 'respuestas_formulario', 'promociones',
            'recompensas_lealtad', 'movimientos_puntos', 'difusiones', 'mensajes', 'reglas_automatizacion', 'facturas',
        ] as $tabla) {
            expect(DB::connection('tenant')->table($tabla)->count())->toBeGreaterThan(0, "Sin datos en {$tabla}");
        }
        // Un estudio no registra entradas: entrar es tomar la clase (ADR 0105).
        expect(DB::connection('tenant')->table('accesos')->count())->toBe(0);
        // Las cuentas para revisar «Mi cuenta» conservan el plan que dice su descripción.
        foreach (['valeria.rios@correo.test' => 'Paquete 8 clases', 'renata.soto@correo.test' => 'Ilimitada'] as $correo => $plan) {
            $persona = PersonaTenant::query()->where('email', $correo)->sole();
            expect(AcuerdoTenant::query()->with('producto')->where('persona_id', $persona->getKey())->get()
                ->map(fn (AcuerdoTenant $a): ?string => $a->producto?->nombre)->unique()->values()->all())->toBe([$plan]);
        }
        expect(PersonaTenant::query()->whereNull('genero')->count())->toBe(0)
            ->and(DB::connection('tenant')->table('mensajes')->where('estado', 'encolado')->count())->toBe(0);

        // Lo que la historia no crea, también con datos (bloque 32): membresías que se
        // renuevan (una en mora, una dada de baja con penalización, con pago automático
        // la del dueño), prospectos, cobros por conciliar, aviso de privacidad, días
        // cerrados, bloqueos y un webhook pausado. Familias queda apagado: para el
        // negocio no existe hasta que lo pide.
        $dueno = PersonaTenant::query()->where('email', 'constantino@grecon.test')->sole();
        $tablas = DB::connection('tenant');
        expect(AcuerdoTenant::query()->whereNotNull('proxima_cobro_en')->count())->toBeGreaterThanOrEqual(5)
            ->and($tablas->table('domiciliaciones')->where('estado', 'activa')->where('persona_id', $dueno->getKey())->exists())->toBeTrue()
            ->and($tablas->table('domiciliaciones')->where('estado', 'activa')->count())->toBeGreaterThanOrEqual(3)
            ->and($tablas->table('procesos_dunning')->where('estado', 'en_mora')->count())->toBe(1)
            ->and($tablas->table('bajas_acuerdo')->count())->toBe(1)
            ->and($tablas->table('prospectos')->count())->toBe(10)
            ->and($tablas->table('prospectos')->where('estado', 'convertido')->count())->toBe(1)
            ->and($tablas->table('incidencias_cobro')->where('estado', 'abierta')->count())->toBe(2)
            ->and($tablas->table('waivers')->where('clave', 'aviso-privacidad')->where('activo', true)->exists())->toBeTrue()
            ->and($tablas->table('excepciones_horario')->count())->toBe(4)
            ->and($tablas->table('bloqueos_agenda')->count())->toBe(2)
            ->and($tablas->table('webhooks_salientes')->where('activo', false)->count())->toBe(1)
            ->and($tablas->table('tutelas')->count())->toBe(0)
            // Lo más nuevo: caja (ayer cerrada, hoy abierta), bandeja, calendarios,
            // kiosco, widgets, videos y las plataformas de bienestar.
            ->and($tablas->table('turnos_caja')->pluck('estado')->sort()->values()->all())->toBe(['abierta', 'cerrada'])
            ->and($tablas->table('conversaciones')->count())->toBe(2)
            ->and($tablas->table('calendarios_externos')->count())->toBe(2)
            ->and($tablas->table('kioscos')->count())->toBe(1)
            ->and($tablas->table('widgets')->pluck('tipo')->sort()->values()->all())->toBe(['calendario', 'planes'])
            ->and($tablas->table('videos')->where('publicado', true)->count())->toBe(4)
            ->and($tablas->table('integraciones')->pluck('proveedor')->sort()->values()->all())->toBe(['fitpass', 'totalpass', 'wellhub'])
            ->and($tablas->table('lugares_plataforma')->where('proveedor', 'fitpass')->where('estado', 'reservado')->count())->toBe(3)
            ->and($tablas->table('checkins')->where('proveedor', 'fitpass')->count())->toBe(2);
    });
    expect(Estudio::query()->where('slug', 'demo')->sole()->modulosHabilitados())->toBe([]);

    // Lo que paga a la plataforma: el mes ya cerrado, medido y pagado (sin tarifas en
    // las pruebas, queda sin cargo); ninguno se queda pendiente.
    $cargo = CargoRenta::query()->where('estudio_id', Estudio::query()->where('slug', 'demo')->value('id'))->where('periodo', '2026-09')->first();
    expect($cargo?->estado->value)->toBeIn(['pagado', 'sin_cargo']);

    $this->postJson('/api/v1/app/demo/login', ['email' => 'admin@grecon.test', 'password' => 'password'])
        ->assertOk()->assertJsonStructure(['data' => ['token']]);

    // Valeria fue prospecto: al entrar ve la membresía y la clase que pidió (ADR 0137).
    $this->travelTo($ahora);
    $valeria = (string) $this->postJson('/api/v1/app/demo/login', ['email' => 'valeria.rios@correo.test', 'password' => 'password'])->json('data.token');
    $this->getJson('/api/v1/app/demo/mi/interes', ['Authorization' => "Bearer {$valeria}"])
        ->assertOk()->assertJsonPath('data.plan.nombre', 'Membresía Ilimitada');
});
