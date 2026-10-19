<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Comunicaciones\SegmentoComunicacion;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resuelve un {@see SegmentoComunicacion} en la audiencia concreta (personas) EN VIVO
 * desde los datos del estudio, sin listas guardadas. Reusa las mismas reglas del radar
 * de retención (por vencer/vencidas) y del padrón (primerizos). Debe correr con la
 * conexión del tenant ya activa.
 */
class ResolverSegmentoTenant
{
    /** Al contar quién quedó fuera por no querer promociones ({@see sinConsentimiento()}). */
    private bool $conQuienNoQuierePromociones = false;

    /** "Por vencer" y "vencida recuperable": ventanas que fija el negocio (ADR 0047). */
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly SegmentosRetencionTenant $retencion,
        private readonly ModalidadNegocioTenant $modalidad,
    ) {}

    /**
     * @return Collection<int, PersonaTenant>
     */
    public function resolver(SegmentoComunicacion $segmento): Collection
    {
        return match ($segmento) {
            SegmentoComunicacion::Todos => $this->miembros()->orderBy('id')->get(),
            SegmentoComunicacion::PorVencer => $this->porVencimiento(true),
            SegmentoComunicacion::Vencidos => $this->porVencimiento(false),
            SegmentoComunicacion::Primerizos => $this->primerizos(),
            // Retención (ADR 0159): las mismas personas que en Renovaciones › Para recuperar.
            default => $this->miembros()
                ->whereIn('id', array_column($this->retencion->personas($segmento->value), 'persona_id'))
                ->orderBy('id')
                ->get(),
        };
    }

    /**
     * Cuántos del segmento quedan fuera por no querer promociones (oposición ARCO), para
     * decirlo antes de enviar (ADR 0166).
     */
    public function sinConsentimiento(SegmentoComunicacion $segmento): int
    {
        $this->conQuienNoQuierePromociones = true;
        try {
            $todos = $this->resolver($segmento)->count();
        } finally {
            $this->conQuienNoQuierePromociones = false;
        }

        return max(0, $todos - $this->resolver($segmento)->count());
    }

    /**
     * Conteo en vivo de cada segmento (clave del enum => total), para la pantalla.
     *
     * @return array<string, int>
     */
    public function conteos(): array
    {
        $conteos = [];
        $citas = $this->modalidad->esCitas();
        foreach (array_filter(SegmentoComunicacion::cases(), static fn (SegmentoComunicacion $s): bool => $s->aplica($citas)) as $segmento) {
            $conteos[$segmento->value] = $this->resolver($segmento)->count();
        }

        return $conteos;
    }

    /**
     * Query base: personas con perfil de miembro, activas en el padrón.
     *
     * @return Builder<PersonaTenant>
     */
    private function miembros(): Builder
    {
        return PersonaTenant::query()
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('archivado', false)
            // Oposición (ARCO): quien no quiere promociones no entra en las difusiones.
            ->when(! $this->conQuienNoQuierePromociones, fn (Builder $q) => $q->where('recibe_promociones', true));
    }

    /**
     * Miembros por vencer (próximos días) o vencidos (gracia hacia atrás), según el MAX
     * `valido_hasta` de sus derechos bajo un acuerdo activo. Clasifica en PHP (evita
     * trampas de comparación de fechas en SQL).
     *
     * @return Collection<int, PersonaTenant>
     */
    private function porVencimiento(bool $porVencer): Collection
    {
        $hoy = app(FechasNegocioTenant::class)->dia();

        $filas = DerechoTenant::query()
            ->join('acuerdos', 'acuerdos.id', '=', 'derechos.acuerdo_id')
            ->where('acuerdos.estado', EstadoAcuerdo::Activo->value)
            ->whereNotNull('derechos.valido_hasta')
            ->groupBy('acuerdos.persona_id')
            ->selectRaw('acuerdos.persona_id as pid, MAX(derechos.valido_hasta) as vence')
            ->get();

        $porVencerDias = $this->parametros->entero('membresias.dias_por_vencer');
        $vencidaDias = $this->parametros->entero('membresias.dias_vencida_recuperable');
        $pids = [];
        foreach ($filas as $fila) {
            $vence = CarbonImmutable::parse((string) $fila->getAttribute('vence'))->startOfDay();
            $restantes = $hoy->diffInDays($vence, false);
            $coincide = $porVencer
                ? ($restantes >= 0 && $restantes <= $porVencerDias)
                : ($restantes < 0 && $restantes >= -$vencidaDias);
            if ($coincide) {
                $pids[] = (int) $fila->getAttribute('pid');
            }
        }

        // whereIn con lista vacía devuelve una colección vacía (Eloquent lo resuelve como 0=1).
        return $this->miembros()->whereIn('id', $pids)->orderBy('id')->get();
    }

    /**
     * Miembros PRIMERIZOS: sin ninguna asistencia registrada como presente (misma
     * señal derivada del roster/directorio).
     *
     * @return Collection<int, PersonaTenant>
     */
    private function primerizos(): Collection
    {
        $conAsistencia = ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->distinct()
            ->pluck('reservas.persona_id')
            ->all();

        return $this->miembros()
            ->when($conAsistencia !== [], fn (Builder $q): Builder => $q->whereNotIn('id', $conAsistencia))
            ->orderBy('id')
            ->get();
    }
}
