<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\EscenariosConcurrencia;
use App\Modules\Platform\Operacion\VolcadoBaseDatos;
use App\Modules\Tenancy\Application\AprovisionarEstudio;
use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Application\RespaldosEstudio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Comprueba en MySQL lo que las pruebas (SQLite) no pueden: SQLite ignora los
 * candados de fila y hace una escritura a la vez, así que ahí nunca hay carreras.
 * Crea un negocio temporal con su base MySQL, lanza procesos que hacen a la vez la
 * misma operación real (reservar el último lugar, agendar el mismo hueco, cancelar y
 * reprogramar), revisa que el resultado cuadre, prueba migrar varios negocios y
 * respaldar/restaurar uno, y al final borra todo. Sirve en el servidor antes de abrir
 * y en CI; sale con error si algo no cuadra.
 */
class VerificarConcurrencia extends Command
{
    use ConfirmableTrait;

    protected $signature = 'agendauno:verificar-concurrencia
        {--base= : Base MySQL existente y VACÍA para el negocio temporal (se vacía al terminar). Sin ella se crea y se borra una base por negocio}
        {--rondas=3 : Veces que se repite cada caso}
        {--procesos=6 : Procesos que compiten en cada ronda}
        {--force : Correr en producción sin confirmar}
        {--paso= : (interno) la operación de un proceso hijo}';

    protected $description = 'Comprueba en MySQL reservas, citas, cancelaciones, migraciones y respaldos con procesos simultáneos';

    /** Prefijo de las bases que crea (y solo esas borra). */
    private const PREFIJO_BASE = 'tenant_verificacion_';

    /** Negocios extra del caso de migraciones (cuando puede crear bases). */
    private const NEGOCIOS_MIGRACION = 3;

    /** Migraciones que se deshacen y se vuelven a aplicar. */
    private const MIGRACIONES_A_REHACER = 3;

    /** @var list<Estudio> */
    private array $temporales = [];

    private Estudio $principal;

    /** @var array{sucursal: int, clase: int, servicio: int, profesionales: list<int>} */
    private array $base;

    /** Segundos entre lanzar los procesos y la señal de salida común. */
    private float $espera = 3.0;

    private int $fallas = 0;

    /** Solo se vacía al final la base prestada que se comprobó vacía al empezar. */
    private bool $basePrestadaVacia = false;

