<?php

declare(strict_types=1);

namespace App\Console\Commands\Demos;

use App\Modules\Tenancy\Application\GenerarCargoRenta;
use App\Modules\Tenancy\Application\GestionarRolesTenant;
use App\Modules\Tenancy\Application\PlanCitasSaas;
use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\Exceptions\TipoCambioNoDisponible;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\OrigenCliente;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\Support\RedesSociales;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

/**
 * Lo común de los demos (`agendauno:sembrar-demos`): registra el negocio (o lo rehace
 * desde cero), crea las cuentas, da de alta personas creíbles y deja listo el reloj
 * para sembrar la historia día por día con los servicios del dominio (así órdenes,
 * pagos, créditos, asistencia y nómina quedan como si hubiera ocurrido).
 *
 * El azar tiene semilla fija (`Randomizer` con `Mt19937`): dos corridas dan los mismos
 * datos. Solo para desarrollo.
 */
abstract class DemoBase
{
    protected string $zona = 'America/Mexico_City';

    protected CarbonImmutable $hoy;

    /** La hora real al empezar: nada de lo sembrado queda después. */
    protected CarbonImmutable $ahora;

    protected CarbonImmutable $inicio;

    protected string $password = '';

    /** El negocio que se siembra (para guardar sus archivos y su renta). */
    protected Estudio $estudio;

    /** Azar con semilla fija (el nombre del negocio). */
    private Randomizer $aleatorio;

    /** @var array<string, int> lo que se sembró, para el resumen */
    protected array $cuenta = [];

    /** @var array<string, true> celulares ya usados (son únicos por negocio) */
    private array $celulares = [];

    /** @var array<string, true> correos ya usados */
    private array $correos = [];

    public function __construct(
        protected readonly GestorDeConexionTenant $gestor,
        protected readonly RegistrarEstudio $registrar,
    ) {}

    /**
     * Nombre, slug, giro, contacto (dueño), descripción, redes, color del logo y, si
     * va fuera del directorio, `privado` (solo con enlace).
     *
     * @return array{nombre: string, slug: string, perfil: PerfilNegocio, contacto: array{0: string, 1: string, 2: string}, email: string, telefono: string, descripcion: string, instagram: string, color: array{0: int, 1: int, 2: int}, privado?: bool}
     */
    abstract protected function datos(): array;

    /**
     * Siembra todo dentro de la BD del negocio (ya conectada).
     */
    abstract protected function sembrar(): void;

    /**
     * Cuentas para entrar a revisar (correo => qué es).
     *
     * @return array<string, string>
     */
    abstract public function cuentas(): array;

