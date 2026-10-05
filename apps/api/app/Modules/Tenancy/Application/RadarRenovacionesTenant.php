<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Radar de renovaciones: alumnos con membresía POR VENCER (próximos días) o VENCIDA
 * hace poco (recuperable), con su última asistencia como señal de abandono. El
 * vencimiento es el MAX `valido_hasta` de sus derechos bajo un acuerdo activo.
 *
 * Lo usan la pantalla de Renovaciones (la lista, o el CSV) y el Inicio del negocio
 * (cuántos hay que contactar): una sola regla para las dos.
 */
class RadarRenovacionesTenant
{
    /** Las ventanas por defecto las fija el negocio (ADR 0047). */
    public function __construct(private readonly ParametrosTenant $parametros) {}

    /** Días hacia adelante que cuentan como "por vencer", si no se pide otro. */
    public function diasPorDefecto(): int
    {
        return $this->parametros->entero('membresias.dias_por_vencer');
    }

    /**
     * La lista, lo más urgente primero (las vencidas arriba, luego lo más próximo).
     * `$sucursales` acota a esas sedes (staff con alcance por sucursal); null = todas.
     *
     * @param  list<int>|null  $sucursales
     * @return list<array{id: string, nombre_completo: string, email: string|null, estado: string, vence: string, dias_restantes: int, ultima_asistencia: string|null, dias_sin_asistir: int|null}>
     */
    public function miembros(int $dias, ?array $sucursales): array
    {
        $hoy = app(FechasNegocioTenant::class)->dia();

        // MAX vencimiento por persona entre sus acuerdos ACTIVOS (una sola consulta).
        $filas = DerechoTenant::query()
            ->join('acuerdos', 'acuerdos.id', '=', 'derechos.acuerdo_id')
            ->where('acuerdos.estado', EstadoAcuerdo::Activo->value)
            ->whereNotNull('derechos.valido_hasta')
            ->groupBy('acuerdos.persona_id')
            ->selectRaw('acuerdos.persona_id as pid, MAX(derechos.valido_hasta) as vence')
            ->get();

        // Clasifica en PHP (evita trampas de comparación de fechas en SQL).
        $vencidaDias = $this->parametros->entero('membresias.dias_vencida_recuperable');
        $ventana = [];
        foreach ($filas as $fila) {
            $vence = CarbonImmutable::parse((string) $fila->getAttribute('vence'))->startOfDay();
            $restantes = (int) $hoy->diffInDays($vence, false);
            if ($restantes >= 0 && $restantes <= $dias) {
                $estado = 'por_vencer';
            } elseif ($restantes < 0 && $restantes >= -$vencidaDias) {
                $estado = 'vencida';
            } else {
                continue;
            }
            $ventana[(int) $fila->getAttribute('pid')] = ['vence' => $vence, 'dias' => $restantes, 'estado' => $estado];
        }

        if ($ventana === []) {
            return [];
        }

        // Solo alumnos vigentes en el padrón (no archivados), de las sedes permitidas.
        $personas = PersonaTenant::query()
            ->whereIn('id', array_keys($ventana))
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('archivado', false)
            ->when($sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursales))
            ->get()
            ->keyBy('id');

        $ultimaAsistencia = $this->ultimaAsistencia(array_keys($ventana));

        $miembros = [];
        foreach ($ventana as $pid => $info) {
            $persona = $personas->get($pid);
            if (! $persona instanceof PersonaTenant) {
                continue;
            }
            $ultima = $ultimaAsistencia->get($pid);
            $ultimaFecha = $ultima !== null ? CarbonImmutable::parse((string) $ultima) : null;

            $miembros[] = [
                'id' => (string) $persona->ulid,
                'nombre_completo' => $persona->nombreCompleto(),
                'email' => $persona->email,
                'estado' => $info['estado'],
                'vence' => $info['vence']->toDateString(),
                'dias_restantes' => $info['dias'],
                'ultima_asistencia' => $ultimaFecha?->toDateString(),
                'dias_sin_asistir' => $ultimaFecha !== null ? (int) $ultimaFecha->startOfDay()->diffInDays($hoy) : null,
            ];
        }

        usort($miembros, static fn (array $a, array $b): int => $a['dias_restantes'] <=> $b['dias_restantes']);

        return $miembros;
    }

    /**
     * Última asistencia (presente) por persona, en una sola consulta.
     *
     * @param  list<int>  $personaIds
     * @return Collection<int, string>
     */
    private function ultimaAsistencia(array $personaIds): Collection
    {
        /** @var Collection<int, string> $mapa */
        $mapa = ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->whereIn('reservas.persona_id', $personaIds)
            ->groupBy('reservas.persona_id')
            ->selectRaw('reservas.persona_id as pid, MAX(asistencias.registrada_en) as ultima')
            ->pluck('ultima', 'pid');

        return $mapa;
    }
}
