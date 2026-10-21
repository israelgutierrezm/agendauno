<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CobrarOrdenTenant;
use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Application\PresupuestosTenant;
use App\Modules\Tenancy\Mail\CorreoPresupuestoRespondido;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\LineaPresupuestoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PresupuestoTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\Presupuestos\EstadoPresupuesto;
use App\Modules\Tenancy\Presupuestos\TipoLineaPresupuesto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Presupuestos en línea (ADR 0127, ajuste): el cliente ve su presupuesto, lo acepta o
 * lo rechaza y lo paga en línea, desde el enlace de su correo (`?t=`, sin cuenta) o
 * desde su cuenta (web o app: `/mi/presupuestos`).
 *
 * - Aceptarlo lo vuelve su orden de cobro (Por cobrar): la paga en línea con la
 *   pasarela del negocio o en el negocio. Sus servicios se agendan aparte.
 * - Se acepta lo que se vio (`revision`); uno vencido o ya respondido no se responde.
 * - Al responder se le avisa al negocio por correo.
 */
class PresupuestoEnLineaController
{
    public function __construct(
        private readonly PresupuestosTenant $presupuestos,
        private readonly CobrarOrdenTenant $cobrar,
        private readonly RegistroDePasarelasTenant $pasarelas,
        private readonly CuentaEnUsoTenant $cuenta,
    ) {}

    // ---- Desde el enlace del correo ----

