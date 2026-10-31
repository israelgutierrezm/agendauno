<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AsignarSucursalAlPersonalTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\ReanclarZonaDeSede;
use App\Modules\Tenancy\Application\ReanclarZonaDeSedeTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Exceptions\CambioDeZonaSinConfirmar;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use App\Modules\Tenancy\Support\EnlaceMapa;
use App\Modules\Tenancy\Support\HorarioSucursal;
use App\Modules\Tenancy\Support\RedesSociales;
use App\Support\ReporteDeErrores;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

/**
 * Organizaciones y sucursales del estudio, tenant-local (BD del tenant). Base para
 * la agenda (las sesiones ocurren en una sucursal con su zona horaria). Opera
 * SIEMPRE sobre la BD del estudio resuelto; gateado por permisos tenant-local.
 */
class OrganizacionesTenantController
{
    public function organizaciones(): JsonResponse
    {
        $organizaciones = OrganizacionTenant::query()->with('sucursales')->orderBy('id')->get();

        return response()->json([
            'data' => $organizaciones->map(fn (OrganizacionTenant $organizacion): array => [
                'id' => $organizacion->ulid,
                'nombre' => $organizacion->nombre,
                'sucursales' => $organizacion->sucursales->map(fn (SucursalTenant $sucursal): array => $this->presentarSucursal($sucursal))->all(),
            ])->all(),
        ]);
    }

    public function crearOrganizacion(Request $request): JsonResponse
    {
        $validado = $request->validate(['nombre' => ['required', 'string', 'max:255']]);

        $organizacion = OrganizacionTenant::query()->create(['nombre' => $validado['nombre']]);

        return response()->json(['data' => ['id' => $organizacion->ulid, 'nombre' => $organizacion->nombre]], 201);
    }

    public function crearSucursal(Request $request): JsonResponse
    {
        $organizacion = OrganizacionTenant::query()->where('ulid', (string) $request->route('organizacion'))->firstOrFail();

        $validado = $this->validarSucursal($request, obligarNombre: true);
        // Más de una sucursal es de Premium (ADR 0107).
        $estudio = $request->attributes->get('estudio');
        if ($estudio instanceof Estudio && SucursalTenant::query()->exists()) {
            app(FuncionesPlan::class)->exigir($estudio, 'sucursales');
        }
        // ¿Es la segunda? Entonces la primera era «todo» para el personal sin asignar.
        $unica = SucursalTenant::query()->count() === 1 ? SucursalTenant::query()->first() : null;

        $sucursal = $organizacion->sucursales()->create([
            'nombre' => $validado['nombre'],
            // Sin zona, la del negocio; la moneda es la del negocio (ADR 0099).
            'zona_horaria' => $validado['zona_horaria'] ?? app(FechasNegocioTenant::class)->zona(),
            'region' => $validado['region'] ?? null,
            'moneda' => null,
            'impuesto_tasa_bps' => $validado['impuesto_tasa_bps'] ?? 0,
            'latitud' => $validado['latitud'] ?? null,
            'longitud' => $validado['longitud'] ?? null,
        ]);
        $this->aplicarPerfilPublico($sucursal, $validado);
        $sucursal->save();
        if ($unica instanceof SucursalTenant) {
            app(AsignarSucursalAlPersonalTenant::class)->sinAsignar([(int) $unica->getKey()]);
        }

        return response()->json(['data' => $this->presentarSucursal($sucursal)], 201);
    }