    /**
     * @return array{estudio: Estudio, cuenta: array<string, int>}
     */
    public function ejecutar(string $password, int $dias, bool $rehacer, ?CarbonImmutable $ahora = null): array
    {
        $this->password = $password;
        Carbon::setTestNow();
        // La hora real; las pruebas fijan otra para no depender del día en que corren.
        $this->ahora = ($ahora ?? CarbonImmutable::now())->setTimezone($this->zona);
        $this->hoy = $this->ahora->startOfDay();
        $this->inicio = $this->hoy->subDays($dias);
        $d = $this->datos();
        $this->aleatorio = new Randomizer(new Mt19937(crc32($d['slug'])));

        $estudio = Estudio::query()->where('slug', $d['slug'])->first();
        if ($estudio instanceof Estudio) {
            if (! $rehacer) {
                throw new RuntimeException("El demo «{$d['slug']}» ya existe; usa --rehacer para sembrarlo desde cero.");
            }
            $this->borrarBaseDeDatos($estudio);
        }

        [$nombre, $apellido1, $apellido2] = $d['contacto'];
        $estudio ??= $this->registrar->ejecutar([
            'nombre' => $d['nombre'],
            'slug' => $d['slug'],
            'perfil_negocio' => $d['perfil']->value,
            'contacto_nombre' => $nombre,
            'contacto_primer_apellido' => $apellido1,
            'contacto_segundo_apellido' => $apellido2,
            'contacto_email' => $d['email'],
            'contacto_telefono' => $d['telefono'],
            'pais' => 'MX',
            'ciudad' => 'Ciudad de México',
            'zona_horaria' => $this->zona,
        ]);
        $this->gestor->aprovisionarBaseDeDatos($estudio);

        // Un negocio que ya opera: activo, al corriente, publicado y sin el asistente.
        // Si el negocio ya existía (se rehace), toma el nombre y el contacto del demo.
        $estudio->update([
            'nombre' => $d['nombre'],
            'contacto_nombre' => $nombre,
            'contacto_primer_apellido' => $apellido1,
            'contacto_segundo_apellido' => $apellido2,
            'contacto_email' => $d['email'],
            'contacto_telefono' => $d['telefono'],
            'perfil_negocio' => $d['perfil']->value,
            'estado' => EstadoEstudio::Active->value,
            'estado_facturacion' => EstadoFacturacion::Active->value,
            'trial_inicia_en' => $this->inicio->subDays(30)->toDateString(),
            'trial_termina_en' => $this->inicio->toDateString(),
            'aprovisionado_en' => $this->inicio->subDays(30),
            'paso_aprovisionamiento' => null,
            'onboarding_completo' => true,
            // Sus reglas ya están revisadas y la página publicada (ADR 0090).
            'onboarding_pasos' => ['reglas' => true, 'publicacion' => true],
            'publicado' => true,
            'privado' => $d['privado'] ?? false,
            'pais' => 'MX',
            'ciudad' => 'Ciudad de México',
            'descripcion' => $d['descripcion'],
            'redes' => RedesSociales::normalizar(['instagram' => $d['instagram']]),
        ]);
        // Rehecho desde cero (sin sesiones): su modalidad es la de su giro (ADR 0104).
        $estudio->forceFill(['modalidad' => ModalidadServicio::paraPerfil($d['perfil'])])->save();
        $this->logo($estudio, $d['color']);
        $this->estudio = $estudio;

        try {
            $this->gestor->ejecutarEn($estudio, function () use ($estudio): void {
                // Solo mientras se siembra: sin esperar al disco en cada escritura.
                if ($estudio->db_driver === 'sqlite') {
                    DB::connection('tenant')->statement('PRAGMA synchronous = OFF');
                    DB::connection('tenant')->statement('PRAGMA journal_mode = MEMORY');
                }
                // Sus reglas de cancelación: avisar con tiempo; la inasistencia cuenta.
                PoliticaCancelacionTenant::query()->updateOrCreate(['actividad_id' => null], [
                    'horas_limite' => 6, 'penaliza_tarde' => true, 'penaliza_no_show' => true, 'tolerancia_no_show' => 0,
                ]);
                $this->sembrar();
                $this->cerrarHistoria();
            });
            $this->rentaDeLaPlataforma($estudio->refresh());
        } finally {
            Carbon::setTestNow();
        }

        return ['estudio' => $estudio->refresh(), 'cuenta' => $this->cuenta];
    }

    /**
     * Lo que el negocio paga a la plataforma: el cargo de cada mes ya cerrado de la
     * historia (con su medición de uso), emitido al cerrar el mes y pagado a los pocos
     * días. Un cargo ya emitido no se recalcula.
     */
    private function rentaDeLaPlataforma(Estudio $estudio): void
    {
        $generar = app(GenerarCargoRenta::class);
        for ($mes = $this->inicio->startOfMonth(); $mes->lessThan($this->hoy->startOfMonth()); $mes = $mes->addMonth()) {
            Carbon::setTestNow($mes->addMonth()->setTime(6, 0)->utc());
            // Los meses que su plan de citas cubre por adelantado no se cobran vencidos.
            if ($generar->porAdelantado($estudio, $mes->format('Y-m'))) {
                continue;
            }
            try {
                $cargo = $generar->paraEstudio($estudio, $mes->format('Y-m'));
            } catch (TipoCambioNoDisponible) {
                // Sin tipo de cambio en desarrollo: el mes queda sin cargo.
                continue;
            }
            if ($cargo->estado === EstadoCargoRenta::Pendiente) {
                $cargo->update([
                    'estado' => EstadoCargoRenta::Pagado->value, 'metodo_pago' => 'stripe',
                    'pagado_en' => $mes->addMonth()->addDays(3)->setTime(11, 20)->utc(),
                ]);
            }
            $this->sumar('cargos de renta pagados');
        }
        Carbon::setTestNow();

        // Un negocio de citas con plan: el periodo en curso, cobrado por adelantado y pagado.
        $planes = app(PlanCitasSaas::class);
        if ($planes->aplica($estudio)) {
            try {
                foreach ($planes->emitirPendientes($estudio->refresh()) as $cargo) {
                    if ($cargo->estado === EstadoCargoRenta::Pendiente) {
                        $cargo->update(['estado' => EstadoCargoRenta::Pagado->value, 'metodo_pago' => 'stripe', 'pagado_en' => now()]);
                    }
                    $this->sumar('cargos de renta pagados');
                }
            } catch (TipoCambioNoDisponible) {
                // Sin tipo de cambio en desarrollo: el plan se cobra cuando lo haya.
            }
        }
    }

