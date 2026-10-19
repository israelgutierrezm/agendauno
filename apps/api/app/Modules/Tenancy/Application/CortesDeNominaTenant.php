<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AjusteNominaTenant;
use App\Modules\Tenancy\Models\CorteNominaTenant;
use App\Modules\Tenancy\Models\LineaCorteNominaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Nomina\EstadoCorteNomina;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cortes de nómina: el periodo abierto se calcula al vuelo ({@see CalcularNominaTenant});
 * al cerrarlo queda la foto de lo devengado por persona y quién lo cerró; después se
 * registra el pago con su referencia. Lo que cambie después de pagar (una asistencia
 * corregida) no toca el corte: va en ajustes, con su motivo.
 *
 * - Solo se cierra un periodo que ya terminó (días del negocio) y que no se encime con
 *   otro corte. Dos cierres a la vez se forman bajo un candado del negocio.
 * - Un corte cerrado se puede reabrir mientras no esté pagado; uno pagado, no.
 */
class CortesDeNominaTenant
{
    public function __construct(
        private readonly CalcularNominaTenant $nomina,
        private readonly FechasNegocioTenant $fechas,
        private readonly RegionNegocioTenant $region,
        private readonly RegistrarAuditoria $auditoria,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function cerrar(string $desde, string $hasta, ?Usuario $actor): CorteNominaTenant
    {
        if ($hasta >= $this->fechas->hoy()) {
            throw ValidationException::withMessages(['hasta' => ['Solo se cierra un periodo que ya terminó: elige hasta ayer o antes.']]);
        }

        $llave = 'nomina:cortes:'.($this->gestor->actual()->ulid ?? 'local');

        return Cache::lock($llave, 30)->block(10, function () use ($desde, $hasta, $actor): CorteNominaTenant {
            $encimado = CorteNominaTenant::query()
                ->whereDate('desde', '<=', $hasta)
                ->whereDate('hasta', '>=', $desde)
                ->orderBy('desde')
                ->first();
            if ($encimado !== null) {
                throw ValidationException::withMessages(['desde' => ['Se encima con el periodo ya cerrado del '.$encimado->desde->toDateString().' al '.$encimado->hasta->toDateString().'.']]);
            }

            $filas = $this->nomina->calcular($desde, $hasta);
            $ids = Usuario::query()->whereIn('ulid', array_filter(array_column($filas, 'usuario_id')))->pluck('id', 'ulid');
            $moneda = $this->region->moneda();

            $corte = DB::connection('tenant')->transaction(function () use ($desde, $hasta, $actor, $filas, $ids, $moneda): CorteNominaTenant {
                $corte = CorteNominaTenant::query()->create([
                    'desde' => $desde,
                    'hasta' => $hasta,
                    'estado' => EstadoCorteNomina::Cerrado->value,
                    'moneda' => $moneda,
                    'total_minor' => array_sum(array_column($filas, 'monto_total_minor')),
                    'cerrado_por_id' => $actor?->getKey(),
                    'cerrado_por_nombre' => $actor?->name,
                    'cerrado_en' => now(),
                ]);
                foreach ($filas as $fila) {
                    $corte->lineas()->create([
                        'usuario_id' => $fila['usuario_id'] !== null ? $ids->get($fila['usuario_id']) : null,
                        'usuario_nombre' => $fila['usuario'],
                        'tipo' => $fila['tipo'],
                        'monto_minor' => $fila['monto_minor'],
                        'moneda' => $fila['moneda'],
                        'vigente_desde' => $fila['vigente_desde'],
                        'unidades' => round($fila['unidades'], 3),
                        'total_minor' => $fila['monto_total_minor'],
                    ]);
                }

                return $corte;
            });

            $this->auditoria->registrar($actor, 'nomina.corte_cerrado', 'corte_nomina', (string) $corte->ulid, null, $this->resumen($corte));

            return $corte;
        });
    }

    public function pagar(CorteNominaTenant $corte, string $referencia, ?Usuario $actor): CorteNominaTenant
    {
        $pagado = DB::connection('tenant')->transaction(function () use ($corte, $referencia, $actor): CorteNominaTenant {
            $bloqueado = CorteNominaTenant::query()->whereKey($corte->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado === EstadoCorteNomina::Pagado) {
                throw ValidationException::withMessages(['corte' => ['Este periodo ya se registró como pagado.']]);
            }
            $bloqueado->update([
                'estado' => EstadoCorteNomina::Pagado->value,
                'pagado_por_id' => $actor?->getKey(),
                'pagado_por_nombre' => $actor?->name,
                'pagado_en' => now(),
                'referencia_pago' => $referencia,
            ]);

            return $bloqueado;
        });
        $this->auditoria->registrar($actor, 'nomina.corte_pagado', 'corte_nomina', (string) $pagado->ulid, null, [...$this->resumen($pagado), 'referencia' => $referencia]);

        return $pagado;
    }

    /** Vuelve a abrir el periodo (se calcula al vuelo otra vez). Solo si no está pagado. */
    public function reabrir(CorteNominaTenant $corte, ?Usuario $actor): void
    {
        $resumen = DB::connection('tenant')->transaction(function () use ($corte): array {
            $bloqueado = CorteNominaTenant::query()->whereKey($corte->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado === EstadoCorteNomina::Pagado) {
                throw ValidationException::withMessages(['corte' => ['Un periodo pagado no se reabre: registra un ajuste.']]);
            }
            $resumen = $this->resumen($bloqueado);
            $bloqueado->delete();

            return $resumen;
        });
        $this->auditoria->registrar($actor, 'nomina.corte_reabierto', 'corte_nomina', (string) $corte->ulid, $resumen, null);
    }

    /**
     * Una corrección después de pagar, a favor (+) o en contra (−) de alguien. Con
     * `$compensa` corrige una diferencia del cálculo y se resta de lo que falta (R09);
     * sin él (un bono, otra corrección) se muestra aparte.
     */
    public function ajustar(CorteNominaTenant $corte, Usuario $persona, int $montoMinor, string $motivo, ?Usuario $actor, bool $compensa = false): AjusteNominaTenant
    {
        if ($montoMinor === 0) {
            throw ValidationException::withMessages(['monto_minor' => ['El ajuste no puede ser de cero.']]);
        }
        $ajuste = DB::connection('tenant')->transaction(function () use ($corte, $persona, $montoMinor, $motivo, $actor, $compensa): AjusteNominaTenant {
            $bloqueado = CorteNominaTenant::query()->whereKey($corte->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado !== EstadoCorteNomina::Pagado) {
                throw ValidationException::withMessages(['corte' => ['Antes de pagar no hace falta un ajuste: reabre el periodo y vuelve a cerrarlo.']]);
            }

            return $bloqueado->ajustes()->create([
                'usuario_id' => $persona->getKey(),
                'usuario_nombre' => $persona->name,
                'monto_minor' => $montoMinor,
                'moneda' => $bloqueado->moneda,
                'motivo' => $motivo,
                'registrado_por_id' => $actor?->getKey(),
                'registrado_por_nombre' => $actor?->name,
                'compensa_diferencia' => $compensa,
            ]);
        });
        $this->auditoria->registrar($actor, 'nomina.ajuste', 'corte_nomina', (string) $corte->ulid, null, [
            ...$this->resumen($corte),
            'persona' => $persona->name,
            'monto_minor' => $montoMinor,
            'motivo' => $motivo,
        ]);

        return $ajuste;
    }

    /**
     * Lo que da hoy el cálculo del mismo periodo contra lo que quedó en el corte, por
     * persona (solo donde cambió). Sirve para decidir un ajuste; no cambia nada.
     *
     * @return list<array{usuario_id: string|null, usuario: string|null, en_corte_minor: int, ajustes_minor: int, otros_ajustes_minor: int, reconocido_minor: int, hoy_minor: int, diferencia_minor: int, diferencia_original_minor: int}>
     */
    public function diferencias(CorteNominaTenant $corte): array
    {
        $enCorte = [];
        $usuarios = Usuario::query()->whereIn('id', $corte->lineas()->whereNotNull('usuario_id')->select('usuario_id'))->pluck('ulid', 'id');
        foreach ($corte->lineas as $linea) {
            /** @var LineaCorteNominaTenant $linea */
            $clave = $linea->usuario_id !== null ? (string) $usuarios->get($linea->usuario_id) : '';
            $enCorte[$clave] ??= ['usuario' => $linea->usuario_nombre, 'minor' => 0];
            $enCorte[$clave]['minor'] += $linea->total_minor;
        }
        // Lo que ya se ajustó después de pagar por una diferencia (R09): cuenta como
        // reconocido. Los demás ajustes (un bono…) van aparte y no cambian lo que falta.
        $ajustes = [];
        $otros = [];
        $deAjustes = Usuario::query()->whereIn('id', $corte->ajustes()->whereNotNull('usuario_id')->select('usuario_id'))->pluck('ulid', 'id');
        foreach ($corte->ajustes()->get() as $ajuste) {
            $clave = $ajuste->usuario_id !== null ? (string) $deAjustes->get($ajuste->usuario_id) : '';
            if ($ajuste->compensa()) {
                $ajustes[$clave] ??= ['usuario' => $ajuste->usuario_nombre, 'minor' => 0];
                $ajustes[$clave]['minor'] += (int) $ajuste->monto_minor;
            } else {
                $otros[$clave] = ($otros[$clave] ?? 0) + (int) $ajuste->monto_minor;
            }
        }
        $hoy = [];
        foreach ($this->nomina->calcular($corte->desde->toDateString(), $corte->hasta->toDateString()) as $fila) {
            $clave = (string) ($fila['usuario_id'] ?? '');
            $hoy[$clave] ??= ['usuario' => $fila['usuario'], 'minor' => 0];
            $hoy[$clave]['minor'] += $fila['monto_total_minor'];
        }

        $filas = [];
        foreach (array_unique([...array_keys($enCorte), ...array_keys($hoy), ...array_keys($ajustes)]) as $clave) {
            $antes = $enCorte[$clave]['minor'] ?? 0;
            $ajustado = $ajustes[$clave]['minor'] ?? 0;
            $ahora = $hoy[$clave]['minor'] ?? 0;
            if ($antes === $ahora && $ajustado === 0) {
                continue;
            }
            $filas[] = [
                'usuario_id' => $clave !== '' ? (string) $clave : null,
                'usuario' => $enCorte[$clave]['usuario'] ?? $hoy[$clave]['usuario'] ?? $ajustes[$clave]['usuario'] ?? null,
                'en_corte_minor' => $antes,
                // Lo ya ajustado por la diferencia y lo reconocido (corte + esos ajustes).
                'ajustes_minor' => $ajustado,
                // Otros ajustes de la persona (no corrigen la diferencia).
                'otros_ajustes_minor' => $otros[$clave] ?? 0,
                'reconocido_minor' => $antes + $ajustado,
                'hoy_minor' => $ahora,
                // Lo que falta (0 = ya se atendió) y, aparte, la de siempre: contra el corte.
                'diferencia_minor' => $ahora - $antes - $ajustado,
                'diferencia_original_minor' => $ahora - $antes,
            ];
        }

        return $filas;
    }

    /**
     * @return array{desde: string, hasta: string, total_minor: int, moneda: string}
     */
    private function resumen(CorteNominaTenant $corte): array
    {
        return [
            'desde' => $corte->desde->toDateString(),
            'hasta' => $corte->hasta->toDateString(),
            'total_minor' => $corte->total_minor,
            'moneda' => $corte->moneda,
        ];
    }
}
