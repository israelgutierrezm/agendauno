<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalcularNominaTenant;
use App\Modules\Tenancy\Application\VerificarAgendaTenant;
use App\Modules\Tenancy\Models\AsignacionSesionTenant;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Nomina\TipoPago;
use App\Modules\Tenancy\RolSesionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Staff multi + sustitucion + nomina del estudio (R17): asigna varios miembros del
 * staff a una sesion (con rol y sustitucion), define su esquema de pago y calcula la
 * nomina de un periodo. Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class StaffTenantController
{
    public function __construct(
        private readonly CalcularNominaTenant $nomina,
        private readonly VerificarAgendaTenant $agenda,
    ) {}

    public function asignar(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();
        $validado = $request->validate([
            'usuario_id' => ['required', 'string'],
            'rol' => ['required', Rule::enum(RolSesionTenant::class)],
            'sustituye_a' => ['nullable', 'string'],
        ]);

        $usuario = Usuario::query()->where('ulid', $validado['usuario_id'])->firstOrFail();
        $sustituyeA = null;
        if (($validado['sustituye_a'] ?? '') !== '') {
            $sustituyeA = (int) Usuario::query()->where('ulid', $validado['sustituye_a'])->firstOrFail()->getKey();
        }

        $rol = RolSesionTenant::from((string) $validado['rol']);
        $asignacion = DB::connection('tenant')->transaction(function () use ($sesion, $usuario, $rol, $sustituyeA): AsignacionSesionTenant {
            // Nadie puede estar en dos clases a la vez (imparta, sustituya o asista):
            // se revalida bajo su candado.
            $this->agenda->bloquear((int) $usuario->getKey(), null);
            $this->agenda->exigirSinConflictos((int) $usuario->getKey(), null, $sesion->inicia_en, $sesion->termina_en, (int) $sesion->getKey());

            return AsignacionSesionTenant::query()->updateOrCreate(
                ['sesion_id' => $sesion->getKey(), 'usuario_id' => $usuario->getKey()],
                ['rol' => $rol->value, 'sustituye_a' => $sustituyeA],
            );
        });

        return response()->json(['data' => $this->presentarAsignacion($asignacion->load('usuario'))], 201);
    }

    public function staffDeSesion(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();

        $asignaciones = AsignacionSesionTenant::query()->where('sesion_id', $sesion->getKey())->with('usuario')->get();

        return response()->json([
            'data' => $asignaciones->map(fn (AsignacionSesionTenant $a): array => $this->presentarAsignacion($a))->all(),
        ]);
    }

    public function esquemaPago(Request $request): JsonResponse
    {
        $usuario = Usuario::query()->where('ulid', (string) $request->route('usuario'))->firstOrFail();
        $validado = $request->validate([
            'tipo' => ['required', Rule::enum(TipoPago::class)],
            'monto_minor' => ['required', 'integer', 'min:0'],
            'moneda' => ['required', 'string', 'size:3'],
        ]);

        $esquema = EsquemaPagoTenant::query()->updateOrCreate(
            ['usuario_id' => $usuario->getKey()],
            ['tipo' => $validado['tipo'], 'monto_minor' => (int) $validado['monto_minor'], 'moneda' => $validado['moneda'], 'activo' => true],
        );

        return response()->json(['data' => [
            'usuario' => $usuario->ulid,
            'tipo' => $esquema->tipo->value,
            'monto_minor' => $esquema->monto_minor,
            'moneda' => $esquema->moneda,
        ]], 201);
    }

    public function nomina(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);

        return response()->json([
            'data' => $this->nomina->calcular($validado['desde'], $validado['hasta']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarAsignacion(AsignacionSesionTenant $asignacion): array
    {
        return [
            'id' => $asignacion->ulid,
            'usuario' => $asignacion->usuario?->name,
            'rol' => $asignacion->rol->value,
            'sustituye_a' => $asignacion->sustituye_a,
        ];
    }
}
