<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Application\ResolverDerechoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\Exceptions\SinDerechoDisponible;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    // TestCase aísla storage en storage/testing; jamás limpiar tenants reales.
    expect(str_replace('\\', '/', storage_path()))->toContain('/storage/testing/');
    File::deleteDirectory(storage_path('tenants'));
});

it('crea una demo privada fiel a la fuente, sin cobros y sin reemplazar negocios existentes', function (): void {
    Mail::fake();
    Http::preventStrayRequests();
    $otro = estudioConSesion('conservar-demo', 'dueno@otro.test');
    $this->artisan('agendauno:sembrar-demo-grecon')->assertSuccessful();
    $estudio = Estudio::query()->where('slug', 'grecon-demo')->firstOrFail();
    expect($estudio->publicado)->toBeFalse()->and($estudio->privado)->toBeTrue();
    $gestor = app(GestorDeConexionTenant::class);
    $gestor->ejecutarEn($estudio, function (): void {
        expect(SesionTenant::query()->count())->toBe(191)
            ->and(SesionTenant::query()->where('estado', 'cancelada')->count())->toBe(1)
            ->and(SesionTenant::query()->whereNull('instructor_id')->count())->toBe(46)
            ->and(Usuario::query()->where('rol', 'instructor')->count())->toBe(11)
            ->and(ProductoTenant::query()->count())->toBe(4)
            ->and(DB::connection('tenant')->table('pagos')->count())->toBe(0)
            ->and(DB::connection('tenant')->table('eventos_outbox')->count())->toBe(0);
        $fuente = json_decode(File::get(database_path('demo/grecon-octubre-2026.json')), true, flags: JSON_THROW_ON_ERROR);
        $sesiones = SesionTenant::query()->with(['oferta', 'instructor'])->get();
        foreach ($fuente['sesiones'] as $s) {
            $inicio = CarbonImmutable::parse($s['fecha'].' '.$s['hora_inicio'], 'America/Mexico_City');
            $real = $sesiones->first(fn (SesionTenant $x): bool => $x->oferta->nombre === $s['actividad'] && $x->inicia_en->equalTo($inicio));
            expect($real)->not->toBeNull()
                ->and($real->instructor?->name)->toBe($s['instructor'])
                ->and($real->termina_en->diffInMinutes($real->inicia_en, true))->toEqual($s['duracion_minutos']);
        }
    });
    $id = $estudio->ulid;
    $this->artisan('agendauno:sembrar-demo-grecon')->assertSuccessful();
    expect(Estudio::query()->where('slug', 'grecon-demo')->sole()->ulid)->toBe($id)
        ->and(Estudio::query()->where('slug', $otro['slug'])->exists())->toBeTrue();
    $this->postJson('/api/v1/app/grecon-demo/login', ['email' => 'admin@grecon.test', 'password' => 'password'])
        ->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('permite reservar clases al paquete y Open Training solo a la ilimitada, y vence al fin del mes', function (): void {
    $this->artisan('agendauno:sembrar-demo-grecon')->assertSuccessful();
    $estudio = Estudio::query()->where('slug', 'grecon-demo')->sole();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function (): void {
        $paquete = PersonaTenant::query()->where('email', 'paquete@grecon.test')->sole();
        $ilimitada = PersonaTenant::query()->where('email', 'ilimitada@grecon.test')->sole();
        $vencida = PersonaTenant::query()->where('email', 'vencido@grecon.test')->sole();
        $sinplan = PersonaTenant::query()->where('email', 'sinplan@grecon.test')->sole();
        $open = SesionTenant::query()->whereHas('oferta', fn ($q) => $q->where('nombre', 'OPEN TRAINING Matutino'))->orderBy('inicia_en')->firstOrFail();
        $clase = SesionTenant::query()->whereHas('oferta', fn ($q) => $q->where('nombre', 'EXOTIC Matutino'))->orderBy('inicia_en')->firstOrFail();
        $resolver = app(ResolverDerechoTenant::class);
        expect($resolver->paraSesion($paquete, $open, 1000))->toBeNull()
            ->and($resolver->paraSesion($vencida, $clase, 1000))->toBeNull()
            ->and($resolver->paraSesion($sinplan, $clase, 1000))->toBeNull();
        $derecho = $resolver->paraSesion($paquete, $clase, 1000);
        expect($derecho)->not->toBeNull()->and($derecho->valido_hasta->toDateString())->toBe('2026-10-31');
        $reservas = app(ReservasTenant::class);
        expect(fn () => $reservas->crear($open, $paquete))->toThrow(SinDerechoDisponible::class);
        $reservas->crear($clase, $paquete);
        expect(app(LibroMayorTenant::class)->disponible($derecho))->toBe(7000);
        $reservas->crear($open, $ilimitada);
        $noviembre = $clase->replicate();
        $noviembre->inicia_en = CarbonImmutable::parse('2026-11-01 10:00', 'America/Mexico_City');
        expect($resolver->paraSesion($paquete, $noviembre, 1000))->toBeNull();
    });
    // Caracteriza el hueco actual: bloquear la reserva no oculta la sesión.
    $bearer = $this->postJson('/api/v1/app/grecon-demo/login', ['email' => 'paquete@grecon.test', 'password' => 'password'])
        ->assertOk()->json('data.token');
    $agenda = $this->getJson('/api/v1/app/grecon-demo/mi/agenda?desde=2026-10-01&hasta=2026-10-03', conBearer($bearer))->assertOk()->json('data');
    expect(collect($agenda)->contains(fn (array $s): bool => str_starts_with($s['oferta'], 'OPEN TRAINING')))->toBeTrue();
});

it('rechaza la creación fuera de entornos locales o de pruebas', function (): void {
    app()->instance('env', 'production');
    $this->artisan('agendauno:sembrar-demo-grecon')->assertFailed();
    expect(Estudio::query()->where('slug', 'grecon-demo')->exists())->toBeFalse();
});
