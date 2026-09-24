<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\ConfiguracionPasarelaTenant;
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
    public function __construct(private readonly RegistroDePasarelasTenant $registro) {}

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

        $data = array_map(function (string $proveedor) use ($configs): array {
            $config = $configs->get($proveedor);

            return [
                'proveedor' => $proveedor,
                'activa' => $config instanceof ConfiguracionPasarelaTenant ? $config->activa : false,
                'modo' => $config instanceof ConfiguracionPasarelaTenant ? $config->modo : 'test',
                'llaves_configuradas' => $config instanceof ConfiguracionPasarelaTenant
                    ? array_keys($config->llaves())
                    : [],
                // ¿Existe de verdad? (OpenPay y Mercado Pago aún no.)
                'disponible' => ProveedorPasarela::disponible($proveedor),
                // ¿Ya cobra? (activa y con lo necesario, p. ej. la llave de Stripe)
                'lista' => $this->registro->activa($proveedor),
            ];
        }, $this->configurables());

        return response()->json(['data' => $data]);
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

        if ((bool) $validado['activa'] && ! ProveedorPasarela::disponible($proveedor)) {
            throw ValidationException::withMessages(['activa' => ['Esta pasarela aún no está disponible.']]);
        }

        $config = ConfiguracionPasarelaTenant::query()->firstOrNew(['proveedor' => $proveedor]);
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

        return response()->json(['data' => [
            'proveedor' => $proveedor,
            'activa' => $config->activa,
            'modo' => $config->modo,
            'llaves_configuradas' => array_keys($config->llaves()),
            'disponible' => ProveedorPasarela::disponible($proveedor),
            'lista' => $this->registro->activa($proveedor),
        ]]);
    }
}
