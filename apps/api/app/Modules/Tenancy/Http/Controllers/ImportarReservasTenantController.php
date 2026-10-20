<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ImportarReservasTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importar las reservas futuras de clases de otro sistema (ADR 0176): plantilla, vista
 * previa y la importación con la confirmación de esa vista (todo o, si se pide, por
 * partes).
 */
class ImportarReservasTenantController
{
    public function __construct(private readonly ImportarReservasTenant $importador, private readonly ParametrosTenant $parametros) {}

    public function plantilla(Request $request): StreamedResponse
    {
        $this->actor($request);

        return response()->streamDownload(function (): void {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ImportarReservasTenant::COLUMNAS, ',', '"', '');
            fclose($salida);
        }, 'plantilla-reservas.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        [$corte, $yaDescontadas] = $this->opciones($request, $actor);
        $filas = $this->parsear($request);

        $resultado = $this->importador->ejecutar($filas, $actor, $corte, $yaDescontadas);
        $resultado['confirmacion'] = Crypt::encryptString(json_encode([
            'contexto' => $this->contexto($request, $actor, $filas, $corte, $yaDescontadas),
            'expira' => now()->addMinutes(30)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return response()->json(['data' => $resultado]);
    }

    public function importar(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        [$corte, $yaDescontadas] = $this->opciones($request, $actor);
        $filas = $this->parsear($request);
        $request->validate(['confirmacion' => ['required', 'string'], 'parcial' => ['nullable', 'boolean']]);
        try {
            $confirmacion = json_decode(Crypt::decryptString($request->string('confirmacion')->toString()), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Exception) {
            $confirmacion = [];
        }
        if (($confirmacion['contexto'] ?? '') !== $this->contexto($request, $actor, $filas, $corte, $yaDescontadas) || ($confirmacion['expira'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['archivo' => ['Vuelve a revisar el archivo: la confirmación expiró o cambió el contenido.']]);
        }

        $resultado = $this->importador->ejecutar($filas, $actor, $corte, $yaDescontadas, true, $request->boolean('parcial'));

        return response()->json(['data' => $resultado], $resultado['importadas'] > 0 || $resultado['ok'] ? 201 : 422);
    }

    /** Solo negocios de clases: lo cuida la ruta (`modalidad:clases`). */
    private function actor(Request $request): Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless($actor instanceof Usuario && $actor->puede('reservas.gestionar'), 403);

        return $actor;
    }

    /**
     * El corte acordado y si los saldos de apertura ya descontaron estas reservas.
     * Reintegrar créditos es concederlos: pide además gestionar créditos.
     *
     * @return array{0: string, 1: bool}
     */
    private function opciones(Request $request, Usuario $actor): array
    {
        $validado = $request->validate([
            'corte' => ['required', 'date_format:Y-m-d'],
            'ya_descontadas' => ['required', 'boolean'],
        ], [
            'corte.required' => 'Indica la fecha de corte acordada.',
            'corte.date_format' => 'Escribe la fecha de corte como AAAA-MM-DD.',
            'ya_descontadas.required' => 'Indica si los saldos que cargaste ya descontaron estas reservas.',
        ]);
        $yaDescontadas = $request->boolean('ya_descontadas');
        abort_if($yaDescontadas && ! $actor->puede('creditos.gestionar'), 403);

        return [(string) $validado['corte'], $yaDescontadas];
    }

    /**
     * @param  list<array<string, string|int|null>>  $filas
     */
    private function contexto(Request $request, Usuario $actor, array $filas, string $corte, bool $yaDescontadas): string
    {
        return hash('sha256', json_encode([$request->attributes->get('estudio')->ulid, $actor->ulid, $filas, $corte, $yaDescontadas], JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, string|int|null>> */
    private function parsear(Request $request): array
    {
        $request->validate(['archivo' => ['required', 'file', 'max:2048']]);
        $ruta = $request->file('archivo')->getRealPath();
        $contenido = (string) file_get_contents($ruta);
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            throw ValidationException::withMessages(['archivo' => ['Guarda el archivo como CSV UTF-8.']]);
        }
        $h = fopen($ruta, 'r');
        try {
            $linea = fgets($h);
            if ($linea === false) {
                throw ValidationException::withMessages(['archivo' => ['El archivo está vacío.']]);
            }
            $separador = substr_count($linea, ';') > substr_count($linea, ',') ? ';' : ',';
            rewind($h);
            $cabecera = (array) fgetcsv($h, null, $separador, '"', '');
            $columnas = array_map(fn ($v): string => mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $v))), $cabecera);
            $requeridas = array_diff(ImportarReservasTenant::COLUMNAS, ImportarReservasTenant::OPCIONALES);
            if (count(array_unique($columnas)) !== count($columnas) || array_diff($columnas, ImportarReservasTenant::COLUMNAS) !== [] || array_diff($requeridas, $columnas) !== []) {
                throw ValidationException::withMessages(['archivo' => ['Encabezados inválidos o repetidos. Descarga la plantilla y conserva sus columnas.']]);
            }
            $filas = [];
            $numero = 1;
            $max = $this->parametros->entero('importaciones.max_filas');
            while (($valores = fgetcsv($h, null, $separador, '"', '')) !== false) {
                $numero++;
                if (count(array_filter($valores, fn ($v): bool => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                if (count($filas) >= $max || count($valores) !== count($columnas)) {
                    throw ValidationException::withMessages(['archivo' => ["Fila {$numero}: número de columnas incorrecto o límite de {$max} filas superado."]]);
                }
                $filas[] = array_combine($columnas, $valores) + ['_fila' => $numero];
            }
            if ($filas === []) {
                throw ValidationException::withMessages(['archivo' => ['La plantilla no contiene reservas. Agrega al menos una fila.']]);
            }

            return $filas;
        } finally {
            fclose($h);
        }
    }
}
