<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Configuracion de pasarelas de pago del estudio (data plane del tenant): el
 * propietario conecta sus propias llaves (Stripe/OpenPay/Mercado Pago) o activa
 * ventanilla. Las credenciales se guardan cifradas y NUNCA se devuelven: solo se
 * informa que llaves estan configuradas. Cobro real: cuando el estudio cargue llaves.
 */
class PasarelasTenantController
{
    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly GestorDeConexionTenant $gestor,
        private readonly RegistrarAuditoria $auditoria,
        private readonly RegionNegocioTenant $region,
    ) {}

    /**
     * Proveedores configurables por el estudio (los integrados manual/simulada no
     * requieren configuracion).
     *
     * @return list<string>
     */
    private function configurables(): array
    {
        return array_merge(ProveedorPasarela::enLinea(), [ProveedorPasarela::Ventanilla->value]);
    }

    public function index(): JsonResponse
    {
        $configs = ConfiguracionPasarelaTenant::query()->get()->keyBy('proveedor');

        $data = array_map(
            fn (string $proveedor): array => $this->presentar($proveedor, $configs->get($proveedor)),
            $this->configurables(),
        );

        return response()->json([
            'data' => $data,
            // Fuera de pesos mexicanos no se cobra en línea (ADR 0099).
            'meta' => [
                'en_linea_disponible' => $this->region->enPesos(),
                'motivo' => $this->region->enPesos() ? null : RegionNegocioTenant::MOTIVO_PASARELAS,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(string $proveedor, ?ConfiguracionPasarelaTenant $config): array
    {
        $enLinea = in_array($proveedor, ProveedorPasarela::enLinea(), true);

        return [
            'proveedor' => $proveedor,
            'activa' => $config instanceof ConfiguracionPasarelaTenant ? $config->activa : false,
            'modo' => $config instanceof ConfiguracionPasarelaTenant ? $config->modo : 'test',
            'llaves_configuradas' => $config instanceof ConfiguracionPasarelaTenant
                ? array_keys($config->llaves())
                : [],
            'disponible' => ProveedorPasarela::disponible($proveedor),
            // ¿Ya cobra? (activa y con sus llaves)
            'lista' => $this->registro->activa($proveedor),
            // A dónde manda sus avisos la pasarela (se registra en su tablero).
            'webhook_url' => $enLinea ? route('api.v1.webhooks.tenant', [
                'estudio' => (string) $this->gestor->actual()?->slug,
                'proveedor' => $proveedor,
            ]) : null,
            // OpenPay: el código que manda al registrar el webhook.
            'codigo_verificacion' => $proveedor === 'openpay' && $config instanceof ConfiguracionPasarelaTenant
                ? $config->codigo_verificacion
                : null,
        ];
    }

    public function upsert(Request $request): JsonResponse
    {
        $proveedor = (string) $request->route('proveedor');
        abort_unless(in_array($proveedor, $this->configurables(), true), 404);

        $validado = $request->validate([
            'activa' => ['required', 'boolean'],
            'modo' => ['required', Rule::in(['test', 'live'])],
            'credenciales' => ['nullable', 'array'],
            'credenciales.*' => ['nullable', 'string'],
        ]);

        // Las de cobro en línea solo en pesos mexicanos: ni se cargan sus llaves.
        if (in_array($proveedor, ProveedorPasarela::enLinea(), true) && ! $this->region->enPesos()) {
            throw ValidationException::withMessages(['proveedor' => [RegionNegocioTenant::MOTIVO_PASARELAS]]);
        }
        if ((bool) $validado['activa'] && ! ProveedorPasarela::disponible($proveedor)) {
            throw ValidationException::withMessages(['activa' => ['Esta pasarela aún no está disponible.']]);
        }

        $config = ConfiguracionPasarelaTenant::query()->firstOrNew(['proveedor' => $proveedor]);
        // Para la bitácora: estado y NOMBRES de las llaves (nunca sus valores).
        $antes = $config->exists
            ? ['proveedor' => $proveedor, 'activa' => $config->activa, 'modo' => $config->modo, 'llaves_configuradas' => array_keys($config->llaves())]
            : null;
        $config->activa = (bool) $validado['activa'];
        $config->modo = (string) $validado['modo'];

        // Merge: solo actualiza las llaves provistas con valor; conserva las demas.
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

        $actor = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar($actor instanceof Usuario ? $actor : null, 'pasarela.configurada', 'pasarela', $proveedor, $antes, [
            'proveedor' => $proveedor,
            'activa' => $config->activa,
            'modo' => $config->modo,
            'llaves_configuradas' => array_keys($config->llaves()),
        ]);

        return response()->json(['data' => $this->presentar($proveedor, $config)]);
    }
}