    public function handle(GestorDeConexionTenant $gestor, EscenariosConcurrencia $escenarios): int
    {
        if (is_string($this->option('paso'))) {
            return $this->hijo($escenarios, $this->option('paso'));
        }
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $base = $this->option('base');
        if (is_string($base) && preg_match('/^[A-Za-z0-9_]+$/', $base) !== 1) {
            $this->error('El nombre de la base solo admite letras, números y guion bajo.');

            return self::FAILURE;
        }
        $rondas = max(1, (int) $this->option('rondas'));
        $procesos = max(2, (int) $this->option('procesos'));
        config(['agendauno.tenant_db_driver' => 'mysql']);

        try {
            $this->principal = $this->nuevoEstudio();
            if (is_string($base)) {
                $this->principal->update(['db_database' => $base]);
                if (! $this->baseVacia($gestor, $this->principal)) {
                    $this->error("La base {$base} no existe o tiene tablas: usa una base vacía.");

                    return self::FAILURE;
                }
                $this->basePrestadaVacia = true;
            }
            app(AprovisionarEstudio::class)->ejecutar($this->principal);
            // Fuera del directorio y de los procesos programados mientras dura.
            $this->principal->update(['estado' => EstadoEstudio::Suspended->value]);

            $gestor->conectar($this->principal);
            $motor = (array) DB::connection('tenant')->selectOne('SELECT VERSION() AS version, @@transaction_isolation AS aislamiento');
            $this->line("Negocio temporal {$this->principal->slug} en {$this->principal->db_database} (MySQL {$motor['version']}, aislamiento {$motor['aislamiento']}).");
            $this->base = $escenarios->prepararBase();
            $this->calibrar();

            $this->caso('Dos personas por el último lugar de una clase', fn () => $this->ultimoLugar($escenarios, $rondas, $procesos));
            $this->caso('Reservas a la vez con el último crédito del paquete', fn () => $this->ultimoCredito($escenarios, $rondas));
            $this->caso('Mismo profesional y horario (agendar y reprogramar)', fn () => $this->mismaCita($escenarios, $rondas, $procesos));
            $this->caso('Misma hora con cualquier profesional disponible', fn () => $this->cualquierProfesional($escenarios, $rondas, $procesos));
            $this->caso('Cancelar y reprogramar la misma reserva a la vez', fn () => $this->cancelarYReprogramar($escenarios, $rondas));
            $this->caso('Reprogramaciones y reservas por el último lugar', fn () => $this->reprogramarAlUltimoLugar($escenarios, $rondas, $procesos));
            $this->caso('Una cancelación libera lugar mientras otros lo piden', fn () => $this->cancelacionLiberaLugar($escenarios, $rondas));
            $this->caso('Respaldo y restauración de un negocio', fn () => $this->respaldoYRestauracion($gestor));
            $this->caso('Migraciones de varios negocios', fn () => $this->migraciones($gestor, is_string($base)));
        } catch (Throwable $e) {
            $this->error('No se pudo preparar la verificación: '.$e->getMessage());
            $this->fallas++;
        } finally {
            $this->limpiar($gestor, is_string($base) ? $base : null);
        }

        $this->newLine();
        if ($this->fallas > 0) {
            $this->error("{$this->fallas} caso(s) no cuadraron.");

            return self::FAILURE;
        }
        $this->info('Todo cuadró con procesos simultáneos en MySQL.');

        return self::SUCCESS;
    }

    /**
     * Varios alumnos reservan a la vez una clase con 1 (o 3) lugares: entran
     * exactamente los que caben y a nadie se le aparta crédito de más.
     *
     * @return list<string>
     */
    private function ultimoLugar(EscenariosConcurrencia $e, int $rondas, int $procesos): array
    {
        $fallas = [];
        for ($r = 0; $r < $rondas; $r++) {
            $cupo = $r % 2 === 0 ? 1 : 3;
            $sesion = $e->sesion($this->base['clase'], $this->base['sucursal'], $cupo, $r);
            $alumnos = collect(range(1, $procesos))->map(fn (): PersonaTenant => $e->alumno(10));
            $res = $this->competir($alumnos->map(fn (PersonaTenant $p): array => [
                'accion' => 'reservar', 'sesion' => $sesion->getKey(), 'persona' => $p->getKey(),
            ])->all());

            $esperadas = min($cupo, $procesos);
            $ocupadas = $e->ocupadas((int) $sesion->getKey());
            $this->comprobar($fallas, $ocupadas === $esperadas, "cupo {$cupo}: {$ocupadas} con lugar (debían ser {$esperadas})");
            $this->comprobar($fallas, $this->hechos($res) === $esperadas, "cupo {$cupo}: {$this->hechos($res)} procesos dicen haber reservado");
            foreach ($alumnos as $p) {
                foreach ($e->creditosCuadran($p) as $f) {
                    $fallas[] = "cupo {$cupo}: {$f}";
                }
            }
            $this->inesperados($fallas, $res);
            $this->detalle('ronda '.($r + 1).": cupo {$cupo}, {$procesos} a la vez → {$ocupadas} con lugar", $res);
        }

        return $fallas;
    }