    public function ver(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->delEnlace($request))]);
    }

    public function aceptar(Request $request): JsonResponse
    {
        return $this->responderAceptar($request, $this->delEnlace($request));
    }

    public function rechazar(Request $request): JsonResponse
    {
        return $this->responderRechazar($request, $this->delEnlace($request));
    }

    public function pagar(Request $request): JsonResponse
    {
        $presupuesto = $this->delEnlace($request);

        // Vuelve a la página del presupuesto (la web guarda su token antes de salir).
        return $this->responderPagar($request, $presupuesto, '/presupuesto/'.$this->estudio($request)->slug.'/'.$presupuesto->ulid);
    }

    // ---- Desde su cuenta ----

    public function mios(Request $request): JsonResponse
    {
        $persona = $this->titular($request);
        $lista = PresupuestoTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [EstadoPresupuesto::Enviado->value, EstadoPresupuesto::Aceptado->value, EstadoPresupuesto::Rechazado->value, EstadoPresupuesto::Convertido->value])
            ->with(['lineas', 'orden', 'sucursal'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $lista->map(fn (PresupuestoTenant $p): array => $this->presentar($p))->all()]);
    }

    public function miVer(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->mio($request))]);
    }

    public function miAceptar(Request $request): JsonResponse
    {
        return $this->responderAceptar($request, $this->mio($request));
    }

    public function miRechazar(Request $request): JsonResponse
    {
        return $this->responderRechazar($request, $this->mio($request));
    }

    public function miPagar(Request $request): JsonResponse
    {
        return $this->responderPagar($request, $this->mio($request), '/mi-cuenta/pagos');
    }

    // ---- Lo común ----

    private function responderAceptar(Request $request, PresupuestoTenant $presupuesto): JsonResponse
    {
        $datos = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->exigirRespondible($presupuesto);
        $this->presupuestos->aceptarEnLinea($presupuesto, (int) $datos['revision']);
        $presupuesto->refresh();
        $this->avisarAlNegocio($request, $presupuesto, true);

        return response()->json(['data' => $this->presentar($presupuesto)]);
    }

    private function responderRechazar(Request $request, PresupuestoTenant $presupuesto): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $this->exigirRespondible($presupuesto);
        $this->presupuestos->rechazar($presupuesto, $datos['motivo'] ?? null, null, 'cliente');
        $this->avisarAlNegocio($request, $presupuesto, false, $datos['motivo'] ?? null);

        return response()->json(['data' => $this->presentar($presupuesto->refresh())]);
    }

    /** Paga en línea la orden del presupuesto aceptado (con la pasarela del negocio). */
    private function responderPagar(Request $request, PresupuestoTenant $presupuesto, string $regreso): JsonResponse
    {
        $datos = $request->validate(['idempotency_key' => ['nullable', 'string', 'max:255']]);
        $orden = $presupuesto->orden;
        if (! $orden instanceof OrdenTenant || $orden->estado !== EstadoOrden::Pendiente) {
            throw ValidationException::withMessages(['presupuesto' => ['Este presupuesto no tiene nada por pagar.']]);
        }
        $proveedor = $this->pasarelas->enLinea();
        if ($proveedor === null) {
            throw ValidationException::withMessages(['presupuesto' => ['Este negocio todavía no cobra en línea; paga en el negocio.']]);
        }
        $key = ($datos['idempotency_key'] ?? '') !== '' ? (string) $datos['idempotency_key'] : null;
        $pago = $this->cobrar->ejecutar($orden, $proveedor, null, $key, $regreso);

        return response()->json(['data' => [
            'pago' => $pago->ulid,
            'estado' => $pago->estado->value,
            'checkout' => $pago->checkout,
        ]], 201);
    }

    private function exigirRespondible(PresupuestoTenant $presupuesto): void
    {
        if (! $presupuesto->estado->editable()) {
            throw ValidationException::withMessages(['presupuesto' => ['Este presupuesto ya se respondió.']]);
        }
        if ($this->presupuestos->vencido($presupuesto)) {
            throw ValidationException::withMessages(['presupuesto' => ['Este presupuesto ya venció: pide al negocio uno nuevo.']]);
        }
    }

    private function avisarAlNegocio(Request $request, PresupuestoTenant $presupuesto, bool $aceptado, ?string $motivo = null): void
    {
        $estudio = $this->estudio($request);
        $correo = trim((string) $estudio->contacto_email);
        if ($correo === '') {
            return;
        }
        try {
            Mail::to($correo)->send(new CorreoPresupuestoRespondido(
                (string) $estudio->slug,
                (string) $estudio->nombre,
                $presupuesto->folioVisible(),
                $presupuesto->persona?->nombreCompleto() ?? (string) $presupuesto->nombre_contacto,
                $aceptado,
                $motivo,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function delEnlace(Request $request): PresupuestoTenant
    {
        $presupuesto = $this->presupuestos->delEnlace((string) $request->route('presupuesto'), (string) $request->input('t', $request->query('t', '')));
        abort_unless($presupuesto instanceof PresupuestoTenant && $presupuesto->estado !== EstadoPresupuesto::Borrador, 404);

        return $presupuesto;
    }

    private function mio(Request $request): PresupuestoTenant
    {
        return PresupuestoTenant::query()
            ->where('ulid', (string) $request->route('presupuesto'))
            ->where('persona_id', $this->titular($request)->getKey())
            ->where('estado', '!=', EstadoPresupuesto::Borrador->value)
            ->firstOrFail();
    }

    private function titular(Request $request): PersonaTenant
    {
        $persona = $this->cuenta->titular($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        return $persona;
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }

    /**
     * El presupuesto como lo ve el cliente: sin notas internas del negocio ni quién lo
     * hizo.
     *
     * @return array<string, mixed>
     */
    private function presentar(PresupuestoTenant $p): array
    {
        $p->loadMissing(['persona', 'sucursal', 'lineas', 'orden']);
        $orden = $p->orden;
        $vencido = $this->presupuestos->vencido($p);
        $porPagar = $orden instanceof OrdenTenant && $orden->estado === EstadoOrden::Pendiente;
        $ofertas = OfertaTenant::query()
            ->whereIn('id', $p->lineas->where('tipo', TipoLineaPresupuesto::Servicio)->pluck('referencia_id')->filter()->all())
            ->get()->keyBy('id');

        return [
            'id' => $p->ulid,
            'folio' => $p->folioVisible(),
            'estado' => $p->estado->value,
            'vencido' => $vencido,
            'valido_hasta' => $p->valido_hasta->toDateString(),
            'revision' => $p->revision,
            'cliente' => $p->persona?->nombreCompleto() ?? $p->nombre_contacto,
            'sucursal' => $p->sucursal === null ? null : ['id' => $p->sucursal->ulid, 'nombre' => $p->sucursal->nombre],
            'moneda' => $p->moneda,
            'impuestos' => $p->impuestos->value,
            'tasa_impuesto' => PresupuestosTenant::porcentaje($p->tasa_impuesto_bp),
            'subtotal_minor' => $p->subtotal_minor,
            'descuento_minor' => $p->descuento_minor,
            'impuesto_minor' => $p->impuesto_minor,
            'total_minor' => $p->total_minor,
            'notas' => $p->notas,
            'respondido_en' => $p->respondido_en?->toIso8601String(),
            'respondido_por' => $p->respondido_por,
            'lineas' => $p->lineas->map(fn (LineaPresupuestoTenant $l): array => [
                'tipo' => $l->tipo->value,
                'descripcion' => $l->descripcion,
                'cantidad' => $l->cantidad,
                'precio_unitario_minor' => $l->precio_unitario_minor,
                'descuento_minor' => $l->descuento_minor,
                'subtotal_minor' => $l->subtotal_minor,
            ])->all(),
            // Los servicios que puede agendar (su identificador público).
            'servicios' => $p->lineas->where('tipo', TipoLineaPresupuesto::Servicio)
                ->map(fn (LineaPresupuestoTenant $l): ?array => ($o = $ofertas->get($l->referencia_id)) instanceof OfertaTenant ? ['id' => $o->ulid, 'nombre' => $o->nombre] : null)
                ->filter()->unique('id')->values()->all(),
            'puede_responder' => $p->estado->editable() && ! $vencido,
            'orden' => $orden === null ? null : ['id' => $orden->ulid, 'estado' => $orden->estado->value],
            'puede_pagar' => $porPagar && $this->pasarelas->enLinea() !== null,
        ];
    }
}
