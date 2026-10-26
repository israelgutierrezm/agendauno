<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Http\JsonResponse;

/**
 * API de integración de terceros (R40), autenticada por llave de API y acotada por
 * scopes. Solo lectura de datos operativos. Opera sobre la BD del estudio resuelto.
 */
class IntegracionApiTenantController
{
    private const LIMITE = 200;

    /**
     * Miembros activos del estudio (scope `miembros.ver`).
     */
    public function miembros(): JsonResponse
    {
        $miembros = PersonaTenant::query()
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('archivado', false)
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $miembros->map(static fn (PersonaTenant $p): array => [
                'id' => $p->ulid,
                'nombre' => $p->nombreCompleto(),
                'email' => $p->email,
                'activo' => (bool) $p->activo,
            ])->all(),
        ]);
    }

    /**
     * Sesiones próximas programadas (scope `agenda.ver`).
     */
    public function sesiones(): JsonResponse
    {
        $sesiones = SesionTenant::query()
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>=', now())
            ->orderBy('inicia_en')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $sesiones->map(static fn (SesionTenant $s): array => [
                'id' => $s->ulid,
                'inicia_en' => $s->inicia_en->toIso8601String(),
                'termina_en' => $s->termina_en->toIso8601String(),
                'capacidad' => $s->capacidad,
                'estado' => $s->estado->value,
                'tipo' => $s->tipo->value,
            ])->all(),
        ]);
    }

    /**
     * Reservas de las sesiones por venir (scope `reservas.ver`). La persona va por su
     * ULID (sus datos, con `miembros.ver`).
     */
    public function reservas(): JsonResponse
    {
        $reservas = ReservaTenant::query()
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->where('sesiones.inicia_en', '>=', now())
            ->orderBy('sesiones.inicia_en')
            ->select('reservas.*')
            ->with(['sesion', 'persona'])
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $reservas->map(static fn (ReservaTenant $r): array => [
                'id' => $r->ulid,
                'sesion_id' => $r->sesion?->ulid,
                'persona_id' => $r->persona?->ulid,
                'estado' => $r->estado->value,
                'creada_en' => $r->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Planes vigentes con su saldo (scope `derechos.ver`).
     */
    public function derechos(LibroMayorTenant $libro): JsonResponse
    {
        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('estado', EstadoAcuerdo::Activo->value))
            ->where(fn ($q) => $q->whereNull('valido_hasta')->orWhereDate('valido_hasta', '>=', now()->toDateString()))
            ->with('acuerdo.persona', 'acuerdo.producto')
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $derechos->map(static fn (DerechoTenant $d): array => [
                'id' => $d->ulid,
                'persona_id' => $d->acuerdo?->persona?->ulid,
                'producto' => $d->acuerdo?->producto?->nombre,
                'ilimitado' => (bool) $d->ilimitado,
                // Unidades escaladas: 1000 = 1 crédito.
                'disponible' => $d->ilimitado ? null : $libro->disponible($d),
                'valido_hasta' => $d->valido_hasta?->toDateString(),
            ])->all(),
        ]);
    }

    /**
     * Órdenes recientes (scope `ordenes.ver`). Montos en centavos con su moneda.
     */
    public function ordenes(): JsonResponse
    {
        $ordenes = OrdenTenant::query()
            ->with('persona')
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $ordenes->map(static fn (OrdenTenant $o): array => [
                'id' => $o->ulid,
                'persona_id' => $o->persona?->ulid,
                'estado' => $o->estado->value,
                'total_minor' => (int) $o->total_minor,
                'moneda' => $o->moneda,
                'pagada_en' => $o->pagada_en?->toIso8601String(),
                'creada_en' => $o->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