    /**
     * Un alumno con UN crédito reserva cuatro clases distintas a la vez: solo una
     * debe entrar (el paquete nunca queda en negativo).
     *
     * @return list<string>
     */
    private function ultimoCredito(EscenariosConcurrencia $e, int $rondas): array
    {
        $fallas = [];
        for ($r = 0; $r < $rondas; $r++) {
            $alumno = $e->alumno(1);
            $sesiones = collect(range(0, 3))->map(fn (int $k) => $e->sesion($this->base['clase'], $this->base['sucursal'], 10, 10 + $r, 7 + $k * 2));
            $res = $this->competir($sesiones->map(fn ($s): array => [
                'accion' => 'reservar', 'sesion' => $s->getKey(), 'persona' => $alumno->getKey(),
            ])->all());

            $conLugar = ReservaTenant::query()->where('persona_id', $alumno->getKey())
                ->where('estado', EstadoReserva::Confirmada->value)->count();
            $this->comprobar($fallas, $conLugar === 1, "{$conLugar} reservas con un solo crédito");
            foreach ($e->creditosCuadran($alumno) as $f) {
                $fallas[] = $f;
            }
            $this->inesperados($fallas, $res);
            $this->detalle('ronda '.($r + 1).": 4 reservas a la vez con 1 crédito → {$conLugar} entró", $res);
        }

        return $fallas;
    }

    /**
     * Varias solicitudes por el mismo profesional en horarios que se enciman (y, en
     * rondas alternas, reprogramaciones de otras citas hacia ese hueco): solo una
     * cita puede quedar.
     *
     * @return list<string>
     */
    private function mismaCita(EscenariosConcurrencia $e, int $rondas, int $procesos): array
    {
        $fallas = [];
        $desfases = [0, 0, 10, -10, 5, -5, 0, 10, -10, 5];
        for ($r = 0; $r < $rondas; $r++) {
            $profesional = $this->base['profesionales'][$r % 2];
            $hueco = $e->hueco(20 + $r, 10);
            $pasos = [];
            $moviendo = $r % 2 === 1 ? 2 : 0;
            for ($k = 0; $k < $moviendo; $k++) {
                $otra = $e->cita($this->base['servicio'], $this->base['sucursal'], $e->alumno(0), $profesional, $hueco->addHours(3 + $k));
                $pasos[] = ['accion' => 'mover-cita', 'reserva' => $otra->getKey(), 'inicia' => $hueco->toIso8601String()];
            }
            for ($k = count($pasos); $k < $procesos; $k++) {
                $pasos[] = [
                    'accion' => 'agendar', 'servicio' => $this->base['servicio'], 'sucursal' => $this->base['sucursal'],
                    'persona' => $e->alumno(0)->getKey(), 'profesional' => $profesional,
                    'inicia' => $hueco->addMinutes($desfases[$k % count($desfases)])->toIso8601String(),
                ];
            }
            $res = $this->competir($pasos);

            // Todas las propuestas se enciman entre sí (±10 min en citas de 30).
            $encimadas = $e->citasEncimadas($profesional, $hueco->subMinutes(10), $hueco->addMinutes(40));
            $this->comprobar($fallas, $encimadas === 1, "{$encimadas} citas encimadas del mismo profesional");
            $this->inesperados($fallas, $res);
            $tipo = $moviendo > 0 ? " ({$moviendo} reprogramando)" : '';
            $this->detalle('ronda '.($r + 1).": {$procesos} solicitudes{$tipo} → {$encimadas} cita", $res);
        }

        return $fallas;
    }

    /**
     * Varios clientes piden la misma hora con «cualquier profesional»: cada uno de los
     * dos profesionales queda con una cita (no dos encimadas) y los demás clientes
     * reciben que ya no hay nadie libre.
     *
     * @return list<string>
     */
    private function cualquierProfesional(EscenariosConcurrencia $e, int $rondas, int $procesos): array
    {
        $fallas = [];
        $e->abrirAtencion($this->base['sucursal'], $this->base['profesionales']);
        for ($r = 0; $r < $rondas; $r++) {
            $hueco = $e->hueco(60 + $r, 10);
            $res = $this->competir(collect(range(1, $procesos))->map(fn (): array => [
                'accion' => 'agendar-cualquiera', 'servicio' => $this->base['servicio'], 'sucursal' => $this->base['sucursal'],
                'persona' => $e->alumno(0)->getKey(), 'inicia' => $hueco->toIso8601String(),
            ])->all());

            $esperadas = min(count($this->base['profesionales']), $procesos);
            $porProfesional = array_map(
                fn (int $p): int => $e->citasEncimadas($p, $hueco, $hueco->addMinutes(30)),
                $this->base['profesionales'],
            );
            $total = array_sum($porProfesional);
            $this->comprobar($fallas, max($porProfesional) <= 1, 'un profesional quedó con citas encimadas ('.implode(' + ', $porProfesional).')');
            $this->comprobar($fallas, $total === $esperadas, "{$total} citas a esa hora (debían ser {$esperadas})");
            $this->comprobar($fallas, $this->hechos($res) === $esperadas, "{$this->hechos($res)} procesos dicen haber agendado");
            $this->inesperados($fallas, $res);
            $this->detalle('ronda '.($r + 1).": {$procesos} clientes a la misma hora → ".implode(' + ', $porProfesional).' citas', $res);
        }

        return $fallas;
    }

