<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Acceso\MetodoAcceso;
use App\Modules\Tenancy\Application\PaseAccesoTenant;
use App\Modules\Tenancy\Application\RegistrarAccesoTenant;
use App\Modules\Tenancy\Models\AccesoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Control de acceso del estudio (R12): la puerta/recepcion registra un intento de
 * entrada (credencial QR/PIN/NFC) y el motor decide (reserva vigente u OPEN_ACCESS),
 * dejando la bitacora. Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class AccesosTenantController
{
    private const LIMITE = 200;

    public function __construct(
        private readonly RegistrarAccesoTenant $accesos,
        private readonly PaseAccesoTenant $pases,
    ) {}

    public function registrar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            // El pase QR del alumno (firmado y con vencimiento) o, a mano, su ulid.
            'codigo' => ['required_without:persona_id', 'nullable', 'string', 'max:200'],
            'persona_id' => ['required_without:codigo', 'nullable', 'string'],
            'metodo' => ['required_without:codigo', 'nullable', Rule::enum(MetodoAcceso::class)],
            'sucursal_id' => ['nullable', 'string'],
        ]);

        $codigo = $validado['codigo'] ?? null;
        if (is_string($codigo) && $codigo !== '') {
            $persona = $this->pases->resolver($codigo);
            $metodo = MetodoAcceso::Qr;
        } else {
            $persona = PersonaTenant::query()->where('ulid', (string) $validado['persona_id'])->firstOrFail();
            $metodo = MetodoAcceso::from((string) $validado['metodo']);
        }
        $sucursalId = null;
        if (($validado['sucursal_id'] ?? '') !== '') {
            $sucursalId = (int) SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail()->getKey();
        }

        $acceso = $this->accesos->registrar($persona, $metodo, $sucursalId, Carbon::now());

        return response()->json(['data' => $this->presentar($acceso)], 201);
    }

    public function index(): JsonResponse
    {
        $accesos = AccesoTenant::query()->with('persona')->orderByDesc('id')->limit(self::LIMITE)->get();

        return response()->json([
            'data' => $accesos->map(fn (AccesoTenant $a): array => $this->presentar($a))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(AccesoTenant $acceso): array
    {
        return [
            'id' => $acceso->ulid,
            'persona' => $acceso->persona?->nombre,
            'persona_id' => $acceso->persona?->ulid,
            'persona_nombre' => $acceso->persona?->nombreCompleto(),
            'metodo' => $acceso->metodo->value,
            'resultado' => $acceso->resultado->value,
            'permitido' => $acceso->resultado->value === 'permitido',
            'codigo' => $acceso->codigo,
            'registrado_en' => $acceso->registrado_en->toIso8601String(),
        ];
    }
}
