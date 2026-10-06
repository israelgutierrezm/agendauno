<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;

/**
 * Un solo criterio de «listo para operar» (ADR 0090), el mismo para el asistente de
 * configuración inicial y para «Pon tu negocio en marcha» del panel:
 *
 * - los pasos, según cómo trabaja el negocio (citas o clases), con las reglas básicas
 *   (cancelación e inasistencia) revisadas o aceptadas como un paso más;
 * - tres estados que no siempre coinciden: configurado (todos los pasos de
 *   configuración hechos), publicado (su página y la reserva en línea abiertas) y
 *   recibe reservas (publicado y con una fecha que un cliente de verdad puede
 *   reservar en los próximos días).
 *
 * Un paso está hecho cuando existen sus datos en la BD del negocio; solo las reglas
 * se anotan además como revisadas, porque nacen con valores razonables.
 */
class PuestaEnMarchaTenant
{
    /** @var list<string> */
    public const PASOS_CITAS = ['negocio', 'servicios', 'equipo', 'reglas', 'publicacion'];

    /** @var list<string> */
    public const PASOS_CLASES = ['negocio', 'clases', 'horario', 'planes', 'reglas', 'publicacion'];

    /** Cuántos días hacia adelante se busca una fecha que se pueda reservar. */
    private const DIAS_A_REVISAR = 14;

    public function __construct(
        private readonly CalcularDisponibilidadTenant $disponibilidad,
    ) {}

    /**
     * @return list<string>
     */
    public function pasos(Estudio $estudio): array
    {
        return $estudio->modalidad() === ModalidadServicio::Citas ? self::PASOS_CITAS : self::PASOS_CLASES;
    }

    /**
     * Los pasos hechos, según los datos reales del negocio.
     *
     * @return list<string>
     */
    public function hechos(Estudio $estudio): array
    {
        return array_values(array_filter($this->pasos($estudio), fn (string $paso): bool => $this->hecho($estudio, $paso)));
    }

    public function hecho(Estudio $estudio, string $paso): bool
    {
        return match ($paso) {
            'negocio' => SucursalTenant::query()->exists(),
            // En citas, lo que el cliente puede agendar en línea: un servicio con precio.
            'servicios' => OfertaTenant::query()->where('politica_reserva', PoliticaReservaTenant::Pago->value)->exists(),
            'clases' => OfertaTenant::query()->exists(),
            'equipo', 'horario' => $this->horariosListos($estudio),
            'planes' => ProductoTenant::query()->exists(),
            'reglas' => $this->reglasRevisadas($estudio),
            // Revisada por el dueño (vista previa y publicar) y de verdad publicada.
            'publicacion' => array_key_exists('publicacion', $estudio->onboarding_pasos ?? []) && $estudio->paginaPublica(),
            default => false,
        };
    }

    /**
     * @return array{configurado: bool, publicado: bool, reservable: bool, listo: bool, primera_fecha: array<string, string|null>|null, motivo: string|null}
     */
    public function estado(Estudio $estudio): array
    {
        $configuracion = array_values(array_diff($this->pasos($estudio), ['publicacion']));
        $configurado = array_diff($configuracion, $this->hechos($estudio)) === [];
        $publicado = $estudio->paginaPublica();
        [$primera, $motivo] = $estudio->modalidad() === ModalidadServicio::Citas
            ? $this->primeraCita()
            : $this->primeraClase();
        $reservable = $publicado && $primera !== null;

        return [
            'configurado' => $configurado,
            'publicado' => $publicado,
            'reservable' => $reservable,
            // Listo para operar: todo configurado, la publicación revisada y una fecha reservable.
            'listo' => $configurado && $reservable && $this->hecho($estudio, 'publicacion'),
            'primera_fecha' => $primera,
            'motivo' => $primera === null ? $motivo : ($publicado ? null : 'sin_publicar'),
        ];
    }