    /**
     * La misma reserva se cancela y se reprograma a la vez: termina cancelada (en
     * una u otra fecha), nunca "viva" tras una cancelación exitosa, y su crédito
     * vuelve.
     *
     * @return list<string>
     */
    private function cancelarYReprogramar(EscenariosConcurrencia $e, int $rondas): array
    {
        $fallas = [];
        $reservas = app(ReservasTenant::class);
        for ($r = 0; $r < $rondas * 2; $r++) {
            $origen = $e->sesion($this->base['clase'], $this->base['sucursal'], 10, 30 + $r, 17);
            $destino = $e->sesion($this->base['clase'], $this->base['sucursal'], 10, 30 + $r, 21);
            $alumno = $e->alumno(10);
            $reserva = $reservas->crear($origen, $alumno);
            $res = $this->competir([
                ['accion' => 'cancelar', 'reserva' => $reserva->getKey()],
                ['accion' => 'mover-clase', 'reserva' => $reserva->getKey(), 'sesion' => $destino->getKey()],
            ]);

            $final = $reserva->refresh();
            $enUso = $e->ocupadas((int) $origen->getKey()) + $e->ocupadas((int) $destino->getKey());
            $this->comprobar($fallas, $final->estado === EstadoReserva::Cancelada, "la reserva quedó {$final->estado->value} tras cancelarla");
            $this->comprobar($fallas, $enUso === 0, "{$enUso} lugar(es) ocupados tras cancelar");
            foreach ($e->creditosCuadran($alumno) as $f) {
                $fallas[] = $f;
            }
            $this->inesperados($fallas, $res);
            $orden = $res[1]['hecho'] ? 'se reprogramó y luego se canceló' : 'se canceló antes de reprogramarse';
            $this->detalle('ronda '.($r + 1).": {$orden}", $res);
        }

        return $fallas;
    }

    /**
     * Varias reservas de otra fecha se mueven a una clase con un lugar mientras dos
     * alumnos la reservan directo: solo uno ocupa el lugar.
     *
     * @return list<string>
     */
    private function reprogramarAlUltimoLugar(EscenariosConcurrencia $e, int $rondas, int $procesos): array
    {
        $fallas = [];
        $reservas = app(ReservasTenant::class);
        for ($r = 0; $r < $rondas; $r++) {
            $destino = $e->sesion($this->base['clase'], $this->base['sucursal'], 1, 40 + $r, 21);
            $origen = $e->sesion($this->base['clase'], $this->base['sucursal'], 10, 40 + $r, 17);
            $alumnos = [];
            $pasos = [];
            for ($k = 0; $k < $procesos; $k++) {
                $alumnos[] = $alumno = $e->alumno(10);
                $pasos[] = $k < $procesos - 2
                    ? ['accion' => 'mover-clase', 'reserva' => $reservas->crear($origen, $alumno)->getKey(), 'sesion' => $destino->getKey()]
                    : ['accion' => 'reservar', 'sesion' => $destino->getKey(), 'persona' => $alumno->getKey()];
            }
            $res = $this->competir($pasos);

            $ocupadas = $e->ocupadas((int) $destino->getKey());
            $this->comprobar($fallas, $ocupadas === 1, "{$ocupadas} con lugar en una clase de 1");
            foreach ($alumnos as $a) {
                foreach ($e->creditosCuadran($a) as $f) {
                    $fallas[] = $f;
                }
            }
            $this->inesperados($fallas, $res);
            $this->detalle('ronda '.($r + 1).': '.($procesos - 2)." reprogramaciones + 2 reservas → {$ocupadas} con lugar", $res);
        }

        return $fallas;
    }

