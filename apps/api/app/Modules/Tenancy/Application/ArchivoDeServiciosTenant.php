<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retirar un servicio o clase del catálogo y volver a ofrecerlo (ADR 0154). Archivado
 * ya no se agenda, no se vende ni aparece en línea; lo ya agendado sigue en pie y su
 * historial (citas, clases, reservas, ventas, reportes) sigue apuntando a él.
 *
 * No se archiva lo que dejaría algo a medias: un paquete vigente que lo incluye o una
 * clase recurrente que sigue generando fechas con él. Se dice cuáles, para resolverlo
 * primero.
 */
class ArchivoDeServiciosTenant
{
    /** Cuántos nombres se dicen de lo que lo impide (el resto, como «y N más»). */
    private const NOMBRES_EN_AVISO = 3;

    public function __construct(
        private readonly RegistrarAuditoria $auditoria,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * @return array{sesiones_futuras: int}
     */
    public function archivar(OfertaTenant $oferta, ?Usuario $actor): array
    {
        return DB::connection('tenant')->transaction(function () use ($oferta, $actor): array {
            // Bajo candado: dos que archivan o un cambio de paquete a la vez esperan.
            $bloqueada = OfertaTenant::query()->whereKey($oferta->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueada->archivada()) {
                return ['sesiones_futuras' => $this->sesionesFuturas($bloqueada)];
            }

            $paquetes = $bloqueada->incluidaEn()->vigentes()->orderBy('nombre')->pluck('nombre')->all();
            if ($paquetes !== []) {
                throw ValidationException::withMessages(['oferta' => [
                    'Está incluido en '.$this->lista($paquetes).'. Quítalo de ahí primero.',
                ]]);
            }

            $hoy = $this->fechas->hoy();
            $series = PlantillaHorarioTenant::query()
                ->where('oferta_id', $bloqueada->getKey())
                ->where('activo', true)
                ->where(fn (Builder $v) => $v->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $hoy))
                ->count();
            if ($series > 0) {
                throw ValidationException::withMessages(['oferta' => [
                    $series === 1
                        ? 'Una clase que se repite sigue generando fechas con él. Deja de repetirla primero en Reglas de la agenda › Clases que se repiten.'
                        : "{$series} clases que se repiten siguen generando fechas con él. Deja de repetirlas primero en Reglas de la agenda › Clases que se repiten.",
                ]]);
            }

            $bloqueada->update(['archivado_en' => now()]);
            $this->auditoria->registrar($actor, 'oferta.archivada', 'oferta', (string) $bloqueada->ulid, ['nombre' => $bloqueada->nombre], null);

            return ['sesiones_futuras' => $this->sesionesFuturas($bloqueada)];
        });
    }

    public function restaurar(OfertaTenant $oferta, ?Usuario $actor): void
    {
        if (! $oferta->archivada()) {
            return;
        }
        $oferta->update(['archivado_en' => null]);
        $this->auditoria->registrar($actor, 'oferta.restaurada', 'oferta', (string) $oferta->ulid, null, ['nombre' => $oferta->nombre]);
    }

    /** Lo ya agendado con él que sigue en pie (se respeta). */
    private function sesionesFuturas(OfertaTenant $oferta): int
    {
        return SesionTenant::query()
            ->where('oferta_id', $oferta->getKey())
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>', now())
            ->count();
    }

    /**
     * «A», «A y B» o «A, B, C y 2 más», entre comillas latinas.
     *
     * @param  list<string>  $nombres
     */
    private function lista(array $nombres): string
    {
        $citados = array_map(static fn (string $n): string => "«{$n}»", array_slice($nombres, 0, self::NOMBRES_EN_AVISO));
        $resto = count($nombres) - count($citados);
        if ($resto > 0) {
            return implode(', ', $citados)." y {$resto} más";
        }
        if (count($citados) === 1) {
            return $citados[0];
        }

        return implode(', ', array_slice($citados, 0, -1)).' y '.end($citados);
    }
}
