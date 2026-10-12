<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Captacion\EstadoProspecto;
use App\Modules\Tenancy\Captacion\TipoEventoProspecto;
use App\Modules\Tenancy\Models\EventoProspectoTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Un prospecto que nadie atiende (ADR 0138). Si pasan `captacion.horas_para_atender`
 * horas desde que llegó o se le asignó a alguien y nadie lo atendió (nota, contacto,
 * cambio de estado, seguimiento), se marca «sin atender»:
 * - queda en su historia;
 * - su responsable recibe una tarea (o el equipo, si no tiene responsable);
 * - se avisa al equipo que gestiona prospectos con la plantilla del negocio
 *   (`prospecto.sin_atender`).
 * En Prospectos aparece con su marca y su filtro, para reasignarlo. Una sola vez por
 * asignación: al atenderlo o reasignarlo se limpia y el plazo empieza de nuevo.
 */
class ProspectosSinAtenderTenant
{
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly GestionarTareasTenant $tareas,
        private readonly RegistrarEventoTenant $eventos,
    ) {}

    /** Marca los que vencieron su plazo; devuelve cuántos. */
    public function revisar(): int
    {
        $horas = $this->parametros->entero('captacion.horas_para_atender');
        if ($horas <= 0) {
            return 0;
        }
        $limite = CarbonImmutable::now()->subHours($horas);
        $marcados = 0;

        ProspectoTenant::query()
            ->whereIn('estado', EstadoProspecto::abiertos())
            ->whereNull('sin_atender_desde')
            ->where('created_at', '<=', $limite)
            ->with('responsable')
            ->chunkById(100, function (Collection $prospectos) use ($limite, $horas, &$marcados): void {
                /** @var Collection<int, ProspectoTenant> $prospectos */
                foreach ($prospectos as $prospecto) {
                    [$desde, $asignacion] = $this->desdeCuando($prospecto);
                    if ($desde->greaterThan($limite) || $this->atendidoDespues($prospecto, $asignacion)) {
                        continue;
                    }
                    $this->marcar($prospecto, $horas);
                    $marcados++;
                }
            });

        return $marcados;
    }

    /**
     * Desde cuándo le toca: la última vez que se asignó (y ese evento), o cuando llegó.
     *
     * @return array{0: CarbonImmutable, 1: EventoProspectoTenant|null}
     */
    private function desdeCuando(ProspectoTenant $prospecto): array
    {
        $asignacion = EventoProspectoTenant::query()
            ->where('prospecto_id', $prospecto->getKey())
            ->where('tipo', TipoEventoProspecto::Asignado->value)
            ->orderByDesc('id')
            ->first();

        return [CarbonImmutable::parse($asignacion !== null ? $asignacion->created_at : $prospecto->created_at), $asignacion];
    }

    /** ¿Alguien lo atendió después de asignarlo (o desde que llegó)? Por el orden de su historia. */
    private function atendidoDespues(ProspectoTenant $prospecto, ?EventoProspectoTenant $asignacion): bool
    {
        return EventoProspectoTenant::query()
            ->where('prospecto_id', $prospecto->getKey())
            ->whereIn('tipo', array_map(static fn (TipoEventoProspecto $t): string => $t->value, TipoEventoProspecto::atencion()))
            ->when($asignacion !== null, fn ($q) => $q->where('id', '>', $asignacion?->getKey()))
            ->exists();
    }

    private function marcar(ProspectoTenant $prospecto, int $horas): void
    {
        $responsable = $prospecto->responsable;
        $prospecto->update(['sin_atender_desde' => now()]);
        EventoProspectoTenant::query()->create([
            'prospecto_id' => $prospecto->getKey(),
            'tipo' => TipoEventoProspecto::SinAtender->value,
            'detalle' => array_filter(['responsable' => $responsable?->name, 'horas' => $horas], static fn (mixed $v): bool => $v !== null),
            'autor_id' => null,
        ]);
        $this->tareas->crear([
            'titulo' => $responsable !== null ? "Atiende a {$prospecto->nombre}" : "Asigna a alguien para atender a {$prospecto->nombre}",
            'detalle' => "Lleva más de {$horas} h sin que nadie le escriba ni le dé seguimiento. Escríbele o reasígnalo en Prospectos.",
            'responsable_id' => $responsable?->getKey(),
            'vence_en' => now(),
        ]);
        $this->eventos->registrar('prospecto.sin_atender', 'prospecto', (string) $prospecto->ulid, [
            'prospecto_id' => (string) $prospecto->ulid,
            'nombre' => $prospecto->nombre,
            'responsable' => $responsable?->name,
            'horas' => $horas,
        ]);
    }
}