    /**
     * Clase llena (2 de 2): una alumna cancela mientras dos reprogramaciones y dos
     * reservas piden lugar. Nunca más de 2 adentro y cada crédito en su sitio.
     *
     * @return list<string>
     */
    private function cancelacionLiberaLugar(EscenariosConcurrencia $e, int $rondas): array
    {
        $fallas = [];
        $reservas = app(ReservasTenant::class);
        for ($r = 0; $r < $rondas; $r++) {
            $destino = $e->sesion($this->base['clase'], $this->base['sucursal'], 2, 50 + $r, 21);
            $origen = $e->sesion($this->base['clase'], $this->base['sucursal'], 10, 50 + $r, 17);
            $alumnos = collect(range(1, 6))->map(fn (): PersonaTenant => $e->alumno(10))->all();
            $sale = $reservas->crear($destino, $alumnos[0]);
            $reservas->crear($destino, $alumnos[1]);
            $res = $this->competir([
                ['accion' => 'cancelar', 'reserva' => $sale->getKey()],
                ['accion' => 'mover-clase', 'reserva' => $reservas->crear($origen, $alumnos[2])->getKey(), 'sesion' => $destino->getKey()],
                ['accion' => 'mover-clase', 'reserva' => $reservas->crear($origen, $alumnos[3])->getKey(), 'sesion' => $destino->getKey()],
                ['accion' => 'reservar', 'sesion' => $destino->getKey(), 'persona' => $alumnos[4]->getKey()],
                ['accion' => 'reservar', 'sesion' => $destino->getKey(), 'persona' => $alumnos[5]->getKey()],
            ]);

            $ocupadas = $e->ocupadas((int) $destino->getKey());
            $this->comprobar($fallas, $ocupadas <= 2, "{$ocupadas} con lugar en una clase de 2");
            $this->comprobar($fallas, $this->hechos(array_slice($res, 1)) <= 1, $this->hechos(array_slice($res, 1)).' entraron al único lugar liberado');
            foreach ($alumnos as $a) {
                foreach ($e->creditosCuadran($a) as $f) {
                    $fallas[] = $f;
                }
            }
            $this->inesperados($fallas, $res);
            $this->detalle('ronda '.($r + 1).": cancelación + 4 por el lugar → {$ocupadas} de 2", $res);
        }

        return $fallas;
    }

    /**
     * Respalda el negocio (con todo lo que dejaron los casos anteriores), lo cambia,
     * lo restaura y compara tabla por tabla con lo respaldado.
     *
     * @return list<string>
     */
    private function respaldoYRestauracion(GestorDeConexionTenant $gestor): array
    {
        $fallas = [];
        $respaldos = app(RespaldosEstudio::class);
        $antes = $this->sumasDeTablas();
        $ruta = $respaldos->respaldar($this->principal);
        $this->comprobar($fallas, app(VolcadoBaseDatos::class)->disco()->exists($ruta.'.sha256'), 'el respaldo no tiene su suma de verificación');

        $gestor->conectar($this->principal);
        DB::connection('tenant')->table('personas')->update(['nombre' => 'Cambiado después del respaldo']);
        PersonaTenant::query()->create(['nombre' => 'Creada después del respaldo', 'tipo' => 'miembro', 'activo' => true]);
        $this->comprobar($fallas, $this->sumasDeTablas() !== $antes, 'el cambio de prueba no alteró la base');

        $respaldos->restaurar($this->principal, $ruta);
        $gestor->conectar($this->principal);
        $despues = $this->sumasDeTablas();
        $distintas = array_keys(array_filter($antes, fn (?string $suma, string $tabla): bool => ($despues[$tabla] ?? null) !== $suma, ARRAY_FILTER_USE_BOTH));
        $this->comprobar($fallas, $distintas === [] && count($despues) === count($antes), 'tablas distintas tras restaurar: '.implode(', ', $distintas));
        $this->detalle(count($antes).' tablas respaldadas, cambiadas y restauradas; '.(count($antes) - count($distintas)).' idénticas a lo respaldado', []);

        return $fallas;
    }

