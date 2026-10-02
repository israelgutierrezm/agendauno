<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pagos\EstadoPago;
use Carbon\CarbonInterface;

/**
 * Historial de una cita para recepción: cuándo se agendó, los recordatorios, los
 * cambios de horario, el cobro (y su corrección, anulación o reembolso), la
 * asistencia (y sus correcciones) y la cancelación, con quién lo hizo cuando se sabe.
 *
 * No guarda nada propio: se arma con lo que ya queda registrado (la reserva, sus
 * pagos, la bitácora y los eventos de dominio). Las marcas de asistencia salen de los
 * eventos `asistencia.marcada`; si no hay, de la asistencia vigente.
 */
final class HistorialCitaTenant
{
    /** Lo que la bitácora dice de una cita y de sus cobros. */
    private const ACCIONES = ['reserva.reprogramada', 'pago.registrado', 'pago.metodo_corregido', 'pago.anulado'];

    /**
     * @return list<array{tipo: string, fecha: string, actor: string|null, detalle: array<string, mixed>}>
     */
    public function de(ReservaTenant $reserva): array
    {
        $reserva->loadMissing(['orden.pagos.registradoPor', 'asistencia']);
        $hechos = [];
        $agregar = function (string $tipo, ?CarbonInterface $fecha, ?string $actor = null, array $detalle = []) use (&$hechos): void {
            if ($fecha !== null) {
                $hechos[] = ['tipo' => $tipo, 'fecha' => $fecha, 'actor' => $actor, 'detalle' => $detalle];
            }
        };

        $agregar('agendada', $reserva->created_at);
        $agregar('recordatorio', $reserva->recordatorio_24h_en, null, ['horas' => 24]);
        $agregar('recordatorio', $reserva->recordatorio_2h_en, null, ['horas' => 2]);

        $pagos = ($reserva->orden->pagos ?? collect())
            ->reject(fn (PagoTenant $p): bool => $p->estado === EstadoPago::Pendiente || $p->estado === EstadoPago::Rechazado);
        $ulidsPago = $pagos->map(fn (PagoTenant $p): string => (string) $p->ulid)->all();

        $bitacora = AuditoriaTenant::query()
            ->whereIn('accion', self::ACCIONES)
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('entidad_tipo', 'reserva')->where('entidad_id', (string) $reserva->ulid))
                ->orWhere(fn ($r) => $r->where('entidad_tipo', 'pago')->whereIn('entidad_id', $ulidsPago)))
            ->orderBy('id')
            ->get();
        // La forma con que se registró cada cobro (la orden guarda la vigente).
        $registrados = $bitacora->where('accion', 'pago.registrado')->keyBy('entidad_id');

        foreach ($pagos as $pago) {
            $enCaja = $pago->proveedor === 'manual';
            $metodo = $registrados->get((string) $pago->ulid)?->despues['metodo'] ?? ($enCaja ? $reserva->orden?->metodo_pago : null);
            $agregar('cobrada', $pago->aprobado_en ?? $pago->created_at, $pago->registradoPor?->name, [
                'monto_minor' => $pago->monto_minor,
                'moneda' => $pago->moneda,
                'metodo' => $metodo,
                'en_caja' => $enCaja,
            ]);
        }

        foreach ($bitacora as $registro) {
            match ($registro->accion) {
                'reserva.reprogramada' => $agregar('reprogramada', $registro->created_at, $registro->actor_nombre, [
                    'de' => self::horario($registro->antes),
                    'a' => self::horario($registro->despues),
                ]),
                'pago.metodo_corregido' => $agregar('metodo_corregido', $registro->created_at, $registro->actor_nombre, [
                    'de' => $registro->antes['metodo'] ?? null,
                    'a' => $registro->despues['metodo'] ?? null,
                    'motivo' => $registro->motivo,
                ]),
                'pago.anulado' => $agregar('cobro_anulado', $registro->created_at, $registro->actor_nombre, [
                    'motivo' => $registro->motivo,
                ]),
                default => null,
            };
        }

        $eventos = EventoOutboxTenant::query()
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('tipo', 'asistencia.marcada')->where('agregado_id', (string) $reserva->asistencia?->ulid))
                ->orWhere(fn ($r) => $r->where('tipo', 'pago.reembolsado')->whereIn('agregado_id', $ulidsPago)))
            ->orderBy('id')
            ->get();
        $marcas = $eventos->where('tipo', 'asistencia.marcada')->values();
        foreach ($marcas as $i => $evento) {
            $agregar($i === 0 ? 'asistencia' : 'asistencia_corregida', $evento->ocurrido_en, null, [
                'estado' => $evento->payload['estado'] ?? null,
            ]);
        }
        if ($marcas->isEmpty() && $reserva->asistencia !== null) {
            $agregar('asistencia', $reserva->asistencia->registrada_en, null, ['estado' => $reserva->asistencia->estado->value]);
        }
        foreach ($eventos->where('tipo', 'pago.reembolsado') as $evento) {
            $agregar('reembolsada', $evento->ocurrido_en, null, [
                'monto_minor' => $evento->payload['monto_minor'] ?? null,
            ]);
        }

        if ($reserva->cancelada_en !== null) {
            $quien = $reserva->cancelada_por_usuario_id !== null
                ? Usuario::query()->withTrashed()->find($reserva->cancelada_por_usuario_id)?->name
                : null;
            $agregar('cancelada', $reserva->cancelada_en, $quien, ['por' => $reserva->cancelada_por]);
        }

        usort($hechos, fn (array $a, array $b): int => $a['fecha'] <=> $b['fecha']);

        return array_map(fn (array $h): array => [...$h, 'fecha' => $h['fecha']->toIso8601String()], $hechos);
    }

    /**
     * «viernes 2 de octubre · 11:00», de los datos de sesión que guarda la bitácora.
     *
     * @param  array<string, mixed>|null  $datos
     */
    private static function horario(?array $datos): ?string
    {
        if ($datos === null || ! isset($datos['fecha'], $datos['hora'])) {
            return null;
        }

        return "{$datos['fecha']} · {$datos['hora']}";
    }
}
