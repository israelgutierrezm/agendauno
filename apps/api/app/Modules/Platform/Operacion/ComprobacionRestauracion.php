<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * ¿Lo restaurado permite volver a operar? Revisa una base restaurada en un lugar
 * temporal (tablas esenciales, datos que deben estar, relaciones sin huérfanos y una
 * consulta real de operación) y los archivos recuperados contra los que están en uso.
 * Solo lee; quien restaura crea y borra lo temporal ({@see RespaldosPlataforma}).
 *
 * @phpstan-type Comprobacion array{nombre: string, ok: bool, detalle: string}
 */
class ComprobacionRestauracion
{
    private const CONEXION = 'simulacro';

    /** Archivos en uso que se comparan, como máximo, contra los recuperados. */
    private const MUESTRA_ARCHIVOS = 200;

    private const TABLAS_PLATAFORMA = ['migrations', 'estudios', 'tarifas_saas', 'cargos_renta', 'configuracion_plataforma'];

    private const TABLAS_NEGOCIO = [
        'migrations', 'users', 'personas', 'sucursales', 'ofertas', 'sesiones', 'reservas',
        'ordenes', 'pagos', 'acuerdos', 'derechos', 'movimientos_credito',
    ];

    /** [tabla hija, columna, tabla padre] que no deben quedar huérfanas. */
    private const RELACIONES_NEGOCIO = [
        ['sesiones', 'oferta_id', 'ofertas'],
        ['reservas', 'sesion_id', 'sesiones'],
        ['reservas', 'persona_id', 'personas'],
        ['pagos', 'orden_id', 'ordenes'],
        ['acuerdos', 'persona_id', 'personas'],
        ['derechos', 'acuerdo_id', 'acuerdos'],
        ['movimientos_credito', 'derecho_id', 'derechos'],
    ];

    private const RELACIONES_PLATAFORMA = [
        ['cargos_renta', 'estudio_id', 'estudios'],
        ['mediciones_uso', 'estudio_id', 'estudios'],
    ];

    /**
     * La base central restaurada.
     *
     * @param  array<string, mixed>  $config  conexión a la base temporal
     * @return list<Comprobacion>
     */
    public function plataforma(array $config, ?CarbonImmutable $fecha): array
    {
        return $this->conBase($config, function (Connection $db) use ($fecha): array {
            $faltan = $this->tablasQueFaltan($db, self::TABLAS_PLATAFORMA);
            if ($faltan !== []) {
                return [$this->comprobacion('Tablas esenciales', false, 'Faltan: '.implode(', ', $faltan).'.')];
            }

            // Los negocios que ya existían al respaldar deben venir en el respaldo.
            $esperados = $this->negociosConfirmados($fecha);
            $restaurados = $db->table('estudios')->count();
            $incompletos = $db->table('estudios')->where(fn ($q) => $q->whereNull('slug')->orWhereNull('db_database'))->count();
            // Cada negocio con sus cargos (la consulta agrupada corre entera en la base).
            $consulta = $db->query()->fromSub(
                $db->table('estudios')
                    ->leftJoin('cargos_renta', 'cargos_renta.estudio_id', '=', 'estudios.id')
                    ->groupBy('estudios.id')
                    ->selectRaw('estudios.id, count(cargos_renta.id) as cargos'),
                'negocios_con_cargos',
            )->count();

            return [
                $this->comprobacion('Tablas esenciales', true, implode(', ', self::TABLAS_PLATAFORMA).'.'),
                $this->comprobacion('Migraciones registradas', ($n = $db->table('migrations')->count()) > 0, "{$n} migraciones."),
                $this->comprobacion(
                    'Negocios registrados',
                    $restaurados > 0 || $esperados === 0,
                    "{$restaurados} negocio(s) en el respaldo".($esperados > 0 ? " (hoy hay {$esperados} de antes del respaldo)" : '').'.',
                ),
                $this->relaciones($db, self::RELACIONES_PLATAFORMA),
                $this->comprobacion(
                    'Consulta de operación',
                    $incompletos === 0 && $consulta === $restaurados,
                    $incompletos === 0 ? "Negocios con su base y sus cargos ({$consulta})." : "{$incompletos} negocio(s) sin slug o sin base.",
                ),
            ];
        });
    }

    /**
     * La base restaurada de un negocio.
     *
     * @param  array<string, mixed>  $config  conexión a la base temporal
     * @return list<Comprobacion>
     */
    public function negocio(array $config): array
    {
        return $this->conBase($config, function (Connection $db): array {
            $faltan = $this->tablasQueFaltan($db, self::TABLAS_NEGOCIO);
            if ($faltan !== []) {
                return [$this->comprobacion('Tablas esenciales', false, 'Faltan: '.implode(', ', $faltan).'.')];
            }

            $duenos = $db->table('users')
                ->where(fn ($q) => $q->where('rol', 'propietario')->orWhere('roles', 'like', '%"propietario"%'))
                ->count();
            // Lo que hace la operación a diario: saldos del libro de créditos y la
            // agenda con sus alumnos.
            $saldos = $db->query()->fromSub(
                $db->table('movimientos_credito')->groupBy('derecho_id')->selectRaw('derecho_id, sum(unidades) as saldo'),
                'saldos',
            )->count();
            $agenda = $db->table('reservas')
                ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
                ->join('personas', 'personas.id', '=', 'reservas.persona_id')
                ->count();
            $reservas = $db->table('reservas')->count();

            return [
                $this->comprobacion('Tablas esenciales', true, count(self::TABLAS_NEGOCIO).' tablas.'),
                $this->comprobacion('Migraciones registradas', ($n = $db->table('migrations')->count()) > 0, "{$n} migraciones."),
                $this->comprobacion('Dueño con acceso', $duenos > 0, $duenos > 0 ? "{$duenos} cuenta(s) de dueño." : 'No hay ninguna cuenta de dueño: nadie podría entrar a operar.'),
                $this->relaciones($db, self::RELACIONES_NEGOCIO),
                $this->comprobacion(
                    'Consulta de operación',
                    $agenda === $reservas,
                    "{$reservas} reservas con su clase y su alumno; saldos de {$saldos} paquete(s); {$db->table('pagos')->count()} pagos.",
                ),
            ];
        });
    }

