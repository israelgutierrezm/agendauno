<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Models\AsignacionSesionTenant;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Nomina\TipoPago;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Illuminate\Support\Collection;

/**
 * Lo que dejó cada clase o cita (ADR 0081): quién la impartió, cuántos asistieron o
 * faltaron, el valor de lo atendido y lo que se paga a cada quien que la trabajó.
 * Lo comparten la rentabilidad por clase (R30) y la agenda del equipo.
 *
 * - Valor de lo atendido: asistentes × precio de la clase o servicio si lo tiene; si
 *   no, los créditos consumidos × precio por crédito del paquete. Quien asistió sin
 *   valor por crédito (membresía ilimitada, cortesía) cuenta en `sin_costo`.
 * - Pago: el esquema de cada participante ({@see PersonalDeSesionTenant}) por clase,
 *   por asistente o por hora.
 *
 * Montos en minor.
 */
class ValorDeSesionesTenant
{
    /**
     * @param  Collection<int, SesionTenant>  $sesiones  no canceladas, con su oferta
     * @return array<int, array{imparte: int|null, minutos: int, presentes: int, ausentes: int, canceladas: int, ingreso: int, sin_costo: int, pagos: array<int, int>}> por sesión
     */
    public function calcular(Collection $sesiones): array
    {
        $sesionIds = $sesiones->pluck('id')->all();
        if ($sesionIds === []) {
            return [];
        }

        $esquemas = EsquemaPagoTenant::query()->where('activo', true)->get()->keyBy('usuario_id');
        $asignaciones = AsignacionSesionTenant::query()->whereIn('sesion_id', $sesionIds)->get()->groupBy('sesion_id');
        $reservas = ReservaTenant::query()
            ->whereIn('sesion_id', $sesionIds)
            ->with(['asistencia', 'derecho.acuerdo.producto'])
            ->get()
            ->groupBy('sesion_id');

        $valores = [];
        foreach ($sesiones as $sesion) {
            /** @var Collection<int, AsignacionSesionTenant> $suyas */
            $suyas = $asignaciones->get($sesion->id) ?? collect();
            /** @var Collection<int, ReservaTenant> $deLaSesion */
            $deLaSesion = $reservas->get($sesion->id) ?? collect();
            $presentes = $deLaSesion->filter(fn (ReservaTenant $r): bool => $r->asistencia?->estado === EstadoAsistencia::Presente);
            $ausentes = $deLaSesion->filter(fn (ReservaTenant $r): bool => $r->asistencia?->estado === EstadoAsistencia::Ausente)->count();

            [$ingreso, $sinCosto] = $this->ingresoDe($sesion->oferta->precio_clase_minor, $presentes);
            $minutos = (int) $sesion->inicia_en->diffInMinutes($sesion->termina_en);

            $pagos = [];
            foreach (array_keys(PersonalDeSesionTenant::participantes($sesion, $suyas)) as $usuarioId) {
                $esquema = $esquemas->get($usuarioId);
                if ($esquema instanceof EsquemaPagoTenant) {
                    $pagos[$usuarioId] = $this->pagoDe($esquema, $presentes->count(), $minutos);
                }
            }

            $valores[(int) $sesion->id] = [
                'imparte' => PersonalDeSesionTenant::imparte($sesion, $suyas),
                'minutos' => $minutos,
                'presentes' => $presentes->count(),
                'ausentes' => $ausentes,
                'canceladas' => $deLaSesion->where('estado', EstadoReserva::Cancelada)->count(),
                'ingreso' => $ingreso,
                'sin_costo' => $sinCosto,
                'pagos' => $pagos,
            ];
        }

        return $valores;
    }

    /**
     * @param  Collection<int, ReservaTenant>  $presentes
     * @return array{0: int, 1: int} [ingreso_minor, sin_costo_unitario]
     */
    private function ingresoDe(?int $precioClase, Collection $presentes): array
    {
        if ($precioClase !== null && $precioClase > 0) {
            return [$presentes->count() * $precioClase, 0];
        }

        $ingreso = 0;
        $sinCosto = 0;
        foreach ($presentes as $reserva) {
            $producto = $reserva->derecho?->acuerdo?->producto;
            $precioMinor = (int) ($producto->precio_minor ?? 0);
            $creditos = (int) ($producto->creditos_incluidos ?? 0);
            if ($precioMinor > 0 && $creditos > 0) {
                $ingreso += intdiv($precioMinor * (int) $reserva->costo_unidades, $creditos);
            } else {
                $sinCosto++;
            }
        }

        return [$ingreso, $sinCosto];
    }

    private function pagoDe(EsquemaPagoTenant $esquema, int $presentes, int $minutos): int
    {
        $monto = (int) $esquema->monto_minor;

        return match ($esquema->tipo) {
            TipoPago::PorClase => $monto,
            TipoPago::PorAsistente => $monto * $presentes,
            TipoPago::PorHora => intdiv($monto * $minutos, 60),
        };
    }
}
