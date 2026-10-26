<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ExportacionNegocio;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use App\Support\Csv;
use Illuminate\Http\File;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Todos los datos de un negocio en un ZIP (ADR 0213): un CSV por tabla de su base y sus
 * archivos (documentos, imágenes, adjuntos), con un LEEME. Lo pide su dueño, antes de
 * irse o cuando quiera: sus datos son suyos.
 *
 * No van los secretos ni lo técnico: contraseñas, tokens, llaves, credenciales y huellas
 * (por el nombre de la columna) ni las tablas de sesiones, colas, avisos crudos de las
 * pasarelas o integraciones. El archivo queda en el disco privado y vence a los días.
 */
class ExportacionDeDatosNegocio
{
    /** Días que se guarda la descarga lista. */
    public const DIAS = 7;

    /** Lo técnico, de acceso o de terceros: no son datos del negocio. */
    private const TABLAS_FUERA = [
        'migrations', 'sqlite_sequence', 'personal_access_tokens', 'password_reset_tokens', 'verificaciones_registro',
        'eventos_outbox', 'entregas_webhook', 'webhooks_salientes', 'avisos_pasarela', 'sesiones_tarjeta',
        'configuraciones_pasarela', 'integraciones', 'llaves_api', 'dispositivos_push', 'kioscos',
        'envios_medicion', 'destinos_medicion', 'contextos_medicion', 'eventos_plataforma',
        'calendarios_externos', 'eventos_calendario_externo',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions',
    ];

    /** Columnas que nunca salen: secretos y huellas. */
    private const COLUMNA_SECRETA = '/(password|contrasena|token|secret|secreto|credencial|llave|hash|sello_|idempotency|remember|(^|_)firma$)/i';

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function armar(Estudio $estudio, ExportacionNegocio $exportacion): void
    {
        $zipLocal = (string) tempnam(sys_get_temp_dir(), 'exp');
        $temporales = [];
        try {
            $zip = new ZipArchive;
            if ($zip->open($zipLocal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el archivo de la descarga.');
            }
            $tablas = $this->gestor->ejecutarEn($estudio, function () use ($zip, &$temporales): array {
                $tablas = [];
                foreach ($this->tablas() as $tabla) {
                    $csv = $this->csv($tabla);
                    if ($csv === null) {
                        continue;
                    }
                    $temporales[] = $csv['ruta'];
                    $zip->addFile($csv['ruta'], "datos/{$tabla}.csv");
                    $tablas[$tabla] = $csv['filas'];
                }

                return $tablas;
            });
            $archivos = 0;
            foreach (BajaDeNegocio::carpetas($estudio) as [$disco, $carpeta]) {
                foreach ($disco->allFiles($carpeta) as $archivo) {
                    $zip->addFromString('archivos/'.$archivo, (string) $disco->get($archivo));
                    $archivos++;
                }
            }
            $zip->addFromString('LEEME.txt', $this->leeme($estudio, $tablas, $archivos));
            $zip->close();

            $ruta = "exportaciones/{$estudio->getKey()}/{$exportacion->ulid}.zip";
            DiscosDeArchivos::privado()->putFileAs(dirname($ruta), new File($zipLocal), basename($ruta));
            $exportacion->update([
                'estado' => ExportacionNegocio::LISTA,
                'ruta' => $ruta,
                'tamano_bytes' => (int) filesize($zipLocal),
                'error' => null,
                'lista_en' => Carbon::now(),
                'vence_en' => Carbon::now()->addDays(self::DIAS),
            ]);
        } finally {
            foreach ([$zipLocal, ...$temporales] as $temporal) {
                if (is_file($temporal)) {
                    unlink($temporal);
                }
            }
        }
    }

    /** Borra el archivo de una descarga (al vencer o al pedir otra). */
    public static function borrarArchivo(ExportacionNegocio $exportacion): void
    {
        if ($exportacion->ruta !== null) {
            DiscosDeArchivos::privado()->delete($exportacion->ruta);
        }
    }

    /**
     * Las tablas de su base que se exportan.
     *
     * @return list<string>
     */
    private function tablas(): array
    {
        $nombres = array_map(static fn (array $t): string => (string) $t['name'], Schema::connection('tenant')->getTables());
        sort($nombres);

        return array_values(array_filter($nombres, static fn (string $t): bool => ! in_array($t, self::TABLAS_FUERA, true)));
    }

    /**
     * Una tabla a un CSV temporal (con BOM, para que la hoja de cálculo lea los acentos).
     * Null si no tiene columnas que exportar.
     *
     * @return array{ruta: string, filas: int}|null
     */
    private function csv(string $tabla): ?array
    {
        $columnas = array_values(array_filter(
            Schema::connection('tenant')->getColumnListing($tabla),
            static fn (string $c): bool => preg_match(self::COLUMNA_SECRETA, $c) !== 1,
        ));
        if ($columnas === []) {
            return null;
        }
        $ruta = (string) tempnam(sys_get_temp_dir(), 'csv');
        $archivo = fopen($ruta, 'wb');
        if ($archivo === false) {
            throw new RuntimeException("No se pudo escribir la tabla {$tabla}.");
        }
        fwrite($archivo, "\u{FEFF}".Csv::linea($columnas)."\n");
        $consulta = DB::connection('tenant')->table($tabla)->select($columnas);
        $filas = in_array('id', $columnas, true)
            ? $consulta->lazyById(1000, 'id')
            : $consulta->orderBy($columnas[0])->lazy(1000);
        $total = 0;
        foreach ($filas as $fila) {
            $valores = [];
            foreach ($columnas as $columna) {
                $valor = ((array) $fila)[$columna] ?? null;
                $valores[] = is_scalar($valor) || $valor === null ? (is_bool($valor) ? (int) $valor : $valor) : (string) json_encode($valor);
            }
            fwrite($archivo, Csv::linea($valores)."\n");
            $total++;
        }
        fclose($archivo);

        return ['ruta' => $ruta, 'filas' => $total];
    }

    /**
     * @param  array<string, int>  $tablas
     */
    private function leeme(Estudio $estudio, array $tablas, int $archivos): string
    {
        $lineas = [
            "Datos de {$estudio->nombre}",
            'Descargados el '.Carbon::now()->setTimezone((string) ($estudio->zona_horaria ?: 'America/Mexico_City'))->format('Y-m-d H:i'),
            '',
            'datos/: una hoja (CSV, UTF-8) por cada tipo de dato del negocio. Las columnas *_id',
            'enlazan una hoja con otra; los montos *_minor están en centavos y los créditos en',
            'milésimas (1000 = 1 crédito). No incluye contraseñas, tokens ni llaves.',
            'archivos/: los documentos, las imágenes y los adjuntos que subió el negocio.',
            '',
            'Hojas:',
        ];
        foreach ($tablas as $tabla => $filas) {
            $lineas[] = "  {$tabla}.csv: {$filas} filas";
        }
        $lineas[] = '';
        $lineas[] = "Archivos: {$archivos}";

        return implode("\n", $lineas)."\n";
    }
}