    /**
     * Negocios nuevos se aprovisionan a la vez (crear base + migrar); luego a todos
     * se les deshacen las últimas migraciones y se migran a la vez, como en una
     * actualización. Todos deben quedar en la última versión y con el mismo esquema.
     *
     * @return list<string>
     */
    private function migraciones(GestorDeConexionTenant $gestor, bool $unaBase): array
    {
        $fallas = [];
        $archivos = collect(File::files(database_path('migrations/tenant')))
            ->map(fn ($f): string => $f->getFilenameWithoutExtension())->sort()->values();
        $ultima = (string) $archivos->last();

        $nuevos = [];
        if (! $unaBase) {
            $nuevos = array_map(fn (): Estudio => $this->nuevoEstudio(), range(1, self::NEGOCIOS_MIGRACION));
            $res = $this->competir(array_map(fn (Estudio $n): array => ['accion' => 'aprovisionar', 'estudio' => $n->getKey()], $nuevos), conectar: false);
            $this->inesperados($fallas, $res);
            foreach ($nuevos as $n) {
                $n->update(['estado' => EstadoEstudio::Suspended->value]);
            }
            $this->detalle(count($nuevos).' negocios aprovisionados a la vez', $res);
        }

        $todos = [$this->principal, ...$nuevos];
        foreach ($todos as $n) {
            $gestor->ejecutarEn($n, fn () => Artisan::call('migrate:rollback', [
                '--database' => 'tenant', '--path' => 'database/migrations/tenant',
                '--step' => self::MIGRACIONES_A_REHACER, '--force' => true,
            ]));
        }
        $res = $this->competir(array_map(fn (Estudio $n): array => ['accion' => 'migrar', 'estudio' => $n->getKey()], $todos), conectar: false);
        $this->inesperados($fallas, $res);

        $esquemas = [];
        foreach ($todos as $n) {
            $n->refresh();
            $corridas = $gestor->ejecutarEn($n, fn (): int => DB::connection('tenant')->table('migrations')->count());
            $this->comprobar($fallas, $n->version_migraciones === $ultima, "{$n->slug} quedó en {$n->version_migraciones}");
            $this->comprobar($fallas, $corridas === $archivos->count(), "{$n->slug}: {$corridas} de {$archivos->count()} migraciones");
            $esquemas[$n->slug] = $gestor->ejecutarEn($n, fn (): string => $this->esquema());
        }
        $this->comprobar($fallas, count(array_unique($esquemas)) === 1, 'los negocios no quedaron con el mismo esquema');
        $this->detalle(count($todos).' negocio(s): '.self::MIGRACIONES_A_REHACER." migraciones deshechas y vueltas a aplicar a la vez → {$ultima}", $res);

        return $fallas;
    }

