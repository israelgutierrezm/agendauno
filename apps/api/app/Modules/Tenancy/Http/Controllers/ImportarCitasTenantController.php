<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ImportarCitasTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importar las citas futuras de otro sistema (ADR 0160): plantilla, catálogos para las
 * correspondencias, vista previa y la importación con la confirmación de esa vista.
 */
class ImportarCitasTenantController
{
    public function __construct(private readonly ImportarCitasTenant $importador, private readonly ParametrosTenant $parametros) {}

    /** Lo que se puede elegir como correspondencia (de las sedes a las que tiene acceso). */
    public function catalogos(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $acceso = app(ResolverAccesoTenant::class);
        $sedes = SucursalTenant::query()->orderBy('nombre')->get()->filter(fn ($s): bool => $acceso->permiteSucursal($actor, (int) $s->id));

        return response()->json(['data' => [
            'servicio' => OfertaTenant::query()->vigentes()->orderBy('nombre')->get()->map(fn ($o): array => ['id' => $o->ulid, 'nombre' => $o->nombre])->values(),
            'sucursal' => $sedes->map(fn ($s): array => ['id' => $s->ulid, 'nombre' => $s->nombre])->values(),
            'profesional' => Usuario::query()->profesionales()->where('activo', true)->orderBy('name')->get()
                ->map(fn ($u): array => ['id' => $u->ulid, 'nombre' => $u->name])->values(),
            'max_filas' => $this->parametros->entero('importaciones.max_filas'),
        ]]);
    }

    public function plantilla(Request $request): StreamedResponse
    {
        $this->actor($request);

        return response()->streamDownload(function (): void {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ImportarCitasTenant::COLUMNAS, ',', '"', '');
            fclose($salida);
        }, 'plantilla-citas.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        [$correspondencias, $recordar] = $this->opciones($request);
        $filas = $this->parsear($request);
        $resultado = $this->importador->ejecutar($filas, $actor, $correspondencias, $recordar);
        if ($resultado['ok']) {
            $resultado['confirmacion'] = Crypt::encryptString(json_encode([
                'contexto' => $this->contexto($request, $actor, $filas, $correspondencias, $recordar),
                'expira' => now()->addMinutes(30)->timestamp,
                'revision' => ImportarCitasTenant::revision($resultado['filas']),
            ], JSON_THROW_ON_ERROR));
        }

        return response()->json(['data' => $resultado]);
    }

    public function importar(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        [$correspondencias, $recordar] = $this->opciones($request);
        $filas = $this->parsear($request);
        $request->validate(['confirmacion' => ['required', 'string']]);
        try {
            $confirmacion = json_decode(Crypt::decryptString($request->string('confirmacion')->toString()), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Exception) {
            $confirmacion = [];
        }
        if (($confirmacion['contexto'] ?? '') !== $this->contexto($request, $actor, $filas, $correspondencias, $recordar) || ($confirmacion['expira'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['archivo' => ['Vuelve a revisar el archivo: la confirmación expiró o cambió el contenido.']]);
        }
        $resultado = $this->importador->ejecutar($filas, $actor, $correspondencias, $recordar, true, (string) ($confirmacion['revision'] ?? ''));

        return response()->json(['data' => $resultado], $resultado['ok'] ? 201 : 422);
    }

    /** Solo negocios de citas: lo cuida la ruta (`modalidad:citas`). */
    private function actor(Request $request): Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless($actor instanceof Usuario && $actor->puede('agenda.gestionar') && $actor->puede('reservas.gestionar'), 403);

        return $actor;
    }

    /**
     * Las correspondencias elegidas (`{"servicio": {"Corte H": "<ulid>"}}`) y si se
     * mandan recordatorios antes de cada cita.
     *
     * @return array{0: array<string, array<string, string>>, 1: bool}
     */
    private function opciones(Request $request): array
    {
        $validado = $request->validate([
            'correspondencias' => ['nullable', 'json'],
            'recordatorios' => ['nullable', 'boolean'],
        ]);
        $crudas = isset($validado['correspondencias']) ? json_decode((string) $validado['correspondencias'], true) : [];
        $correspondencias = [];
        foreach (ImportarCitasTenant::CAMPOS as $campo) {
            foreach ((array) ($crudas[$campo] ?? []) as $valor => $ulid) {
                if (is_string($ulid) && $ulid !== '' && mb_strlen((string) $valor) <= 255) {
                    $correspondencias[$campo][(string) $valor] = $ulid;
                }
            }
        }

        return [$correspondencias, $request->boolean('recordatorios')];
    }

    /**
     * @param  list<array<string, string|int|null>>  $filas
     * @param  array<string, array<string, string>>  $correspondencias
     */
    private function contexto(Request $request, Usuario $actor, array $filas, array $correspondencias, bool $recordar): string
    {
        return hash('sha256', json_encode([$request->attributes->get('estudio')->ulid, $actor->ulid, $filas, $correspondencias, $recordar], JSON_THROW_ON_ERROR));
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
            $requeridas = array_diff(ImportarCitasTenant::COLUMNAS, ImportarCitasTenant::OPCIONALES);
            if (count(array_unique($columnas)) !== count($columnas) || array_diff($columnas, ImportarCitasTenant::COLUMNAS) !== [] || array_diff($requeridas, $columnas) !== []) {
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
                throw ValidationException::withMessages(['archivo' => ['La plantilla no contiene citas. Agrega al menos una fila.']]);
            }

            return $filas;
        } finally {
            fclose($h);
        }
    }
}
