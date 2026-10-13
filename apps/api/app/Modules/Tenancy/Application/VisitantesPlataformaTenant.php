<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\VisitantePlataformaTenant;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Quienes llegan por Wellhub o TotalPass (ADR 0148): se registran como visitantes de la
 * plataforma, nunca como personas del negocio. Se reconocen por el id que da la
 * plataforma; cada visita actualiza lo que ella mande (nombre, correo, teléfono,
 * producto) y cuándo vino. Quien solo reservó desde la app (ADR 0149) es visitante sin
 * visitas todavía.
 */
class VisitantesPlataformaTenant
{
    /** Desde cuántos minutos antes de empezar se puede hacer check-in a una clase. */
    private const MINUTOS_ANTES = 30;

    /**
     * El visitante con ese id en la plataforma, nuevo o el de siempre, con lo que ella
     * mandó ahora (lo que no manda se conserva) y, si vino, cuándo (`$visita`; null si
     * solo reservó).
     *
     * @param  array{nombre?: string|null, email?: string|null, telefono?: string|null, producto?: string|null}  $datos
     */
    public function registrar(string $proveedor, string $idExterno, array $datos, ?CarbonInterface $visita): VisitantePlataformaTenant
    {
        $limpios = array_filter([
            'nombre' => self::texto($datos['nombre'] ?? null, 160),
            'email' => self::correo($datos['email'] ?? null),
            'telefono' => self::texto($datos['telefono'] ?? null, 40),
            'producto' => self::texto($datos['producto'] ?? null, 160),
        ], static fn (?string $v): bool => $v !== null);

        $visitante = VisitantePlataformaTenant::query()
            ->where('proveedor', $proveedor)
            ->where('id_externo', $idExterno)
            ->first();
        if (! $visitante instanceof VisitantePlataformaTenant) {
            try {
                $visitante = VisitantePlataformaTenant::query()->create([
                    'proveedor' => $proveedor,
                    'id_externo' => $idExterno,
                    ...$limpios,
                    'primera_visita_en' => $visita,
                    'ultima_visita_en' => $visita,
                ]);

                return $visitante;
            } catch (UniqueConstraintViolationException) {
                // Dos avisos de la misma persona a la vez: el otro ya lo creó.
                $visitante = VisitantePlataformaTenant::query()
                    ->where('proveedor', $proveedor)
                    ->where('id_externo', $idExterno)
                    ->firstOrFail();
            }
        }

        $visitante->fill($limpios);
        if ($visita !== null && ($visitante->ultima_visita_en === null || $visita->greaterThan($visitante->ultima_visita_en))) {
            $visitante->ultima_visita_en = Carbon::instance($visita);
        }
        if ($visita !== null && ($visitante->primera_visita_en === null || $visita->lessThan($visitante->primera_visita_en))) {
            $visitante->primera_visita_en = Carbon::instance($visita);
        }
        $visitante->save();

        return $visitante;
    }

    /**
     * La clase de una visita que llegó sola (la persona hizo check-in en la app): la que
     * reservó por la plataforma, si es de ese momento; si no, la única clase a esa hora
     * (de media hora antes de empezar a que termina). Con varias o ninguna, null: la
     * visita queda sin clase y recepción la asigna.
     */
    public function sesionDeVisita(?VisitantePlataformaTenant $visitante, CarbonInterface $cuando): ?SesionTenant
    {
        $candidatas = SesionTenant::query()
            ->where('tipo', TipoSesionTenant::Clase->value)
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '<=', $cuando->copy()->addMinutes(self::MINUTOS_ANTES))
            ->where('termina_en', '>=', $cuando)
            ->orderBy('inicia_en')
            ->get();
        if ($candidatas->isEmpty()) {
            return null;
        }

        if ($visitante instanceof VisitantePlataformaTenant) {
            $reservada = LugarPlataformaTenant::query()
                ->where('visitante_id', $visitante->getKey())
                ->whereIn('sesion_id', $candidatas->modelKeys())
                ->where('estado', LugarPlataformaTenant::RESERVADO)
                ->value('sesion_id');
            if ($reservada !== null) {
                return $candidatas->firstWhere('id', (int) $reservada);
            }
        }

        return $candidatas->count() === 1 ? $candidatas->first() : null;
    }

    private static function texto(?string $valor, int $largo): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, $largo);
    }

    private static function correo(?string $valor): ?string
    {
        $valor = mb_strtolower(trim((string) $valor));

        return filter_var($valor, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($valor, 0, 190) : null;
    }
}
