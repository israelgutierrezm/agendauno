<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ficha 360° del alumno: historial consolidado para atención integral desde una sola
 * pantalla — derechos (membresías/packs) con su saldo derivado del ledger, historial
 * de reservas (con asistencia) y de compras (órdenes). El resumen operativo
 * (membresía/saldo/adeudo/alertas/próxima) lo entrega {@see ResumenMiembroTenantController};
 * aquí va lo que ese resumen no cubre, sin duplicar lógica.
 *
 * Lo económico (compras, importes, formas de pago y lo pendiente de pago) solo va a
 * quien puede ver órdenes (`ordenes.ver`): ocultar el botón no basta, el servidor
 * no lo entrega (`null`).
 */
class FichaMiembroTenantController
{
    // Cuántos registros recientes de historial mostrar (reservas y órdenes).
    private const HISTORIAL = 20;

    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly ResolverAccesoTenant $acceso,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Su historial se consulta aunque esté dada de baja.
        $persona = PersonaTenant::withTrashed()
            ->with('sucursal')
            ->where('ulid', (string) $request->route('persona'))
            ->firstOrFail();

        // Alcance por sucursal (R19): un acotado no abre la ficha de un alumno de otra sede.
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless(
            ! $actor instanceof Usuario || $this->acceso->permiteSucursal($actor, $persona->sucursal_id !== null ? (int) $persona->sucursal_id : null),
            403,
        );

        return response()->json(['data' => [
            'persona' => [
                'id' => $persona->ulid,
                'nombre_completo' => $persona->nombreCompleto(),
                'email' => $persona->email,
                'celular' => $persona->celular,
                'tipo' => $persona->tipo->value,
                'activo' => $persona->activo,
                'dado_de_baja_en' => $persona->deleted_at?->toIso8601String(),
                'es_facturable' => $persona->es_facturable,
                'archivado' => $persona->archivado,
                'alta' => $persona->created_at?->toDateString(),
                'sucursal' => $persona->sucursal?->nombre,
            ],
            'derechos' => $this->derechos($persona),
            'reservas' => $this->reservas($persona),
            'ordenes' => $this->veDinero($actor) ? $this->ordenes($persona) : null,
            // Todo lo que debe (sin tope), para cobrarlo desde la ficha.
            'pendientes' => $this->veDinero($actor) ? $this->pendientes($persona) : null,
        ]]);
    }

    private function veDinero(mixed $actor): bool
    {
        return ! $actor instanceof Usuario || $actor->puede('ordenes.ver');
    }

    /**
     * Derechos (entitlements) del alumno con su saldo/disponible derivado del ledger
     * y la vigencia. El producto y el estado provienen del acuerdo que los concedió.
     *
     * @return list<array<string, mixed>>
     */
    private function derechos(PersonaTenant $persona): array
    {
        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->with(['acuerdo.producto', 'acuerdo.pausaAbierta'])
            ->orderByDesc('id')
            ->get();

        return $derechos->map(function (DerechoTenant $derecho): array {
            $saldo = $derecho->ilimitado ? null : $this->libro->saldo($derecho);
            $disponible = $derecho->ilimitado ? null : $this->libro->disponible($derecho);

            return [
                'id' => $derecho->ulid,
                'producto' => $derecho->acuerdo?->producto?->nombre,
                'estado' => $derecho->acuerdo?->estado?->value,
                // Para pausar/reanudar desde la ficha.
                'acuerdo_id' => $derecho->acuerdo?->ulid,
                'pausa_hasta' => $derecho->acuerdo?->pausaAbierta?->hasta->toDateString(),
                'proxima_cobro_en' => $derecho->acuerdo?->proxima_cobro_en?->toDateString(),
                'ilimitado' => $derecho->ilimitado,
                'saldo_creditos' => $saldo !== null ? intdiv($saldo, 1000) : null,
                'saldo_unidades' => $saldo,
                'disponible_unidades' => $disponible,
                'valido_hasta' => $derecho->valido_hasta?->toDateString(),
            ];
        })->all();
    }

    /**
     * Historial reciente de reservas del alumno (con asistencia si la hubo).
     *
     * @return list<array<string, mixed>>
     */
    private function reservas(PersonaTenant $persona): array
    {
        $reservas = ReservaTenant::query()
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->where('reservas.persona_id', $persona->getKey())
            ->orderByDesc('sesiones.inicia_en')
            ->select('reservas.*')
            ->with(['sesion.oferta', 'sesion.instructor', 'asistencia'])
            ->limit(self::HISTORIAL)
            ->get();

        return $reservas->map(fn (ReservaTenant $reserva): array => [
            'id' => $reserva->ulid,
            'clase' => $reserva->sesion?->oferta?->nombre,
            // Clase o cita, y con quién: en citas, el servicio y el profesional habituales.
            'tipo' => $reserva->sesion?->tipo->value,
            'instructor' => $reserva->sesion?->instructor?->name,
            'inicia_en' => $reserva->sesion?->inicia_en?->toIso8601String(),
            'zona_horaria' => $reserva->sesion?->zona_horaria,
            'estado' => $reserva->estado->value,
            'asistencia' => $reserva->asistencia?->estado?->value,
            // Quién canceló (cliente, negocio o sistema), si se canceló.
            'cancelada_por' => $reserva->cancelada_por,
        ])->all();
    }

    /**
     * Historial reciente de compras (órdenes) del alumno. Dinero en minor + moneda.
     *
     * @return list<array<string, mixed>>
     */
    private function ordenes(PersonaTenant $persona): array
    {
        $ordenes = OrdenTenant::query()
            ->where('persona_id', $persona->getKey())
            ->with(['lineas.producto', 'sesion.oferta', 'sesion.instructor'])
            ->orderByDesc('id')
            ->limit(self::HISTORIAL)
            ->get();

        return $ordenes->map(fn (OrdenTenant $orden): array => $this->presentarOrden($orden))->all();
    }

    /**
     * Lo que debe, completo (de lo más antiguo a lo más reciente).
     *
     * @return list<array<string, mixed>>
     */
    private function pendientes(PersonaTenant $persona): array
    {
        return OrdenTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('estado', EstadoOrden::Pendiente->value)
            ->with(['lineas.producto', 'sesion.oferta', 'sesion.instructor'])
            ->orderBy('id')
            ->get()
            ->map(fn (OrdenTenant $orden): array => $this->presentarOrden($orden))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarOrden(OrdenTenant $orden): array
    {
        $sesion = $orden->sesion;
        $productos = $orden->lineas->map(static fn (LineaOrdenTenant $l): ?string => $l->producto?->nombre)->filter()->values();

        return [
            'id' => $orden->ulid,
            'fecha' => $orden->created_at?->toIso8601String(),
            'estado' => $orden->estado->value,
            'total_minor' => $orden->total_minor,
            'moneda' => $orden->moneda,
            'metodo_pago' => $orden->metodo_pago,
            'pagada_en' => $orden->pagada_en?->toIso8601String(),
            // Qué se pagó: sus productos o, si es una cita, el servicio, con quién y cuándo.
            'concepto' => $productos->isNotEmpty() ? $productos->implode(', ') : $sesion?->oferta?->nombre,
            'sesion' => $sesion instanceof SesionTenant ? [
                'profesional' => $sesion->instructor?->name,
                'inicia_en' => $sesion->inicia_en->toIso8601String(),
                'zona_horaria' => $sesion->zona_horaria,
            ] : null,
        ];
    }
}
