<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcanceClientesTenant;
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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        private readonly AlcanceClientesTenant $alcance,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $persona = $this->personaVisible($request);
        $actor = $request->attributes->get('usuario_tenant');

        return response()->json(['data' => [
            'persona' => [
                'id' => $persona->ulid,
                'nombre_completo' => $persona->nombreCompleto(),
                // Las partes del nombre, para editarlo sin perder los apellidos.
                'nombre' => $persona->nombre,
                'segundo_nombre' => $persona->segundo_nombre,
                'primer_apellido' => $persona->primer_apellido,
                'segundo_apellido' => $persona->segundo_apellido,
                'email' => $persona->email,
                'celular' => $persona->celular,
                'fecha_nacimiento' => $persona->fecha_nacimiento?->toDateString(),
                'genero' => $persona->genero?->value,
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

    /**
     * Todo su historial de reservas por páginas (la ficha trae las últimas 20), de la
     * más reciente; se filtra por estado.
     */
    public function historialReservas(Request $request): JsonResponse
    {
        $persona = $this->personaVisible($request);
        $filtros = $request->validate([
            'estado' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $pagina = ReservaTenant::query()
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->where('reservas.persona_id', $persona->getKey())
            ->when(($filtros['estado'] ?? '') !== '', fn ($q) => $q->where('reservas.estado', $filtros['estado']))
            ->orderByDesc('sesiones.inicia_en')
            ->orderByDesc('reservas.id')
            ->select('reservas.*')
            ->with(['sesion.oferta', 'sesion.instructor', 'asistencia'])
            ->paginate((int) ($filtros['per_page'] ?? 25), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (ReservaTenant $r): array => $this->presentarReserva($r))->all(),
            'meta' => self::metaDe($pagina),
        ]);
    }

    /**
     * Todo su historial de compras por páginas (la ficha trae las últimas 20). Solo
     * para quien puede ver órdenes.
     */
    public function historialOrdenes(Request $request): JsonResponse
    {
        $persona = $this->personaVisible($request);
        abort_unless($this->veDinero($request->attributes->get('usuario_tenant')), 403);
        $filtros = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $pagina = OrdenTenant::query()
            ->where('persona_id', $persona->getKey())
            ->with(['lineas.producto', 'sesion.oferta', 'sesion.instructor'])
            ->orderByDesc('id')
            ->paginate((int) ($filtros['per_page'] ?? 25), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (OrdenTenant $o): array => $this->presentarOrden($o))->all(),
            'meta' => self::metaDe($pagina),
        ]);
    }

    /**
     * La persona de la ruta, si quien consulta puede verla: un acotado solo a las de
     * sus sedes (R19) y quien imparte solo a sus clientes. Aunque esté dada de baja.
     */
    private function personaVisible(Request $request): PersonaTenant
    {
        $persona = PersonaTenant::withTrashed()
            ->with('sucursal')
            ->where('ulid', (string) $request->route('persona'))
            ->firstOrFail();
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless(
            ! $actor instanceof Usuario || $this->acceso->permiteSucursal($actor, $persona->sucursal_id !== null ? (int) $persona->sucursal_id : null),
            403,
        );
        abort_unless($this->alcance->puedeVer($persona, $actor instanceof Usuario ? $actor : null), 403);

        return $persona;
    }

    /**
     * @param  LengthAwarePaginator<int, *>  $pagina
     * @return array{total: int, page: int, per_page: int, ultima_pagina: int}
     */
    private static function metaDe(LengthAwarePaginator $pagina): array
    {
        return [
            'total' => $pagina->total(),
            'page' => $pagina->currentPage(),
            'per_page' => $pagina->perPage(),
            'ultima_pagina' => $pagina->lastPage(),
        ];
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
                // Membresía que se renueva: se puede dar de baja (B04); su baja programada.
                'recurrente' => $derecho->acuerdo?->dia_ancla !== null,
                'permanencia_hasta' => $derecho->acuerdo?->permanencia_hasta?->toDateString(),
                'baja_efectiva_el' => $derecho->acuerdo?->baja_efectiva_el?->toDateString(),
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

        return $reservas->map(fn (ReservaTenant $reserva): array => $this->presentarReserva($reserva))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarReserva(ReservaTenant $reserva): array
    {
        return [
            'id' => $reserva->ulid,
            'clase' => $reserva->sesion?->oferta?->nombre,
            // Clase o cita, y con quién: en citas, el servicio y el profesional habituales.
            'tipo' => $reserva->sesion?->tipo->value,
            'instructor' => $reserva->sesion?->instructor?->name,
            'inicia_en' => $reserva->sesion?->inicia_en?->toIso8601String(),
            'zona_horaria' => $reserva->sesion?->zona_horaria,
            'estado' => $reserva->estado->value,
            'asistencia' => $reserva->asistencia?->estado?->value,
            'retardo' => (bool) $reserva->asistencia?->retardo,
            // Quién canceló (cliente, negocio o sistema), si se canceló.
            'cancelada_por' => $reserva->cancelada_por,
        ];
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
        $productos = $orden->lineas->map(static fn (LineaOrdenTenant $l): string => $l->nombre())->filter()->values();

        return [
            'id' => $orden->ulid,
            'fecha' => $orden->created_at?->toIso8601String(),
            'estado' => $orden->estado->value,
            'total_minor' => $orden->total_minor,
            'moneda' => $orden->moneda,
            // Las cuotas que lleva (inscripción, anualidad, penalización; B04), para
            // exentarlas por separado.
            'cuotas' => $orden->lineas->map(static fn (LineaOrdenTenant $l): ?array => $l->comoCuota())->filter()->values()->all(),
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