    /**
     * Lanza los pasos en procesos separados que arrancan a la vez (esperan una
     * señal de salida común) y devuelve lo que respondió cada uno.
     *
     * @param  list<array<string, mixed>>  $pasos
     * @return list<array{hecho: bool, esperado: bool, detalle: string, tarde: bool}>
     */
    private function competir(array $pasos, bool $conectar = true): array
    {
        $en = microtime(true) + $this->espera + 0.1 * count($pasos);
        $resultados = Process::concurrently(function (Pool $pool) use ($pasos, $en, $conectar): void {
            foreach ($pasos as $paso) {
                $paso['en'] = $en;
                if ($conectar) {
                    $paso['negocio'] = $this->principal->getKey();
                }
                $pool->path(base_path())->env(['XDEBUG_MODE' => 'off'])->timeout(300)->command([
                    PHP_BINARY, 'artisan', 'agendauno:verificar-concurrencia',
                    '--paso='.base64_encode((string) json_encode($paso)),
                ]);
            }
        });

        $salida = [];
        foreach ($resultados->collect() as $r) {
            $linea = collect(preg_split('/\R/', trim($r->output())) ?: [])->last(fn (string $l): bool => str_starts_with($l, '{'));
            $dato = is_string($linea) ? json_decode($linea, true) : null;
            $salida[] = is_array($dato)
                ? ['hecho' => (bool) $dato['hecho'], 'esperado' => (bool) $dato['esperado'], 'detalle' => (string) $dato['detalle'], 'tarde' => (bool) ($dato['tarde'] ?? false)]
                : ['hecho' => false, 'esperado' => false, 'detalle' => 'El proceso no respondió: '.Str::limit(trim($r->errorOutput().' '.$r->output()), 300), 'tarde' => false];
        }
        // Si alguno llegó después de la señal, la próxima ronda les da más margen.
        if (collect($salida)->contains('tarde', true)) {
            $this->espera *= 1.5;
        }

        return $salida;
    }

