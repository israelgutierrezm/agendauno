<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaPlataforma;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ModoCobroSaas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administración de plataforma (PlatformAdmin): el operador de AgendaUno ve todos los
 * estudios y gestiona la configuración global (p. ej. la cuenta FacturAPI usada para
 * timbrar por todos). Opera sobre el control plane (BD compartida); autenticado por
 * token de plataforma. Nunca devuelve secretos.
 */
class PlataformaController
{
    public function estudios(): JsonResponse
    {
        $estudios = Estudio::query()->orderBy('slug')->get();

        return response()->json([
            'data' => $estudios->map(static fn (Estudio $e): array => [
                'slug' => $e->slug,
                'nombre' => $e->nombre,
                'estado' => $e->estado->value,
                'estado_facturacion' => $e->estado_facturacion->value,
                'modo_cobro' => $e->modo_cobro->value,
                'precio_por_alumno_minor' => $e->precio_por_alumno_minor,
                'cuota_fija_minor' => $e->cuota_fija_minor,
                'moneda' => $e->moneda,
                'publicado' => (bool) $e->publicado,
                'pais' => $e->pais,
                'ciudad' => $e->ciudad,
                'creado_en' => $e->created_at?->toIso8601String(),
            ])->all(),
            'total' => $estudios->count(),
        ]);
    }

    /**
     * Ajusta la facturación SaaS de un estudio: modo de cobro (activos/fijo), precio por
     * alumno, cuota fija y estado de facturación. Solo el admin de la plataforma.
     */
    public function actualizarEstudio(Request $request, string $estudio): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();

        $validado = $request->validate([
            'modo_cobro' => ['required', Rule::enum(ModoCobroSaas::class)],
            'precio_por_alumno_minor' => ['required', 'integer', 'min:0'],
            'cuota_fija_minor' => ['required', 'integer', 'min:0'],
            'estado_facturacion' => ['nullable', Rule::enum(EstadoFacturacion::class)],
        ]);

        $modelo->update([
            'modo_cobro' => $validado['modo_cobro'],
            'precio_por_alumno_minor' => (int) $validado['precio_por_alumno_minor'],
            'cuota_fija_minor' => (int) $validado['cuota_fija_minor'],
            'estado_facturacion' => $validado['estado_facturacion'] ?? $modelo->estado_facturacion->value,
        ]);

        return response()->json(['data' => [
            'slug' => $modelo->slug,
            'modo_cobro' => $modelo->modo_cobro->value,
            'precio_por_alumno_minor' => $modelo->precio_por_alumno_minor,
            'cuota_fija_minor' => $modelo->cuota_fija_minor,
            'estado_facturacion' => $modelo->estado_facturacion->value,
        ]]);
    }

    /**
     * Proveedores de pasarela que la plataforma puede activar para cobrar la renta.
     *
     * @return list<string>
     */
    private function proveedores(): array
    {
        return ['stripe', 'mercadopago', 'openpay'];
    }

    /**
     * Estado de las pasarelas de la plataforma (activa/modo + qué llaves están puestas).
     * Nunca devuelve las credenciales.
     */
    public function pasarelas(): JsonResponse
    {
        $configs = ConfiguracionPasarelaPlataforma::query()->get()->keyBy('proveedor');

        $data = array_map(function (string $proveedor) use ($configs): array {
            $config = $configs->get($proveedor);

            return [
                'proveedor' => $proveedor,
                'activa' => $config instanceof ConfiguracionPasarelaPlataforma ? $config->activa : false,
                'modo' => $config instanceof ConfiguracionPasarelaPlataforma ? $config->modo : 'test',
                'llaves_configuradas' => $config instanceof ConfiguracionPasarelaPlataforma ? array_keys($config->llaves()) : [],
            ];
        }, $this->proveedores());

        return response()->json(['data' => $data]);
    }

    /**
     * Activa/configura una pasarela de la plataforma. Las llaves se combinan (solo se
     * actualizan las provistas con valor); nunca se devuelven.
     */
    public function guardarPasarela(Request $request, string $proveedor): JsonResponse
    {
        abort_unless(in_array($proveedor, $this->proveedores(), true), 404);

        $validado = $request->validate([
            'activa' => ['required', 'boolean'],
            'modo' => ['required', Rule::in(['test', 'live'])],
            'credenciales' => ['nullable', 'array'],
            'credenciales.*' => ['nullable', 'string'],
        ]);

        $config = ConfiguracionPasarelaPlataforma::query()->firstOrNew(['proveedor' => $proveedor]);
        $config->activa = (bool) $validado['activa'];
        $config->modo = (string) $validado['modo'];

        // Merge: solo actualiza las llaves con valor; conserva las demás.
        $credenciales = $validado['credenciales'] ?? null;
        if (is_array($credenciales)) {
            $nuevas = [];
            foreach ($credenciales as $nombre => $valor) {
                if (is_string($valor) && $valor !== '') {
                    $nuevas[(string) $nombre] = $valor;
                }
            }
            $config->credenciales = array_merge($config->llaves(), $nuevas);
        }
        $config->save();

        return response()->json(['data' => [
            'proveedor' => $proveedor,
            'activa' => $config->activa,
            'modo' => $config->modo,
            'llaves_configuradas' => array_keys($config->llaves()),
        ]]);
    }

    public function configuracion(): JsonResponse
    {
        return response()->json(['data' => [
            // Nunca se devuelve la llave; solo si está configurada.
            'facturapi_configurada' => ConfiguracionPlataforma::llaveFacturapi() !== null,
        ]]);
    }

    public function guardarConfiguracion(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'facturapi_llave' => ['nullable', 'string', 'max:255'],
        ]);

        ConfiguracionPlataforma::establecer('facturapi_llave', $validado['facturapi_llave'] ?? null);

        return response()->json(['data' => [
            'facturapi_configurada' => ConfiguracionPlataforma::llaveFacturapi() !== null,
        ]]);
    }

    /**
     * Documentos legales de la plataforma (aviso de privacidad y términos) que se
     * muestran en el registro de negocios. Los edita el superadministrador.
     */
    public function legales(): JsonResponse
    {
        return response()->json(['data' => [
            'aviso_privacidad' => ConfiguracionPlataforma::obtener('aviso_privacidad'),
            'terminos' => ConfiguracionPlataforma::obtener('terminos'),
        ]]);
    }

    public function guardarLegales(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'aviso_privacidad' => ['nullable', 'string', 'max:50000'],
            'terminos' => ['nullable', 'string', 'max:50000'],
        ]);

        ConfiguracionPlataforma::establecer('aviso_privacidad', $validado['aviso_privacidad'] ?? null);
        ConfiguracionPlataforma::establecer('terminos', $validado['terminos'] ?? null);

        return $this->legales();
    }
}