    /**
     * Los archivos recuperados (documentos, fotos, logos) en `$directorio` contra los
     * que están en uso y ya existían al respaldar.
     *
     * @return list<Comprobacion>
     */
    public function archivos(string $directorio, ?CarbonImmutable $fecha): array
    {
        $recuperados = is_dir($directorio)
            ? iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directorio, FilesystemIterator::SKIP_DOTS)))
            : 0;
        $comparados = 0;
        $distintos = [];
        $excluir = 'private/'.trim((string) config('agendauno.respaldos.carpeta', 'respaldos'), '/').'/';
        foreach (['private' => 'local', 'public' => 'public'] as $prefijo => $disco) {
            $raiz = rtrim(Storage::disk($disco)->path(''), '\\/');
            if (! is_dir($raiz)) {
                continue;
            }
            /** @var SplFileInfo $archivo */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS)) as $archivo) {
                if ($comparados >= self::MUESTRA_ARCHIVOS) {
                    break 2;
                }
                $relativo = $prefijo.'/'.str_replace('\\', '/', substr($archivo->getPathname(), strlen($raiz) + 1));
                // Solo lo que ya estaba al respaldar (y no los propios respaldos).
                if ($archivo->getFilename() === '.gitignore' || str_starts_with($relativo, $excluir) || str_starts_with($relativo, 'private/respaldos-temp/')
                    || ($fecha !== null && $archivo->getMTime() > $fecha->getTimestamp())) {
                    continue;
                }
                $comparados++;
                $copia = $directorio.'/'.$relativo;
                if (! is_file($copia) || hash_file('sha256', $copia) !== hash_file('sha256', $archivo->getPathname())) {
                    $distintos[] = $relativo;
                }
            }
        }

        return [
            $this->comprobacion('Paquete legible', is_file($directorio.'/LEEME.txt'), is_file($directorio.'/LEEME.txt') ? "{$recuperados} archivo(s) recuperados." : 'El paquete no trae su LEEME.txt.'),
            $this->comprobacion(
                'Documentos, fotos y logos',
                $distintos === [],
                $distintos === []
                    ? ($comparados > 0 ? "{$comparados} archivo(s) en uso idénticos a los recuperados." : 'No hay archivos en uso de antes del respaldo que comparar.')
                    : count($distintos).' archivo(s) faltan o cambiaron: '.implode(', ', array_slice($distintos, 0, 5)).'.',
            ),
        ];
    }

    /**
     * Negocios registrados (y confirmados) antes de la fecha del respaldo. Se cuentan
     * por otra conexión, como los ve el volcado: una transacción abierta en este
     * proceso no cuenta. En SQLite (desarrollo y pruebas) el volcado se hace por la
     * misma conexión y se cuenta igual.
     */
    private function negociosConfirmados(?CarbonImmutable $fecha): int
    {
        /** @var array<string, mixed> $config */
        $config = DB::connection()->getConfig();
        if (($config['driver'] ?? '') === 'sqlite') {
            return DB::table('estudios')->when($fecha !== null, fn ($q) => $q->where('created_at', '<=', $fecha))->count();
        }
        config(['database.connections.plataforma_confirmada' => $config]);
        try {
            return DB::connection('plataforma_confirmada')->table('estudios')
                ->when($fecha !== null, fn ($q) => $q->where('created_at', '<=', $fecha))
                ->count();
        } finally {
            DB::purge('plataforma_confirmada');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  callable(Connection): list<Comprobacion>  $revisar
     * @return list<Comprobacion>
     */
    private function conBase(array $config, callable $revisar): array
    {
        config(['database.connections.'.self::CONEXION => $config]);
        DB::purge(self::CONEXION);
        try {
            return $revisar(DB::connection(self::CONEXION));
        } catch (Throwable $e) {
            return [$this->comprobacion('Consulta de operación', false, 'No se pudo consultar: '.class_basename($e).'.')];
        } finally {
            DB::purge(self::CONEXION);
        }
    }

    /**
     * @param  list<string>  $tablas
     * @return list<string>
     */
    private function tablasQueFaltan(Connection $db, array $tablas): array
    {
        return array_values(array_filter($tablas, fn (string $t): bool => ! $db->getSchemaBuilder()->hasTable($t)));
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $relaciones
     * @return Comprobacion
     */
    private function relaciones(Connection $db, array $relaciones): array
    {
        $huerfanos = [];
        foreach ($relaciones as [$hija, $columna, $padre]) {
            if (! $db->getSchemaBuilder()->hasTable($hija)) {
                continue;
            }
            $n = $db->table($hija)->whereNotNull($columna)->whereNotIn($columna, $db->table($padre)->select('id'))->count();
            if ($n > 0) {
                $huerfanos[] = "{$n} en {$hija}.{$columna}";
            }
        }

        return $this->comprobacion(
            'Relaciones completas',
            $huerfanos === [],
            $huerfanos === [] ? 'Sin registros huérfanos.' : 'Registros sin su dueño: '.implode(', ', $huerfanos).'.',
        );
    }

    /**
     * @return Comprobacion
     */
    private function comprobacion(string $nombre, bool $ok, string $detalle): array
    {
        return ['nombre' => $nombre, 'ok' => $ok, 'detalle' => $detalle];
    }
}
