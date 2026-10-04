<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcanceClientesTenant;
use App\Modules\Tenancy\Application\RadarRenovacionesTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Radar de retención (Etapa 2): alumnos con membresía POR VENCER (próximos días) o
 * VENCIDA hace poco (recuperable), con señales de churn (última asistencia) para que
 * el estudio actúe antes de perderlos (la regla vive en RadarRenovacionesTenant).
 * `?formato=csv` exporta la lista para campañas de renovación.
 */
class RetencionTenantController
{
    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly RadarRenovacionesTenant $radar,
    ) {}

    public function porVencer(Request $request): Response
    {
        $dias = min(max((int) $request->query('dias', (string) $this->radar->diasPorDefecto()), 1), 90);

        // Alcance por sucursal (R19): el staff acotado solo ve el radar de SUS sedes.
        $actor = $request->attributes->get('usuario_tenant');
        // Números de todo el negocio: no para quien imparte (ve solo a sus clientes).
        abort_if($actor instanceof Usuario && app(AlcanceClientesTenant::class)->esAcotado($actor), 403);
        $permitidas = $actor instanceof Usuario ? $this->acceso->sucursalesPermitidas($actor) : null;

        return $this->responder($this->radar->miembros($dias, $permitidas), $dias, $request);
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
