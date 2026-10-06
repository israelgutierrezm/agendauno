<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AsignarSucursalAlPersonalTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Support\EnlaceMapa;
use App\Modules\Tenancy\Support\HorarioSucursal;
use App\Modules\Tenancy\Support\RedesSociales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function actualizarSucursal(Request $request): JsonResponse
    {
        $sucursal = SucursalTenant::query()->where('ulid', (string) $request->route('sucursal'))->firstOrFail();

        $validado = $this->validarSucursal($request, obligarNombre: false);

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

        return response()->json(['data' => $this->presentarSucursal($sucursal)]);
    }

    public function sucursales(): JsonResponse
    {
        $sucursales = SucursalTenant::query()->orderBy('nombre')->get();

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
            'public',
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
            Storage::disk('public')->delete($sucursal->foto_ruta);
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