    public function reglasRevisadas(Estudio $estudio): bool
    {
        return array_key_exists('reglas', $estudio->onboarding_pasos ?? [])
            && PoliticaCancelacionTenant::query()->whereNull('actividad_id')->exists();
    }

    /**
     * ¿Ya hay horarios? En citas: el horario de atención de algún profesional. En
     * clases: una plantilla recurrente o alguna clase programada.
     */
    public function horariosListos(Estudio $estudio): bool
    {
        return $estudio->modalidad() === ModalidadServicio::Citas
            ? HorarioAtencionTenant::query()->exists()
            : PlantillaHorarioTenant::query()->exists() || SesionTenant::query()->exists();
    }

    /**
     * La primera hora libre para el servicio más corto, en cualquier sucursal, con
     * el mismo cálculo que usa la página de agendar.
     *
     * @return array{0: array<string, string|null>|null, 1: string}
     */
    private function primeraCita(): array
    {
        $servicio = OfertaTenant::query()
            ->where('politica_reserva', PoliticaReservaTenant::Pago->value)
            ->orderBy('duracion_minutos')
            ->first();
        if (! $servicio instanceof OfertaTenant) {
            return [null, 'sin_servicios'];
        }
        if (! HorarioAtencionTenant::query()->exists()) {
            return [null, 'sin_horario'];
        }
        [$duracion, $margenes] = $this->disponibilidad->duracionYMargenes($servicio, null);

        $mejor = null;
        foreach (SucursalTenant::query()->orderBy('id')->get() as $sucursal) {
            $zona = (string) ($sucursal->zona_horaria ?: app(FechasNegocioTenant::class)->zona());
            $hoy = CarbonImmutable::now($zona)->toDateString();
            foreach ($this->disponibilidad->diasConAtencion($sucursal, $hoy, self::DIAS_A_REVISAR) as $dia) {
                if (! $dia['abierto']) {
                    continue;
                }
                $huecos = $this->disponibilidad->paraCualquiera($sucursal, $dia['fecha'], $duracion, null, $margenes, $servicio);
                if ($huecos === []) {
                    continue;
                }
                $inicia = (string) $huecos[0]['inicia'];
                if ($mejor === null || $inicia < $mejor['inicia_en']) {
                    $mejor = [
                        'inicia_en' => $inicia,
                        'zona_horaria' => $zona,
                        'sucursal' => $sucursal->nombre,
                        'que' => $servicio->nombre,
                    ];
                }
                break;
            }
        }

        return [$mejor, 'sin_huecos'];
    }

    /**
     * La próxima clase con lugar, siempre que haya cómo entrar: un plan a la venta o
     * una clase que se paga suelta.
     *
     * @return array{0: array<string, string|null>|null, 1: string}
     */
    private function primeraClase(): array
    {
        $ahora = CarbonImmutable::now();
        $clase = SesionTenant::query()
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('tipo', TipoSesionTenant::Clase->value)
            ->whereBetween('inicia_en', [$ahora, $ahora->addDays(self::DIAS_A_REVISAR)])
            ->withCount(['reservas as confirmadas' => fn ($q) => $q->where('estado', EstadoReserva::Confirmada->value)])
            ->with(['oferta', 'sucursal'])
            ->orderBy('inicia_en')
            ->limit(200)
            ->get()
            ->first(static fn (SesionTenant $s): bool => $s->capacidad === null || (int) $s->getAttribute('confirmadas') < $s->capacidad);
        if (! $clase instanceof SesionTenant) {
            return [null, 'sin_clases'];
        }

        $sePagaSuelta = $clase->oferta?->politica_reserva === PoliticaReservaTenant::Pago;
        if (! $sePagaSuelta && ! ProductoTenant::query()->where('archivado', false)->exists()) {
            return [null, 'sin_planes'];
        }

        return [[
            'inicia_en' => $clase->inicia_en->toIso8601String(),
            'zona_horaria' => $clase->zona_horaria,
            'sucursal' => $clase->sucursal?->nombre,
            'que' => $clase->oferta?->nombre,
        ], 'sin_clases'];
    }
}
