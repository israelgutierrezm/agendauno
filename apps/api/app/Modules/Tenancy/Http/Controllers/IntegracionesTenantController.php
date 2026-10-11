<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Exceptions\IntegracionSoloMexico;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ProveedorPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuracion de integraciones de bienestar del estudio (Wellhub / TotalPass): el
 * propietario conecta sus credenciales. Se guardan cifradas y NUNCA se devuelven:
 * solo se informa que llaves estan configuradas. Solo propietario, solo negocios de
 * clases (ADR 0104) y solo en México, donde operan. Encenderla, apagarla o cambiar
 * una llave queda en la bitácora con los NOMBRES de las llaves, nunca sus valores (M10).
 */
class IntegracionesTenantController
{
    public function __construct(
        private readonly RegionNegocioTenant $region,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function index(): JsonResponse
    {
        $this->exigirMexico();
        $configs = IntegracionTenant::query()->get()->keyBy('proveedor');

        $data = array_map(function (ProveedorPartner $proveedor) use ($configs): array {
            $config = $configs->get($proveedor->value);

            return [
                'proveedor' => $proveedor->value,
                'activa' => $config instanceof IntegracionTenant ? $config->activa : false,
                // Las que pide el proveedor y las que ya se capturaron.
                'llaves' => $proveedor->credenciales(),
                'llaves_configuradas' => $config instanceof IntegracionTenant ? array_keys($config->llaves()) : [],
            ];
        }, ProveedorPartner::cases());

        return response()->json(['data' => $data]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $this->exigirMexico();
        $proveedor = ProveedorPartner::tryFrom((string) $request->route('proveedor'));
        abort_if($proveedor === null, 404);

        $validado = $request->validate([
            'activa' => ['required', 'boolean'],
            // Solo las credenciales que pide este proveedor.
            'credenciales' => ['nullable', 'array:'.implode(',', $proveedor->credenciales())],
            'credenciales.*' => ['nullable', 'string', 'max:500'],
        ]);

        $config = IntegracionTenant::query()->firstOrNew(['proveedor' => $proveedor->value]);
        $antes = ['activa' => $config->exists && $config->activa, 'llaves' => array_keys($config->llaves())];
        $config->activa = (bool) $validado['activa'];
        $cambiadas = [];

        // Merge: solo actualiza las llaves provistas con valor; conserva las demas.
        $credenciales = $validado['credenciales'] ?? null;
        if (is_array($credenciales)) {
            $nuevas = [];
            foreach ($credenciales as $nombre => $valor) {
                if (is_string($valor) && $valor !== '') {
                    $nuevas[(string) $nombre] = trim($valor);
                }
            }
            $actuales = $config->llaves();
            $cambiadas = array_keys(array_filter($nuevas, static fn (string $valor, string $nombre): bool => ($actuales[$nombre] ?? null) !== $valor, ARRAY_FILTER_USE_BOTH));
            $config->credenciales = array_merge($actuales, $nuevas);
        }

        $config->save();

        if ($antes['activa'] !== $config->activa || $cambiadas !== []) {
            $usuario = $request->attributes->get('usuario_tenant');
            $this->auditoria->registrar(
                $usuario instanceof Usuario ? $usuario : null,
                'integracion.actualizada',
                'integracion',
                $proveedor->value,
                $antes,
                ['activa' => $config->activa, 'llaves' => array_keys($config->llaves()), 'llaves_cambiadas' => $cambiadas],
            );
        }

        return response()->json(['data' => [
            'proveedor' => $proveedor->value,
            'activa' => $config->activa,
            'llaves' => $proveedor->credenciales(),
            'llaves_configuradas' => array_keys($config->llaves()),
        ]]);
    }

    private function exigirMexico(): void
    {
        if (! $this->region->enMexico()) {
            throw new IntegracionSoloMexico('Wellhub y TotalPass solo están disponibles para negocios en México.');
        }
    }
}