    /**
     * El proceso hijo: prepara su operación, espera la señal y la ejecuta.
     */
    private function hijo(EscenariosConcurrencia $escenarios, string $codificado): int
    {
        $paso = json_decode((string) base64_decode($codificado, true), true);
        if (! is_array($paso)) {
            $this->line((string) json_encode(['hecho' => false, 'esperado' => false, 'detalle' => 'Paso ilegible']));

            return self::FAILURE;
        }
        if (($paso['accion'] ?? '') === 'ping') {
            $this->line((string) json_encode(['hecho' => true, 'esperado' => true, 'detalle' => 'listo']));

            return self::SUCCESS;
        }
        if (isset($paso['negocio'])) {
            $escenarios->conectar((int) $paso['negocio']);
        }

        $tarde = false;
        $resultado = $escenarios->ejecutar($paso, function () use ($paso, &$tarde): void {
            $falta = (float) $paso['en'] - microtime(true);
            $falta > 0 ? usleep((int) ($falta * 1_000_000)) : $tarde = true;
        });
        $this->line((string) json_encode([...$resultado, 'tarde' => $tarde], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * Mide cuánto tarda en arrancar un proceso para fijar la señal de salida.
     */
    private function calibrar(): void
    {
        $inicio = microtime(true);
        $this->competir([['accion' => 'ping']], conectar: false);
        $this->espera = max(1.5, (microtime(true) - $inicio) * 2);
    }

    /**
     * @param  Closure(): list<string>  $revisar
     */
    private function caso(string $nombre, Closure $revisar): void
    {
        $this->newLine();
        $this->line("<options=bold>{$nombre}</>");
        try {
            $fallas = $revisar();
        } catch (Throwable $e) {
            $fallas = ['no se pudo correr: '.class_basename($e).': '.Str::limit($e->getMessage(), 300)];
        }
        if ($fallas === []) {
            $this->line('  <fg=green>OK</>');

            return;
        }
        foreach (array_unique($fallas) as $f) {
            $this->line("  <fg=red>FALLA</> {$f}");
        }
        $this->fallas++;
    }

    /**
     * @param  list<string>  $fallas
     */
    private function comprobar(array &$fallas, bool $cumple, string $falla): void
    {
        if (! $cumple) {
            $fallas[] = $falla;
        }
    }

    /**
     * @param  list<string>  $fallas
     * @param  list<array{hecho: bool, esperado: bool, detalle: string, tarde?: bool}>  $res
     */
    private function inesperados(array &$fallas, array $res): void
    {
        foreach ($res as $r) {
            if (! $r['esperado']) {
                $fallas[] = "error inesperado: {$r['detalle']}";
            }
        }
    }

    /**
     * @param  list<array{hecho: bool, esperado: bool, detalle: string, tarde?: bool}>  $res
     */
    private function hechos(array $res): int
    {
        return count(array_filter($res, fn (array $r): bool => $r['hecho']));
    }

    /**
     * @param  list<array{hecho: bool, esperado: bool, detalle: string, tarde?: bool}>  $res
     */
    private function detalle(string $texto, array $res): void
    {
        $tarde = count(array_filter($res, fn (array $r): bool => $r['tarde'] ?? false));
        $this->line("  · {$texto}".($tarde > 0 ? " <fg=yellow>({$tarde} proceso(s) arrancaron tarde)</>" : ''));
        if ($this->output->isVerbose()) {
            foreach ($res as $r) {
                $this->line('      '.($r['hecho'] ? 'hecho' : 'no').": {$r['detalle']}");
            }
        }
    }

    /**
     * Suma de verificación de cada tabla del negocio conectado.
     *
     * @return array<string, string|null>
     */
    private function sumasDeTablas(): array
    {
        $tablas = array_map(fn ($t): string => (string) array_values((array) $t)[0], DB::connection('tenant')->select('SHOW TABLES'));
        $sumas = [];
        foreach (array_chunk($tablas, 20) as $grupo) {
            $lista = implode(', ', array_map(fn (string $t): string => "`{$t}`", $grupo));
            foreach (DB::connection('tenant')->select("CHECKSUM TABLE {$lista}") as $fila) {
                $sumas[Str::afterLast((string) $fila->Table, '.')] = $fila->Checksum === null ? null : (string) $fila->Checksum;
            }
        }
        ksort($sumas);

        return $sumas;
    }

    /**
     * Huella del esquema del negocio conectado: tablas, columnas, tipos e índices.
     */
    private function esquema(): string
    {
        $columnas = DB::connection('tenant')->select(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, COLUMN_NAME'
        );
        $indices = DB::connection('tenant')->select(
            'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        );

        return hash('sha256', (string) json_encode([$columnas, $indices]));
    }

    private function nuevoEstudio(): Estudio
    {
        $sufijo = Str::lower(Str::random(6));
        $estudio = app(RegistrarEstudio::class)->ejecutar([
            'nombre' => 'Verificación de concurrencia',
            'slug' => "verificacion-{$sufijo}",
            'contacto_nombre' => 'Verificación',
            'contacto_email' => "verificacion-{$sufijo}@verificacion.invalid",
        ]);
        $estudio->update(['publicado' => false, 'privado' => true]);
        $this->temporales[] = $estudio;

        return $estudio;
    }

    private function baseVacia(GestorDeConexionTenant $gestor, Estudio $estudio): bool
    {
        try {
            $tablas = $gestor->ejecutarEn($estudio, fn (): array => (array) DB::connection('tenant')
                ->selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'));

            return (int) ($tablas['n'] ?? -1) === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Borra lo que creó: sus bases (solo las de prefijo de verificación), el
     * contenido de la base prestada, los respaldos y los negocios temporales.
     */
    private function limpiar(GestorDeConexionTenant $gestor, ?string $base): void
    {
        $volcado = app(VolcadoBaseDatos::class);
        foreach ($this->temporales as $estudio) {
            try {
                $volcado->disco()->deleteDirectory($volcado->carpeta($estudio->slug));
                $nombre = (string) $estudio->db_database;
                if ($nombre === $base) {
                    if ($this->basePrestadaVacia) {
                        $gestor->ejecutarEn($estudio, fn () => Schema::connection('tenant')->dropAllTables());
                    }
                } elseif (str_starts_with($nombre, self::PREFIJO_BASE)) {
                    $gestor->desconectar();
                    DB::statement("DROP DATABASE IF EXISTS `{$nombre}`");
                }
                $estudio->delete();
            } catch (Throwable $e) {
                $this->warn("No se pudo borrar el negocio temporal {$estudio->slug}: {$e->getMessage()}");
            }
        }
        $gestor->desconectar();
    }
}
