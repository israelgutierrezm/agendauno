<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Membresias\TipoVigencia;
use App\Modules\Tenancy\Membresias\VigenciaProducto;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\PoliticaReservaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/** Copia de demostración, no integración ni cuenta oficial del estudio. */
class SembrarDemoGrecon extends Command
{
    protected $signature = 'agendauno:sembrar-demo-grecon
        {--password=password : Contraseña de las cuentas ficticias de prueba}';

    protected $description = 'Crea Grecon demo con la agenda pública de octubre de 2026 (solo local/testing, sin reemplazar negocios)';

    private const SLUG = 'grecon-demo';

    private const ZONA = 'America/Mexico_City';

    public function handle(GestorDeConexionTenant $gestor, RegistrarEstudio $registrar): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Solo se permite en local o testing. No se crean cuentas demo en servidores publicados.');

            return self::FAILURE;
        }
        if ((string) $this->option('password') === '') {
            $this->error('La contraseña de prueba no puede estar vacía.');

            return self::FAILURE;
        }
        if (Estudio::query()->where('slug', self::SLUG)->exists()) {
            $this->warn('grecon-demo ya existe. No se modificó, duplicó ni borró ningún dato.');

            return self::SUCCESS;
        }

        // Se lee antes de crear nada: sin rastrear la web ni extrapolar semanas.
        $datos = json_decode(File::get(database_path('demo/grecon-octubre-2026.json')), true, flags: JSON_THROW_ON_ERROR);
        if (count($datos['sesiones'] ?? []) !== 191 || count($datos['instructores'] ?? []) !== 11 || count($datos['actividades'] ?? []) !== 26) {
            $this->error('La fuente de la demo está incompleta. No se creó el negocio.');

            return self::FAILURE;
        }

        $estudio = $registrar->ejecutar([
            'nombre' => 'Grecon Art House · DEMO NO OFICIAL', 'slug' => self::SLUG,
            'perfil_negocio' => PerfilNegocio::Pole->value,
            'contacto_nombre' => 'Administración', 'contacto_primer_apellido' => 'Demo',
            'contacto_email' => 'admin@grecon.test', 'zona_horaria' => self::ZONA,
        ]);
        // No se publica en directorios ni se enlaza con contactos reales.
        $estudio->update(['publicado' => false, 'privado' => true]);

        try {
            $gestor->aprovisionarBaseDeDatos($estudio);
            $gestor->ejecutarEn($estudio, fn () => DB::connection('tenant')->transaction(fn () => $this->sembrar($datos)));
            $estudio->update([
                'estado' => EstadoEstudio::Active->value,
                'estado_facturacion' => EstadoFacturacion::Active->value,
                'aprovisionado_en' => now(), 'paso_aprovisionamiento' => null,
                'onboarding_completo' => true, 'onboarding_pasos' => ['reglas' => true, 'publicacion' => false],
                'descripcion' => 'DEMO NO OFICIAL. Agenda pública de octubre de 2026; precios, cupos, sede, cuentas y reglas de cancelación ficticios. Sin cobros reales. Permanencia, penalización contractual, inscripción y anualidad automáticas NO implementadas.',
            ]);
        } catch (Throwable $e) {
            // Conserva el registro oculto para diagnóstico; nunca borra BD ajenas.
            $this->error('No se completó la demo. Quedó oculta y sin activar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Grecon demo creada: 191 sesiones (190 programadas, 1 cancelada), 26 clases y 11 instructores.');
        $this->line('Acceso directo: /entrar?estudio=grecon-demo. Consulta octubre de 2026 en la agenda.');
        $this->line('Cuentas ficticias: admin@grecon.test, paquete@grecon.test, ilimitada@grecon.test, vencido@grecon.test, sinplan@grecon.test.');
        $this->line('Instructor: instructor-1@grecon.test. Todas usan la contraseña indicada al ejecutar.');
        $this->warn('Los planes iniciales son concesiones de prueba de octubre, no ventas cobradas. Open Training se bloquea al paquete, pero su visibilidad aún no se filtra por membresía.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $datos */
    private function sembrar(array $datos): void
    {
        $organizacion = OrganizacionTenant::query()->create(['nombre' => 'Grecon · demo no oficial']);
        $sede = $organizacion->sucursales()->create([
            'nombre' => 'Sede de prueba (no confirmada)', 'zona_horaria' => self::ZONA, 'moneda' => 'MXN',
        ]);
        $admin = $this->usuario('admin', 'Administración Demo', 'propietario');
        $instructores = [];
        foreach ($datos['instructores'] as $i) {
            $usuario = $this->usuario($i['id'], $i['nombre'], 'instructor');
            $instructores[$i['id']] = $usuario->getKey();
            PersonaTenant::query()->create([
                'nombre' => $i['nombre'], 'email' => $usuario->email, 'usuario_id' => $usuario->getKey(),
                'tipo' => 'instructor', 'activo' => true, 'sucursal_id' => $sede->getKey(), 'recibe_promociones' => false,
            ]);
        }
        $programa = ProgramaTenant::query()->create(['nombre' => 'Agenda pública · octubre 2026', 'slug' => 'octubre-2026']);
        $ofertas = [];
        $regulares = [];
        foreach ($datos['actividades'] as $a) {
            $open = str_starts_with($a['nombre'], 'OPEN TRAINING');
            $actividad = $programa->actividades()->create(['nombre' => $a['nombre'], 'slug' => $a['id']]);
            $oferta = $actividad->ofertas()->create([
                'nombre' => $a['nombre'], 'modalidad' => ModalidadOfertaTenant::Grupal->value,
                'descripcion' => 'DEMO: nombre y horario públicos. Cupo ficticio.'.($open ? ' Reservable solo con el plan ilimitado de prueba.' : ''),
                'capacidad' => $open ? 12 : 8, 'duracion_minutos' => $a['duraciones_minutos'][0],
                'politica_reserva' => PoliticaReservaTenant::Entitlement->value,
            ]);
            $ofertas[$a['id']] = $oferta;
            if (! $open) {
                $regulares[] = (int) $oferta->getKey();
            }
        }
        foreach ($datos['sesiones'] as $s) {
            $oferta = $ofertas[$s['actividad_id']];
            SesionTenant::query()->create([
                'oferta_id' => $oferta->getKey(), 'sucursal_id' => $sede->getKey(),
                'instructor_id' => $s['instructor_id'] === null ? null : $instructores[$s['instructor_id']],
                'inicia_en' => CarbonImmutable::parse($s['fecha'].' '.$s['hora_inicio'], self::ZONA)->utc(),
                'termina_en' => CarbonImmutable::parse($s['fecha'].' '.$s['hora_fin'], self::ZONA)->utc(),
                'zona_horaria' => self::ZONA, 'capacidad' => $oferta->capacidad, 'tipo' => 'clase',
                'estado' => $s['estado_publicado'] === 'suspendida' ? 'cancelada' : 'programada',
            ]);
        }
        // Política de prueba explícita, no atribuida al establecimiento real.
        PoliticaCancelacionTenant::query()->create([
            'actividad_id' => null, 'horas_limite' => 6, 'penaliza_tarde' => true,
            'penaliza_no_show' => true, 'tolerancia_no_show' => 0,
        ]);
        $motor = app(MembresiasTenant::class);
        $planes = [];
        foreach ([4 => 80000, 8 => 140000, 12 => 180000] as $clases => $precio) {
            $planes[$clases] = $motor->crearProducto(
                "DEMO · {$clases} clases / mes calendario", TipoProducto::Paquete, $precio, 'MXN', false, $clases * 1000,
                vigencia: new VigenciaProducto(TipoVigencia::FinDeMes, 1), ofertaIds: $regulares,
            );
        }
        // Se limita a octubre y sin renovación automática para no generar cobros
        // ni simular que existe un contrato de permanencia que el motor no gestiona.
        $ilimitado = $motor->crearProducto(
            'DEMO · Ilimitada de octubre (sin permanencia automática)', TipoProducto::Membresia, 220000, 'MXN', true, null,
            vigencia: new VigenciaProducto(TipoVigencia::FinDeMes, 1),
            ofertaIds: array_values(array_map(static fn (OfertaTenant $o): int => (int) $o->getKey(), $ofertas)),
        );
        foreach (['paquete' => 'Alumna Paquete DEMO', 'ilimitada' => 'Alumna Ilimitada DEMO', 'vencido' => 'Alumna Vencida DEMO', 'sinplan' => 'Alumna Sin Plan DEMO'] as $clave => $nombre) {
            $usuario = $this->usuario($clave, $nombre, 'miembro');
            $persona = PersonaTenant::query()->create([
                'nombre' => $nombre, 'email' => $usuario->email, 'usuario_id' => $usuario->getKey(),
                'tipo' => 'miembro', 'activo' => true, 'sucursal_id' => $sede->getKey(), 'recibe_promociones' => false,
            ]);
            if ($clave !== 'sinplan') {
                $motor->venderProducto($persona, $clave === 'ilimitada' ? $ilimitado : $planes[8],
                    $clave === 'vencido' ? '2026-09-01' : '2026-10-01', $admin);
            }
        }
    }

    private function usuario(string $clave, string $nombre, string $rol): Usuario
    {
        return Usuario::query()->create([
            'name' => $nombre, 'nombre' => $nombre, 'email' => $clave.'@grecon.test',
            'password' => (string) $this->option('password'), 'rol' => $rol, 'roles' => [$rol], 'activo' => true,
        ]);
    }
}