    /** Rehacer desde cero: borra la BD del negocio (solo SQLite de desarrollo). */
    private function borrarBaseDeDatos(Estudio $estudio): void
    {
        if ($estudio->db_driver !== 'sqlite') {
            throw new RuntimeException('Solo se rehacen demos con la BD en SQLite (desarrollo).');
        }
        $this->gestor->desconectar();
        $ruta = (string) $this->gestor->configuracion($estudio)['database'];
        if (File::exists($ruta)) {
            File::delete($ruta);
        }
    }

    // ---------------------------------------------------------------- reloj y azar

    /** Pone el reloj en una fecha y hora LOCALES del negocio. */
    protected function reloj(CarbonImmutable $dia, string $hora = '07:00'): CarbonImmutable
    {
        return $this->en(CarbonImmutable::parse($dia->toDateString().' '.$hora, $this->zona));
    }

    /**
     * Pone el reloj en un instante; si cae después de la hora real (algo de hoy que
     * aún no pasa), lo pasa a unos minutos antes de ahora.
     */
    /**
     * Un momento de los últimos días para algo hecho «antes de hoy» (apartar una
     * cita o una clase futura), nunca antes del alta de la persona.
     */
    protected function hace(int $minimo, int $maximo, ?\DateTimeInterface $alta): CarbonImmutable
    {
        $momento = $this->ahora->subMinutes($this->aleatorio->getInt($minimo, $maximo));
        if ($alta !== null && $momento->lessThan($alta)) {
            $momento = CarbonImmutable::instance($alta)->addMinutes($this->aleatorio->getInt(5, 30));
        }

        return $this->en($momento);
    }

    protected function en(CarbonImmutable $instante): CarbonImmutable
    {
        if ($instante->greaterThan($this->ahora)) {
            $instante = $this->ahora->subMinutes($this->aleatorio->getInt(3, 60));
        }
        Carbon::setTestNow($instante->utc());

        return $instante->setTimezone($this->zona);
    }

    protected function azar(int $min, int $max): int
    {
        return $this->aleatorio->getInt($min, $max);
    }

    /**
     * La lista en otro orden (con la misma semilla).
     *
     * @template T
     *
     * @param  list<T>  $lista
     * @return list<T>
     */
    protected function mezclar(array $lista): array
    {
        return $this->aleatorio->shuffleArray($lista);
    }

    /** Verdadero con probabilidad `$porciento`. */
    protected function prob(int $porciento): bool
    {
        return $this->aleatorio->getInt(1, 100) <= $porciento;
    }

    /**
     * Uno al azar, con pesos: ['efectivo' => 50, 'manual' => 30, ...].
     *
     * @template T of array-key
     *
     * @param  array<T, int>  $pesos
     * @return T
     */
    protected function elegir(array $pesos): int|string
    {
        $tiro = $this->aleatorio->getInt(1, max(1, array_sum($pesos)));
        foreach ($pesos as $valor => $peso) {
            $tiro -= $peso;
            if ($tiro <= 0) {
                return $valor;
            }
        }

        return array_key_first($pesos);
    }

    /**
     * @template T
     *
     * @param  list<T>  $lista
     * @return T
     */
    protected function uno(array $lista): mixed
    {
        return $lista[$this->aleatorio->getInt(0, count($lista) - 1)];
    }

    protected function sumar(string $que, int $n = 1): void
    {
        $this->cuenta[$que] = ($this->cuenta[$que] ?? 0) + $n;
    }

    // ---------------------------------------------------------------- personas

    /**
     * Cuenta del equipo (o de un cliente con acceso), activa y con la contraseña del demo.
     *
     * @param  list<string>  $roles
     */
    protected function usuario(string $email, string $nombre, string $apellido, array $roles): Usuario
    {
        return Usuario::query()->updateOrCreate(['email' => $email], [
            'name' => "{$nombre} {$apellido}",
            'nombre' => $nombre,
            'primer_apellido' => $apellido,
            'rol' => $roles[0],
            'roles' => $roles,
            'activo' => true,
            'password' => $this->password,
            'activation_token' => null,
        ]);
    }

