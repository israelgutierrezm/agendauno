<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcanceClientesTenant;
use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Application\FacturarCitasEnAutomatico;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Facturacion\CatalogosSat;
use App\Modules\Tenancy\Models\DatosFiscalesPersonaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Los datos para facturarle a un cliente (el receptor del CFDI): los guarda el cliente
 * en su cuenta (`/mi/datos-fiscales`) o el negocio en su ficha
 * (`/miembros/{persona}/datos-fiscales`). Con `factura_automatica`, sus pagos de citas
 * se facturan solos ({@see FacturarCitasEnAutomatico}); al facturar a mano, el borrador
 * los toma.
 */
class DatosFiscalesClienteController
{
    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly AlcanceClientesTenant $alcance,
        private readonly CuentaEnUsoTenant $cuenta,
        private readonly FacturarCitasEnAutomatico $automatica,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function ficha(Request $request): JsonResponse
    {
        return $this->responder($this->personaDeLaFicha($request));
    }

    public function guardarFicha(Request $request): JsonResponse
    {
        $persona = $this->personaDeLaFicha($request);
        $this->guardar($request, $persona);

        return $this->responder($persona);
    }

    public function mios(Request $request): JsonResponse
    {
        return $this->responder($this->titular($request));
    }

    public function guardarMios(Request $request): JsonResponse
    {
        $persona = $this->titular($request);
        $this->guardar($request, $persona);

        return $this->responder($persona);
    }

    public function borrarMios(Request $request): JsonResponse
    {
        $persona = $this->titular($request);
        DatosFiscalesPersonaTenant::query()->where('persona_id', $persona->getKey())->delete();

        return response()->json(null, 204);
    }

    private function guardar(Request $request, PersonaTenant $persona): void
    {
        $datos = $request->validate([
            'rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/i'],
            'nombre' => ['required', 'string', 'max:255'],
            'codigo_postal' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            'regimen_fiscal' => ['required', 'string', Rule::in(CatalogosSat::REGIMENES)],
            'uso_cfdi' => ['required', 'string', Rule::in(CatalogosSat::USOS_CFDI)],
            'email' => ['nullable', 'email', 'max:255'],
            'factura_automatica' => ['boolean'],
        ]);
        // Un uso solo de personas físicas con un RFC de persona moral (12 caracteres).
        if (strlen((string) $datos['rfc']) === 12 && in_array($datos['uso_cfdi'], CatalogosSat::USOS_SOLO_FISICAS, true)) {
            throw ValidationException::withMessages(['uso_cfdi' => ['Ese uso del CFDI es solo para personas físicas y el RFC es de persona moral.']]);
        }
        $fiscales = DatosFiscalesPersonaTenant::query()->firstOrNew(['persona_id' => $persona->getKey()]);
        $fiscales->fill([
            ...$datos,
            'rfc' => mb_strtoupper(trim((string) $datos['rfc'])),
            'nombre' => mb_strtoupper(trim((string) $datos['nombre'])),
            'factura_automatica' => (bool) ($datos['factura_automatica'] ?? false),
        ])->save();
        $actor = $request->attributes->get('usuario_tenant');
        // Sin los datos en la bitácora: solo que cambiaron y si pidió la factura automática.
        $this->auditoria->registrar($actor instanceof Usuario ? $actor : null, 'datos_fiscales_cliente.guardados', 'persona', (string) $persona->ulid, null, ['factura_automatica' => $fiscales->factura_automatica]);
    }

    private function responder(PersonaTenant $persona): JsonResponse
    {
        $fiscales = DatosFiscalesPersonaTenant::query()->where('persona_id', $persona->getKey())->first();

        return response()->json([
            'data' => $fiscales === null ? null : [
                'rfc' => $fiscales->rfc,
                'nombre' => $fiscales->nombre,
                'codigo_postal' => $fiscales->codigo_postal,
                'regimen_fiscal' => $fiscales->regimen_fiscal,
                'uso_cfdi' => $fiscales->uso_cfdi,
                'email' => $fiscales->email,
                'factura_automatica' => $fiscales->factura_automatica,
            ],
            // Si el negocio factura sus citas en automático (para ofrecer pedirlo).
            'meta' => ['automatica_disponible' => $this->automatica->activa()],
        ]);
    }

    private function personaDeLaFicha(Request $request): PersonaTenant
    {
        $persona = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        $actor = $request->attributes->get('usuario_tenant');
        $usuario = $actor instanceof Usuario ? $actor : null;
        abort_unless(
            $usuario === null || $this->acceso->permiteSucursal($usuario, $persona->sucursal_id !== null ? (int) $persona->sucursal_id : null),
            403,
        );
        abort_unless($this->alcance->puedeVer($persona, $usuario), 403);

        return $persona;
    }

    private function titular(Request $request): PersonaTenant
    {
        $persona = $this->cuenta->titular($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        return $persona;
    }
}
