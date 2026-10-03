<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ImportarClasesTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportarClasesTenantController
{
    public function __construct(private readonly ImportarClasesTenant $importador, private readonly ParametrosTenant $parametros) {}

    public function catalogos(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $sedes = SucursalTenant::query()->get()->filter(fn ($s): bool => app(ResolverAccesoTenant::class)->permiteSucursal($actor, (int) $s->id));

        return response()->json(['data' => [
            'clases' => OfertaTenant::query()->orderBy('nombre')->get()->map(fn ($o): array => ['id' => $o->ulid, 'nombre' => $o->nombre, 'cupo' => $o->capacidad]),
            'sucursales' => $sedes->map(fn ($s): array => ['id' => $s->ulid, 'nombre' => $s->nombre, 'zona_horaria' => $s->zona_horaria])->values(),
            'instructores' => Usuario::query()->profesionales()->where('activo', true)->orderBy('name')->get()
                ->filter(fn ($u): bool => $sedes->contains(fn ($s): bool => app(ResolverAccesoTenant::class)->permiteSucursal($u, (int) $s->id)))
                ->map(fn ($u): array => ['id' => $u->ulid, 'nombre' => $u->name])->values(),
            'salas' => RecursoTenant::query()->where('activo', true)->whereIn('sucursal_id', $sedes->pluck('id'))->with('sucursal')->get()
                ->map(fn ($r): array => ['id' => $r->ulid, 'nombre' => $r->nombre, 'sucursal' => $r->sucursal?->nombre]),
            'max_sesiones' => $this->parametros->entero('importaciones.max_sesiones_agenda'),
        ]]);
    }

    public function plantilla(Request $request): StreamedResponse
    {
        $this->actor($request);
        $modo = $this->modo($request);
        $columnas = $this->columnas($modo);

        return response()->streamDownload(function () use ($columnas): void {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $columnas, ',', '"', '');
            fclose($salida);
        }, "plantilla-clases-{$modo}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $modo = $this->modo($request);
        $filas = $this->parsear($request, $modo);
        $resultado = $this->importador->ejecutar($filas, $modo, $actor);
        if ($resultado['ok']) {
            $resultado['confirmacion'] = Crypt::encryptString(json_encode([
                'contexto' => $this->contexto($request, $actor, $filas, $modo), 'expira' => now()->addMinutes(30)->timestamp,
                'revision' => ImportarClasesTenant::revision($resultado['filas']),
            ], JSON_THROW_ON_ERROR));
        }

        return response()->json(['data' => $resultado]);
    }

    public function importar(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $modo = $this->modo($request);
        $filas = $this->parsear($request, $modo);
        $request->validate(['confirmacion' => ['required', 'string']]);
        try {
            $confirmacion = json_decode(Crypt::decryptString($request->string('confirmacion')->toString()), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Exception) {
            $confirmacion = [];
        }
        if (($confirmacion['contexto'] ?? '') !== $this->contexto($request, $actor, $filas, $modo) || ($confirmacion['expira'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['archivo' => ['Vuelve a revisar el archivo: la confirmación expiró o cambió el contenido.']]);
        }
        $resultado = $this->importador->ejecutar($filas, $modo, $actor, true, $confirmacion['revision'] ?? '');

        return response()->json(['data' => $resultado], $resultado['ok'] ? 201 : 422);
    }

    private function actor(Request $request): Usuario
    {
        $estudio = $request->attributes->get('estudio');
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless($estudio instanceof Estudio && $estudio->modalidad() === ModalidadServicio::Clases, 403, 'Esta importación es para la agenda de clases, no para citas.');
        abort_unless($actor instanceof Usuario && $actor->puede('agenda.gestionar'), 403);

        return $actor;
    }

    private function modo(Request $request): string
    {
        return $request->validate(['modo' => ['required', 'in:fechas,semanal']])['modo'];
    }

    /** @return list<string> */
    private function columnas(string $modo): array
    {
        return array_merge(['referencia', 'clase', 'sucursal', 'instructor', 'sala'],
            $modo === 'fechas' ? ['fecha'] : ['dia', 'desde', 'hasta'], ['inicio', 'fin', 'cupo'], $modo === 'fechas' ? ['estado'] : []);
    }

    /** @param list<array<string, string|int|null>> $filas */
    private function contexto(Request $request, Usuario $actor, array $filas, string $modo): string
    {
        return hash('sha256', json_encode([$request->attributes->get('estudio')->ulid, $actor->ulid, $modo, $filas], JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, string|int|null>> */
    private function parsear(Request $request, string $modo): array
    {
        $request->validate(['archivo' => ['required', 'file', 'max:2048']]);
        $contenido = file_get_contents($request->file('archivo')->getRealPath());
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            throw ValidationException::withMessages(['archivo' => ['Guarda el archivo como CSV UTF-8.']]);
        }
        $h = fopen($request->file('archivo')->getRealPath(), 'r');
        try {
            $linea = fgets($h);
            if ($linea === false) {
                throw ValidationException::withMessages(['archivo' => ['El archivo está vacío.']]);
            }
            $separador = substr_count($linea, ';') > substr_count($linea, ',') ? ';' : ',';
            rewind($h);
            $cabecera = fgetcsv($h, null, $separador, '"', '');
            $columnas = array_map(fn ($v): string => mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $v))), $cabecera);
            $permitidas = $this->columnas($modo);
            $requeridas = array_diff($permitidas, ['instructor', 'sala', 'cupo', 'estado']);
            if (count(array_unique($columnas)) !== count($columnas) || array_diff($columnas, $permitidas) !== [] || array_diff($requeridas, $columnas) !== []) {
                throw ValidationException::withMessages(['archivo' => ['Encabezados inválidos o repetidos. Descarga la plantilla de la opción seleccionada y conserva sus columnas.']]);
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
                throw ValidationException::withMessages(['archivo' => ['La plantilla no contiene sesiones. Agrega al menos una fila.']]);
            }

            return $filas;
        } finally {
            fclose($h);
        }
    }
}
