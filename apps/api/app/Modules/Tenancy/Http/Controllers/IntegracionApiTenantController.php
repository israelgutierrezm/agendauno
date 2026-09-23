<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
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
}
