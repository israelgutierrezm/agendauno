<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Asistencia\EstadoAsistencia;
use App\Modules\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Radar de retención (Etapa 2): alumnos con membresía POR VENCER (próximos días) o
 * VENCIDA hace poco (recuperable), con señales de churn (última asistencia) para que
 * el estudio actúe antes de perderlos. El vencimiento es el MAX `valido_hasta` de sus
 * derechos bajo un acuerdo activo. Todo derivado de los datos del tenant; sin esquema
 * nuevo. `?formato=csv` exporta la lista para campañas de renovación.
 */
class RetencionTenantController
{
    // Ventana por defecto (días) hacia adelante para "por vencer".
    private const DIAS_POR_DEFECTO = 14;

    // Días hacia atrás para considerar una membresía "vencida recuperable".
    private const GRACIA_VENCIDAS = 14;

    public function __construct(private readonly ResolverAccesoTenant $acceso) {}

    public function porVencer(Request $request): Response
    {
        $hoy = CarbonImmutable::now()->startOfDay();
        $dias = min(max((int) $request->query('dias', (string) self::DIAS_POR_DEFECTO), 1), 90);

        // MAX vencimiento por persona entre sus acuerdos ACTIVOS (una sola consulta).
        $filas = DerechoTenant::query()
            ->join('acuerdos', 'acuerdos.id', '=', 'derechos.acuerdo_id')
            ->where('acuerdos.estado', EstadoAcuerdo::Activo->value)
            ->whereNotNull('derechos.valido_hasta')
            ->groupBy('acuerdos.persona_id')
            ->selectRaw('acuerdos.persona_id as pid, MAX(derechos.valido_hasta) as vence')
            ->get();

        // Clasifica en PHP (evita trampas de comparación de fechas en SQL).
        $ventana = [];
        foreach ($filas as $fila) {
            $vence = CarbonImmutable::parse((string) $fila->getAttribute('vence'))->startOfDay();
            $restantes = $hoy->diffInDays($vence, false);
            if ($restantes >= 0 && $restantes <= $dias) {
                $estado = 'por_vencer';
            } elseif ($restantes < 0 && $restantes >= -self::GRACIA_VENCIDAS) {
                $estado = 'vencida';
            } else {
                continue;
            }
            $ventana[(int) $fila->getAttribute('pid')] = ['vence' => $vence, 'dias' => (int) $restantes, 'estado' => $estado];
        }

        if ($ventana === []) {
            return $this->responder([], $dias, $request);
        }

        // Solo alumnos vigentes en el padrón (no archivados). Alcance por sucursal (R19):
        // el staff acotado solo ve el radar de SUS sedes.
        $actor = $request->attributes->get('usuario_tenant');
        $permitidas = $actor instanceof Usuario ? $this->acceso->sucursalesPermitidas($actor) : null;

        $personas = PersonaTenant::query()
            ->whereIn('id', array_keys($ventana))
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('archivado', false)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('sucursal_id', $permitidas))
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
                'id' => $persona->ulid,
                'nombre_completo' => $persona->nombreCompleto(),
                'email' => $persona->email,
                'estado' => $info['estado'],
                'vence' => $info['vence']->toDateString(),
                'dias_restantes' => $info['dias'],
                'ultima_asistencia' => $ultimaFecha?->toDateString(),
                'dias_sin_asistir' => $ultimaFecha !== null ? (int) $ultimaFecha->startOfDay()->diffInDays($hoy) : null,
            ];
        }

        // Más urgente primero: las vencidas (días negativos) arriba, luego lo más próximo.
        usort($miembros, static fn (array $a, array $b): int => $a['dias_restantes'] <=> $b['dias_restantes']);

        return $this->responder($miembros, $dias, $request);
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

    /**
     * @param  list<array<string, mixed>>  $miembros
     */
    private function responder(array $miembros, int $dias, Request $request): Response
    {
        if ((string) $request->query('formato') === 'csv') {
            return $this->exportarCsv($miembros);
        }

        $porVencer = count(array_filter($miembros, static fn (array $m): bool => $m['estado'] === 'por_vencer'));
        $vencidas = count($miembros) - $porVencer;

        return response()->json(['data' => [
            'resumen' => ['por_vencer' => $porVencer, 'vencidas' => $vencidas, 'dias' => $dias],
            'miembros' => $miembros,
        ]]);
    }

    /**
     * @param  list<array<string, mixed>>  $miembros
     */
    private function exportarCsv(array $miembros): Response
    {
        $lineas = ['Alumno,Correo,Estado,Vence,Dias restantes,Ultima asistencia'];
        foreach ($miembros as $m) {
            $lineas[] = implode(',', array_map($this->escaparCsv(...), [
                (string) $m['nombre_completo'],
                (string) ($m['email'] ?? ''),
                $m['estado'] === 'por_vencer' ? 'Por vencer' : 'Vencida',
                (string) $m['vence'],
                (string) $m['dias_restantes'],
                (string) ($m['ultima_asistencia'] ?? ''),
            ]));
        }

        return response(implode("\n", $lineas)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="retencion-por-vencer.csv"',
        ]);
    }

    private function escaparCsv(string $valor): string
    {
        return str_contains($valor, ',') || str_contains($valor, '"') || str_contains($valor, "\n")
            ? '"'.str_replace('"', '""', $valor).'"'
            : $valor;
    }
}
