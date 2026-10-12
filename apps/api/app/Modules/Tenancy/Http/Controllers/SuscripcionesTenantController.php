<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\DomiciliacionesTenant;
use App\Modules\Tenancy\Application\FamiliasTenant;
use App\Modules\Tenancy\Application\MembresiasPorModalidadTenant;
use App\Modules\Tenancy\Application\RegistrarEventoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\DomiciliacionNoPermitida;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suscripciones recurrentes del estudio (Etapa 2): membresías con cobro recurrente
 * (llevan `proxima_cobro_en`), para dar visibilidad de las próximas renovaciones que
 * el scheduler cobrará y de cuáles se cobran solas (pago automático) o se avisan
 * para pagar a mano. El cobro lo ejecuta el comando `agendauno:cobrar-suscripciones`.
 *
 * El negocio no captura tarjetas: invita al alumno a activar su pago automático (él
 * lo autoriza en la pasarela) y puede quitarlo si el alumno lo pide. La de una persona
 * a cargo la puede pagar su responsable con su tarjeta (`paga`, ADR 0129).
 */
class SuscripcionesTenantController
{
    private const LIMITE = 200;

    public function __construct(
        private readonly DomiciliacionesTenant $domiciliaciones,
        private readonly RegistrarEventoTenant $eventos,
        private readonly GestorDeConexionTenant $gestor,
        private readonly FamiliasTenant $familias,
    ) {}

    public function index(): JsonResponse
    {
        $acuerdos = AcuerdoTenant::query()
            ->whereNotNull('proxima_cobro_en')
            ->where('estado', '!=', EstadoAcuerdo::Cancelado->value)
            ->with(['persona', 'producto', 'domiciliacion.persona'])
            ->orderBy('proxima_cobro_en')
            ->limit(self::LIMITE)
            ->get();
        // Pagos automáticos que se quitaron y la pasarela aún no confirma (ADR 0134).
        $cancelando = DomiciliacionTenant::query()
            ->whereIn('acuerdo_id', $acuerdos->modelKeys())
            ->where('estado', DomiciliacionTenant::CANCELANDO)
            ->pluck('acuerdo_id')
            ->all();
        $domiciliaciones = $this->domiciliaciones;

        return response()->json([
            'data' => $acuerdos->map(static fn (AcuerdoTenant $a): array => [
                'id' => $a->ulid,
                'persona' => $a->persona?->nombreCompleto(),
                'producto' => $a->producto?->nombre,
                'precio_minor' => $a->producto?->precio_minor,
                'moneda' => $a->producto?->moneda,
                'proxima_cobro_en' => $a->proxima_cobro_en?->toDateString(),
                'estado' => $a->estado->value,
                'cancelando_pago_automatico' => in_array($a->getKey(), $cancelando, false),
                'pago_automatico' => $a->domiciliacion instanceof DomiciliacionTenant
                    ? [
                        ...$a->domiciliacion->tarjeta(),
                        'error' => $a->domiciliacion->ultimo_error,
                        // Lo que autorizó (su precio contratado) y el nuevo, si cambió.
                        'monto_minor' => $domiciliaciones->precioContratado($a)['monto_minor'] ?? null,
                        'precio_nuevo_minor' => $domiciliaciones->nuevoPrecio($a),
                        // La paga su responsable con su tarjeta (ADR 0129).
                        'paga' => (int) $a->domiciliacion->persona_id !== (int) $a->persona_id
                            ? $a->domiciliacion->persona?->nombreCompleto()
                            : null,
                    ]
                    : null,
            ])->all(),
            'pago_automatico_disponible' => $this->domiciliaciones->proveedor() !== null,
        ]);
    }

    /**
     * Correo al alumno con el enlace a su cuenta para activar el pago automático.
     */
    public function solicitarPagoAutomatico(Request $request): JsonResponse
    {
        $acuerdo = $this->acuerdo($request);
        if (app(MembresiasPorModalidadTenant::class)->enCitas()) {
            throw new DomiciliacionNoPermitida('En un negocio de citas no hay pago automático: los bonos se pagan al comprarlos.');
        }
        if ($this->domiciliaciones->proveedor() === null) {
            throw new DomiciliacionNoPermitida('Para ofrecer pago automático, conecta primero una pasarela en línea.');
        }
        if (! DomiciliacionesTenant::renovable($acuerdo)) {
            throw new DomiciliacionNoPermitida('Esta membresía no se renueva; no necesita pago automático.');
        }
        // Sin correo propio, le llega a su responsable con autorización vigente (ADR 0129).
        $persona = $acuerdo->persona;
        if ((string) $persona?->email === '' && ($persona === null || $this->familias->responsablesDe($persona)->isEmpty())) {
            throw new DomiciliacionNoPermitida('El alumno no tiene correo registrado.');
        }

        $this->eventos->registrar('pago_automatico.solicitado', 'acuerdo', (string) $acuerdo->ulid, [
            'acuerdo' => (string) $acuerdo->ulid,
            'persona_id' => $acuerdo->persona?->ulid,
            'producto' => (string) $acuerdo->producto?->nombre,
            'enlace' => MarcaProducto::actual()->urlWeb().'/entrar?estudio='.rawurlencode((string) $this->gestor->actual()?->slug),
        ]);

        return response()->json(['data' => ['enviado' => true]]);
    }

    /**
     * Quita el pago automático (p. ej. a petición del alumno): la renovación vuelve a
     * avisarse para pagarla a mano.
     */
    public function quitarPagoAutomatico(Request $request): JsonResponse
    {
        $this->domiciliaciones->desactivar($this->acuerdo($request));

        return response()->json(['data' => ['pago_automatico' => null]]);
    }

    private function acuerdo(Request $request): AcuerdoTenant
    {
        return AcuerdoTenant::query()
            ->where('ulid', (string) $request->route('acuerdo'))
            ->with(['persona', 'producto'])
            ->firstOrFail();
    }
}
