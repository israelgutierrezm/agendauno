<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ConversionesNegocioTenant;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Medicion\ConfigurarDestino;
use App\Modules\Tenancy\Medicion\EstadoEnvioMedicion;
use App\Modules\Tenancy\Medicion\Ga4MeasurementProtocol;
use App\Modules\Tenancy\Medicion\IdentificadoresNavegador;
use App\Modules\Tenancy\Medicion\MetaConversionesApi;
use App\Modules\Tenancy\Medicion\ProveedorMedicion;
use App\Modules\Tenancy\Models\DestinoMedicionTenant;
use App\Modules\Tenancy\Models\EnvioMedicionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * GA4 y Meta de un negocio (ADR 0143): Configuración → Integraciones → Medición, con
 * `integraciones.configurar` y la función del plan. Las reglas de cada destino son las
 * mismas que en el superadmin (ConfigurarDestino); además, el negocio decide si manda
 * eventos adicionales (asistencia, renovación). Los cambios quedan en su auditoría, sin
 * el secreto.
 *
 * Lo público de su página: `GET /medicion` (el ID de su GA4; el píxel de Meta no se
 * carga en el navegador de un negocio) y `POST /medicion/consentimiento`.
 */
class MedicionNegocioTenantController
{
    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    public function index(): JsonResponse
    {
        $destinos = [];
        foreach (ProveedorMedicion::cases() as $proveedor) {
            $destinos[] = self::presentar(DestinoMedicionTenant::query()->firstOrNew(['proveedor' => $proveedor->value]));
        }
        $envios = EnvioMedicionTenant::query()->with('destino')->latest('id')->limit(50)->get()
            ->map(fn (EnvioMedicionTenant $e): array => ConfigurarDestino::presentarEnvio($e, $e->destino?->proveedor))->all();

        return response()->json(['data' => ['destinos' => $destinos, 'envios' => $envios]]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $proveedor = $this->proveedor($request);
        $validado = $request->validate([
            ...ConfigurarDestino::reglas(),
            'eventos_extra' => ['sometimes', 'array'],
            'eventos_extra.*' => ['string', Rule::in(DestinoMedicionTenant::EVENTOS_EXTRA)],
        ]);
        $destino = DestinoMedicionTenant::query()->firstOrNew(['proveedor' => $proveedor->value]);
        $antes = ConfigurarDestino::presentar($destino, $proveedor);
        ConfigurarDestino::aplicar($destino, $proveedor, $validado);
        if (array_key_exists('eventos_extra', $validado)) {
            $destino->eventos_extra = array_values(array_unique($validado['eventos_extra']));
        }
        $actor = $request->attributes->get('usuario_tenant');
        if ($destino->isDirty('aviso_confirmado_en') && $actor instanceof Usuario) {
            $destino->aviso_confirmado_por = (int) $actor->getKey();
        }
        $destino->save();
        $despues = self::presentar($destino);
        $this->auditar($request, 'medicion.destino_guardado', $proveedor, $antes, $despues, ($validado['secreto'] ?? '') !== '');

        return response()->json(['data' => $despues]);
    }

    public function desconectar(Request $request): JsonResponse
    {
        $proveedor = $this->proveedor($request);
        $destino = DestinoMedicionTenant::query()->firstOrNew(['proveedor' => $proveedor->value]);
        if ($destino->exists) {
            $antes = ConfigurarDestino::presentar($destino, $proveedor);
            ConfigurarDestino::desconectar($destino);
            $destino->save();
            EnvioMedicionTenant::query()->where('destino_id', $destino->getKey())
                ->whereIn('estado', [EstadoEnvioMedicion::Pendiente->value, EstadoEnvioMedicion::Error->value])
                ->update(['estado' => EstadoEnvioMedicion::Omitido->value, 'motivo' => 'destino_desconectado', 'proximo_intento_en' => null]);
            $this->auditar($request, 'medicion.destino_desconectado', $proveedor, $antes, self::presentar($destino), false);
        }

        return response()->json(['data' => self::presentar($destino)]);
    }

    public function probar(Request $request, Ga4MeasurementProtocol $ga4, MetaConversionesApi $meta): JsonResponse
    {
        $proveedor = $this->proveedor($request);
        $destino = DestinoMedicionTenant::query()->firstOrNew(['proveedor' => $proveedor->value]);

        return response()->json(['data' => ConfigurarDestino::probar($destino, $proveedor, $ga4, $meta)]);
    }

    /** Lo que la página del negocio necesita: el ID de su GA4, si está encendido. */
    public function publico(Request $request, FuncionesPlan $funciones): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        $ga4 = null;
        // Sin la función en su plan o siendo una demo, su página no mide.
        if ($estudio instanceof Estudio && ! $estudio->es_demo && $funciones->tiene($estudio, 'integraciones')) {
            $destino = DestinoMedicionTenant::query()->where('proveedor', ProveedorMedicion::Ga4->value)->first();
            $ga4 = $destino instanceof DestinoMedicionTenant && $destino->enUso() ? $destino->identificador : null;
        }

        // Meta del negocio solo desde el servidor: su píxel compartiría la cookie del
        // dominio padre con otros negocios.
        return response()->json(['data' => ['ga4' => $ga4, 'meta' => null]]);
    }

    public function consentimiento(Request $request): Response
    {
        $validado = $request->validate([
            'visitante' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'analitica' => ['required', 'boolean'],
            'publicidad' => ['required', 'boolean'],
        ]);
        $visitante = IdentificadoresNavegador::visitante($validado['visitante']);
        if ($visitante !== null) {
            ConversionesNegocioTenant::cambiarPreferencias($visitante, (bool) $validado['analitica'], (bool) $validado['publicidad'], CarbonImmutable::now());
        }

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentar(DestinoMedicionTenant $d): array
    {
        return [...ConfigurarDestino::presentar($d, $d->proveedor), 'eventos_extra' => $d->eventos_extra ?? []];
    }

    /**
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     */
    private function auditar(Request $request, string $accion, ProveedorMedicion $proveedor, array $antes, array $despues, bool $cambioSecreto): void
    {
        $actor = $request->attributes->get('usuario_tenant');
        if (! $actor instanceof Usuario) {
            return;
        }
        $this->auditoria->registrar(
            $actor,
            $accion,
            'destino_medicion',
            $proveedor->value,
            $antes,
            $despues,
            $cambioSecreto ? 'Se cambió el secreto.' : null,
        );
    }

    private function proveedor(Request $request): ProveedorMedicion
    {
        $proveedor = ProveedorMedicion::tryFrom((string) $request->route('proveedor'));
        abort_if($proveedor === null, 404);

        return $proveedor;
    }
}