    /**
     * Edita una sede. Cambiar su zona con fechas por venir las re-ancla (conservan su hora
     * de reloj) y avisa a quien tiene reserva (ADR 0230): sin `confirmar_zona` no cambia
     * nada y responde 409 con cuántas fechas y reservas son, para que la pantalla pregunte.
     */
    public function actualizarSucursal(Request $request, ReanclarZonaDeSedeTenant $reanclar, RegistrarAuditoria $auditoria): JsonResponse
    {
        $sucursal = SucursalTenant::query()->where('ulid', (string) $request->route('sucursal'))->firstOrFail();

        $validado = $this->validarSucursal($request, obligarNombre: false);
        $request->validate(['confirmar_zona' => ['sometimes', 'boolean']]);

        $zonaAnterior = (string) $sucursal->zona_horaria;
        $zonaNueva = isset($validado['zona_horaria']) ? (string) $validado['zona_horaria'] : $zonaAnterior;
        $afectadas = $zonaNueva !== $zonaAnterior ? $reanclar->afectadas($sucursal, $zonaNueva) : null;
        if ($afectadas !== null && $afectadas['sesiones'] > 0 && ! $request->boolean('confirmar_zona')) {
            throw new CambioDeZonaSinConfirmar($zonaAnterior, $zonaNueva, $afectadas['sesiones'], $afectadas['reservas']);
        }

        // Solo pisa lo enviado (edición parcial de la unidad de negocio).
        $sucursal->fill(array_filter([
            'nombre' => $validado['nombre'] ?? null,
            'zona_horaria' => $validado['zona_horaria'] ?? null,
            'region' => $validado['region'] ?? null,
        ], static fn ($v): bool => $v !== null));

        if (array_key_exists('impuesto_tasa_bps', $validado)) {
            $sucursal->impuesto_tasa_bps = (int) $validado['impuesto_tasa_bps'];
        }
        // La ubicación va junta (o se quita junta, con null en ambas).
        if (array_key_exists('latitud', $validado)) {
            $sucursal->latitud = $validado['latitud'];
            $sucursal->longitud = $validado['longitud'] ?? null;
        }
        $this->aplicarPerfilPublico($sucursal, $validado);

        $sucursal->save();

        if ($afectadas === null) {
            return response()->json(['data' => $this->presentarSucursal($sucursal)]);
        }
        $actor = $request->attributes->get('usuario_tenant');
        $auditoria->registrar($actor instanceof Usuario ? $actor : null, 'sucursal.zona_cambiada', 'sucursal', (string) $sucursal->ulid,
            ['zona_horaria' => $zonaAnterior], ['zona_horaria' => $zonaNueva, ...$afectadas]);

        return response()->json([
            'data' => $this->presentarSucursal($sucursal),
            'meta' => ['reanclaje' => [...$afectadas, 'en_cola' => $this->reanclarAlCambiarZona($request, $sucursal, $reanclar, $afectadas['sesiones'])]],
        ]);
    }

    /**
     * Con pocas fechas, en la misma solicitud; con muchas, o si falla a medias, en la cola
     * (lleva el negocio y es idempotente). Devuelve si quedó en la cola.
     */
    private function reanclarAlCambiarZona(Request $request, SucursalTenant $sucursal, ReanclarZonaDeSedeTenant $reanclar, int $sesiones): bool
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        if ($sesiones <= ReanclarZonaDeSedeTenant::EN_LINEA_MAXIMO) {
            try {
                $reanclar->reanclar($sucursal);

                return false;
            } catch (Throwable $e) {
                // La zona ya cambió: lo que faltó se termina en la cola, no se pierde.
                ReporteDeErrores::reportarAtrapado($e);
            }
        }
        ReanclarZonaDeSede::dispatch((int) $estudio->getKey(), (string) $sucursal->ulid);