    /**
     * Rol propio del negocio (ADR 0057) creado por el dueño; si sus permisos no se
     * pueden dar, se avisa en lugar de inventar otro.
     *
     * @param  list<string>  $permisos
     */
    protected function rolPropio(Usuario $dueno, string $nombre, array $permisos): ?string
    {
        try {
            return (string) app(GestionarRolesTenant::class)->crear($dueno, $nombre, $permisos)->clave;
        } catch (Throwable $e) {
            $this->cuenta["rol no creado: {$nombre}"] = 1;

            return null;
        }
    }

    /**
     * Da de alta a un cliente o alumna con datos creíbles, con su fecha de alta. El
     * género va en `$extra` (lo sabe quien elige el nombre).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function persona(string $nombre, string $apellido1, string $apellido2, CarbonImmutable $alta, ?int $sucursalId, bool $conCorreo, array $extra = []): PersonaTenant
    {
        $correo = null;
        if ($conCorreo) {
            $base = NombresDemo::ascii($nombre).'.'.NombresDemo::ascii($apellido1);
            $correo = $base.'@correo.test';
            for ($n = 2; isset($this->correos[$correo]); $n++) {
                $correo = "{$base}{$n}@correo.test";
            }
            $this->correos[$correo] = true;
        }

        // Casi todos dieron su fecha de nacimiento (adultos).
        $nacimiento = $this->prob(85) ? $this->hoy->subYears($this->azar(18, 56))->subDays($this->azar(0, 364))->toDateString() : null;
        $persona = PersonaTenant::query()->create([
            'fecha_nacimiento' => $nacimiento,
            'nombre' => $nombre,
            'primer_apellido' => $apellido1,
            'segundo_apellido' => $apellido2,
            'email' => $correo,
            'celular' => $this->celular(),
            'tipo' => TipoPersonaTenant::Miembro->value,
            'activo' => true,
            'es_facturable' => true,
            'archivado' => false,
            'sucursal_id' => $sucursalId,
            'como_nos_conocio' => $this->elegir([
                OrigenCliente::Recomendacion->value => 35, OrigenCliente::Instagram->value => 25,
                OrigenCliente::Google->value => 15, OrigenCliente::PasoPorAqui->value => 12,
                OrigenCliente::Facebook->value => 8, OrigenCliente::Tiktok->value => 5,
            ]),
            'whatsapp_aceptado_en' => $this->prob(55) ? $alta->utc() : null,
            ...$extra,
        ]);
        // Nadie se da de alta en el futuro (un alta de hoy, más tarde que ahora).
        if ($alta->greaterThan($this->ahora)) {
            $alta = $this->ahora->subMinutes($this->aleatorio->getInt(30, 240));
        }
        $persona->forceFill(['created_at' => $alta->utc(), 'updated_at' => $alta->utc()])->saveQuietly();
        $this->sumar('personas');

        return $persona;
    }

    /** Celular de 10 dígitos de la CDMX (55), único en el negocio. */
    private function celular(): string
    {
        do {
            $numero = '55'.$this->azar(1000, 9999).$this->azar(1000, 9999);
        } while (isset($this->celulares[$numero]));
        $this->celulares[$numero] = true;

        return $numero;
    }

    // ---------------------------------------------------------------- cierre

    /**
     * Lo que la historia dejó por avisar (confirmaciones, recordatorios, puntos) ya
     * pasó: no se envía.
     */
    private function cerrarHistoria(): void
    {
        Carbon::setTestNow();
        EventoOutboxTenant::query()
            ->whereNull('publicado_en')
            ->where('ocurrido_en', '<=', $this->ahora->utc())
            ->update(['publicado_en' => $this->ahora->utc()]);
    }

    /**
     * Logo sencillo (anillo sobre el color del negocio) con GD; si no hay GD, se omite.
     *
     * @param  array{0: int, 1: int, 2: int}  $color
     */
    private function logo(Estudio $estudio, array $color): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return;
        }
        $img = imagecreatetruecolor(256, 256);
        $fondo = (int) imagecolorallocate($img, ...$color);
        $blanco = (int) imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, 256, 256, $fondo);
        imagefilledellipse($img, 128, 128, 156, 156, $blanco);
        imagefilledellipse($img, 128, 128, 104, 104, $fondo);
        imagefilledrectangle($img, 118, 60, 138, 196, $blanco);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        $ruta = 'estudios/'.$estudio->getKey().'/logo.png';
        Storage::disk('public')->put($ruta, $png);
        $estudio->update(['logo_url' => Storage::disk('public')->url($ruta)]);
    }
}
