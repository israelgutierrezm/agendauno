<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalcularNominaTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\MargenesServicio;
use App\Modules\Tenancy\Application\ModalidadNegocioTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\VerificarAgendaTenant;
use App\Modules\Tenancy\Models\AsignacionSesionTenant;
use App\Modules\Tenancy\Models\CorteNominaTenant;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\EsquemaPagoVersionTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Nomina\TipoPago;
use App\Modules\Tenancy\RolSesionTenant;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        private readonly AccesoSesionTenant $acceso,
    ) {}

    public function asignar(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();
        $actor = $request->attributes->get('usuario_tenant');
        // Solo en clases de sus sedes (R19).
        abort_unless($this->acceso->puedeOperar($sesion, $actor instanceof Usuario ? $actor : null), 403, 'Esa clase no es de tus sucursales.');
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
            $this->agenda->exigirSinConflictos((int) $usuario->getKey(), null, $sesion->inicia_en, $sesion->termina_en, (int) $sesion->getKey(), margenes: MargenesServicio::deSesion($sesion));

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
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless($this->acceso->puedeOperar($sesion, $actor instanceof Usuario ? $actor : null), 403, 'Esa clase no es de tus sucursales.');

        $asignaciones = AsignacionSesionTenant::query()->where('sesion_id', $sesion->getKey())->with('usuario')->get();

        return response()->json([
            'data' => $asignaciones->map(fn (AsignacionSesionTenant $a): array => $this->presentarAsignacion($a))->all(),
        ]);
    }

    /**
     * Fija el esquema de pago de alguien del equipo. Cambiarlo no recalcula lo ya
     * trabajado (ADR 0153): queda una versión nueva que vale desde `vigente_desde` (por
     * omisión, hoy en el negocio). La primera vale desde siempre.
     */
    public function esquemaPago(Request $request, FechasNegocioTenant $fechas): JsonResponse
    {
        $usuario = Usuario::query()->where('ulid', (string) $request->route('usuario'))->firstOrFail();
        $validado = $request->validate([
            'tipo' => ['required', Rule::enum(TipoPago::class)],
            // Por porcentaje (citas, ADR 0219): el porcentaje, con hasta dos decimales.
            'monto_minor' => ['required_unless:tipo,'.TipoPago::PorcentajeVenta->value, 'nullable', 'integer', 'min:0'],
            'porcentaje' => ['required_if:tipo,'.TipoPago::PorcentajeVenta->value, 'nullable', 'numeric', 'min:0.01', 'max:100', 'decimal:0,2'],
            'moneda' => ['nullable', 'string', 'size:3', app(RegionNegocioTenant::class)->reglaMoneda()],
            'vigente_desde' => ['nullable', 'date_format:Y-m-d'],
        ]);
        // Lo ya cerrado en un corte no cambia de tarifa (ADR 0166): la nueva vale después
        // del último periodo cerrado de esta persona; si no, el corte mostraría como
        // diferencia por pagar un periodo ya pagado.
        $cerradoHasta = CorteNominaTenant::query()
            ->whereHas('lineas', fn ($q) => $q->where('usuario_id', $usuario->getKey()))
            ->max('hasta');
        $desde = (string) ($validado['vigente_desde'] ?? $fechas->hoy());
        $hayVersiones = EsquemaPagoVersionTenant::query()->where('usuario_id', $usuario->getKey())->exists();
        if ($hayVersiones && $cerradoHasta !== null && $desde <= substr((string) $cerradoHasta, 0, 10)) {
            $siguiente = CarbonImmutable::parse(substr((string) $cerradoHasta, 0, 10))->addDay();
            throw ValidationException::withMessages(['vigente_desde' => [
                'La nómina de esta persona ya se cerró hasta el '.$siguiente->subDay()->format('d/m/Y')
                .': la tarifa nueva puede valer desde el '.$siguiente->format('d/m/Y').'.',
            ]]);
        }
        $porcentaje = $validado['tipo'] === TipoPago::PorcentajeVenta->value;
        if ($porcentaje && ! app(ModalidadNegocioTenant::class)->esCitas()) {
            throw ValidationException::withMessages(['tipo' => ['La comisión en porcentaje es de los negocios de citas: lo que se cobra por cada cita.']]);
        }
        $datos = [
            'tipo' => $validado['tipo'],
            'monto_minor' => $porcentaje ? 0 : (int) $validado['monto_minor'],
            'porcentaje_bp' => $porcentaje ? (int) round(((float) $validado['porcentaje']) * 100) : null,
            'moneda' => mb_strtoupper((string) ($validado['moneda'] ?? app(ParametrosTenant::class)->moneda())),
        ];

        [$esquema, $version] = DB::connection('tenant')->transaction(function () use ($usuario, $datos, $validado, $fechas): array {
            $esquema = EsquemaPagoTenant::query()->updateOrCreate(['usuario_id' => $usuario->getKey()], $datos + ['activo' => true]);
            $primera = ! EsquemaPagoVersionTenant::query()->where('usuario_id', $usuario->getKey())->exists();
            $version = EsquemaPagoVersionTenant::query()->updateOrCreate(
                ['usuario_id' => $usuario->getKey(), 'vigente_desde' => $primera ? null : ($validado['vigente_desde'] ?? $fechas->hoy())],
                $datos,
            );

            return [$esquema, $version];
        });

        return response()->json(['data' => [
            'usuario' => $usuario->ulid,
            'tipo' => $esquema->tipo->value,
            'monto_minor' => $esquema->monto_minor,
            'porcentaje_bp' => $esquema->porcentaje_bp,
            'moneda' => $esquema->moneda,
            'vigente_desde' => $version->vigente_desde?->toDateString(),
        ]], 201);
    }

    /**
     * Mis comisiones (ADR 0219): lo que el profesional lleva ganado en el periodo con
     * su esquema (devengado y por venir) y sus propinas. Solo lo suyo.
     */
    public function misComisiones(Request $request): JsonResponse
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);
        abort_unless($usuario->esProfesional(), 403, 'Solo quien atiende tiene comisiones.');
        $validado = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);
        $mias = array_values(array_filter(
            $this->nomina->calcular($validado['desde'], $validado['hasta']),
            static fn (array $fila): bool => $fila['usuario_id'] === $usuario->ulid,
        ));

        return response()->json(['data' => $mias]);
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