        return true;
    }

    /**
     * Las sucursales del negocio. Quien está acotado a algunas (R19) ve solo esas: son
     * las que le tocan para vender, agendar o filtrar; sin ninguna asignada, ninguna
     * (ADR 0098).
     */
    public function sucursales(Request $request): JsonResponse
    {
        $sedes = app(ResolverAccesoTenant::class)->deLaSolicitud($request);
        $sucursales = SucursalTenant::query()
            ->when($sedes !== null, fn ($q) => $q->whereIn('id', $sedes))
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'data' => $sucursales->map(fn (SucursalTenant $sucursal): array => $this->presentarSucursal($sucursal))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validarSucursal(Request $request, bool $obligarNombre): array
    {
        return $request->validate([
            'nombre' => [$obligarNombre ? 'required' : 'sometimes', 'string', 'max:255'],
            'zona_horaria' => ['nullable', 'timezone'],
            'region' => ['nullable', 'string', 'max:255'],
            'moneda' => ['nullable', 'string', 'size:3', app(RegionNegocioTenant::class)->reglaMoneda()],
            'impuesto_tasa_bps' => ['nullable', 'integer', 'min:0', 'max:100000'],
            // Ubicación del local (para el clima): las dos o ninguna.
            'latitud' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitud'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitud'],
            // Perfil público de la sede.
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 +()-]*$/'],
            'whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 +()-]*$/'],
            ...RedesSociales::reglas(),
            ...HorarioSucursal::reglas(),
            // Enlace de Google Maps para llegar (ADR 0064).
            ...EnlaceMapa::reglas(),
        ]);
    }

    /**
     * Perfil público de la sede: lo enviado se guarda (vacío = se quita).
     *
     * @param  array<string, mixed>  $validado
     */
    private function aplicarPerfilPublico(SucursalTenant $sucursal, array $validado): void
    {
        foreach (['direccion', 'telefono', 'whatsapp'] as $campo) {
            if (array_key_exists($campo, $validado)) {
                $valor = trim((string) $validado[$campo]);
                $sucursal->{$campo} = $valor === '' ? null : $valor;
            }
        }
        if (array_key_exists('redes', $validado)) {
            /** @var array<string, mixed>|null $redes */
            $redes = $validado['redes'];
            $sucursal->redes = RedesSociales::normalizar($redes);
        }
        if (array_key_exists('horario', $validado)) {
            /** @var list<array<string, mixed>>|null $horario */
            $horario = $validado['horario'];
            $sucursal->horario = HorarioSucursal::normalizar($horario);
        }
        if (array_key_exists('mapa_url', $validado)) {
            $sucursal->mapa_url = EnlaceMapa::normalizar(is_string($validado['mapa_url']) ? $validado['mapa_url'] : null);
        }
    }

    /**
     * Una foto de la sede (la que ve el cliente al elegirla). SVG excluido a propósito
     * (riesgo de XSS al servirse en el navegador).
     */
    public function subirFoto(Request $request): JsonResponse
    {
        $sucursal = SucursalTenant::query()->where('ulid', (string) $request->route('sucursal'))->firstOrFail();
        $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $archivo = $request->file('foto');
        abort_unless($archivo instanceof UploadedFile, 422);
        // Carpeta propia por negocio y nombre no enumerable.
        $ruta = $archivo->storeAs(
            'sucursales/'.$estudio->getKey(),
            Str::lower(Str::random(40)).'.'.$archivo->extension(),
            DiscosDeArchivos::nombrePublico(),
        );

        $this->borrarFoto($sucursal);
        $sucursal->foto_ruta = (string) $ruta;
        $sucursal->save();

        return response()->json(['data' => $this->presentarSucursal($sucursal)]);
    }

    public function eliminarFoto(Request $request): JsonResponse
    {
        $sucursal = SucursalTenant::query()->where('ulid', (string) $request->route('sucursal'))->firstOrFail();
        $this->borrarFoto($sucursal);
        $sucursal->foto_ruta = null;
        $sucursal->save();

        return response()->json(['data' => $this->presentarSucursal($sucursal)]);
    }

    private function borrarFoto(SucursalTenant $sucursal): void
    {
        if ($sucursal->foto_ruta !== null && $sucursal->foto_ruta !== '') {
            DiscosDeArchivos::publico()->delete($sucursal->foto_ruta);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarSucursal(SucursalTenant $sucursal): array
    {
        return [
            'id' => $sucursal->ulid,
            'nombre' => $sucursal->nombre,
            'zona_horaria' => $sucursal->zona_horaria,
            'region' => $sucursal->region,
            // La del negocio: una sola moneda (ADR 0099).
            'moneda' => app(ParametrosTenant::class)->moneda(),
            'impuesto_tasa_bps' => (int) $sucursal->impuesto_tasa_bps,
            'latitud' => $sucursal->latitud,
            'longitud' => $sucursal->longitud,
            'direccion' => $sucursal->direccion,
            'telefono' => $sucursal->telefono,
            'whatsapp' => $sucursal->whatsapp,
            'redes' => (object) ($sucursal->redes ?? []),
            'horario' => $sucursal->horario ?? [],
            'foto_url' => $sucursal->fotoUrl(),
            'mapa_url' => $sucursal->mapa_url,
        ];
    }
}
