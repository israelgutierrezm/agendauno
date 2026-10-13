<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Console\Commands\SincronizarPlataformas;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Integraciones\LugaresDeHorario;
use App\Modules\Tenancy\Integraciones\PublicadorPlataforma;
use App\Modules\Tenancy\Integraciones\PublicadorTotalPass;
use App\Modules\Tenancy\Integraciones\PublicadorWellhub;
use App\Modules\Tenancy\Integraciones\ResultadoPlataforma;
use App\Modules\Tenancy\Models\HorarioPlataformaTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\PublicacionPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\ProveedorPartner;
use App\Modules\Tenancy\TipoSesionTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Las clases publicadas en Wellhub y TotalPass para reservarse desde su app (ADR 0149).
 *
 * - Se publican las sesiones programadas de las clases elegidas, de la sucursal de la
 *   integración, desde ahora hasta los días por adelantado del negocio
 *   (`plataformas.dias_publicados`).
 * - Sus lugares salen de la regla única de cupo ({@see CupoDeSesionTenant}): lo que la
 *   plataforma aún puede tomar, sin lo apartado para otros canales. Una clase sin cupo
 *   ofrece `plataformas.lugares_sin_tope`.
 * - Solo se llama a la plataforma cuando algo cambió: el horario (inicio, duración,
 *   instructor, sala, producto) se manda completo; si solo cambian los lugares, solo los
 *   lugares.
 * - Lo que deja de publicarse (sesión cancelada o movida, clase que ya no se publica,
 *   integración apagada) se retira: la plataforma cancela sus reservas y aquí quedan
 *   canceladas.
 *
 * Corre cada pocos minutos ({@see SincronizarPlataformas}) y,
 * para una sola clase, después de cada reserva o cancelación de la plataforma.
 */
class PublicarEnPlataformasTenant
{
    /**
     * Cuántas sesiones se revisan por vuelta. Las que no cambiaron no llaman a la
     * plataforma; las que no alcanzan entran en la siguiente vuelta.
     */
    private const LOTE = 1000;

    public function __construct(
        private readonly CupoDeSesionTenant $cupo,
        private readonly ParametrosTenant $parametros,
        private readonly PublicadorWellhub $wellhub,
        private readonly PublicadorTotalPass $totalpass,
    ) {}

    /** Cómo se publica en cada plataforma; null si aún no se publica en ella. */
    public function publicador(ProveedorPartner $proveedor): ?PublicadorPlataforma
    {
        return match ($proveedor) {
            ProveedorPartner::Wellhub => $this->wellhub,
            ProveedorPartner::TotalPass => $this->totalpass,
        };
    }

    /** Sincroniza lo publicado en cada plataforma del negocio; cuántos horarios cambiaron. */
    public function sincronizar(): int
    {
        $cambios = 0;
        foreach (IntegracionTenant::query()->get() as $integracion) {
            $cambios += $this->sincronizarIntegracion($integracion);
        }

        return $cambios;
    }

    public function sincronizarIntegracion(IntegracionTenant $integracion): int
    {
        $proveedor = ProveedorPartner::tryFrom($integracion->proveedor);
        $publicador = $proveedor !== null ? $this->publicador($proveedor) : null;
        if ($proveedor === null || ! $publicador instanceof PublicadorPlataforma) {
            return 0;
        }
        $credenciales = $integracion->llaves();
        $listas = $this->publicaciones($integracion, $publicador, $credenciales);

        $ahora = now();
        $hasta = $ahora->copy()->addDays($this->parametros->entero('plataformas.dias_publicados'));
        $sesiones = $listas === [] ? new Collection : SesionTenant::query()
            ->whereIn('oferta_id', array_keys($listas))
            ->where('sucursal_id', $integracion->sucursal_id)
            ->where('tipo', TipoSesionTenant::Clase->value)
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>', $ahora)
            ->where('inicia_en', '<=', $hasta)
            ->withCount(CupoDeSesionTenant::conteos())
            ->with(['recurso', 'instructor', 'oferta'])
            ->orderBy('inicia_en')
            ->limit(self::LOTE)
            ->get();

        $cambios = 0;
        if ($sesiones->isNotEmpty()) {
            $this->cupo->cargar($sesiones, $proveedor->value);
            $delCanal = $this->delCanal($proveedor, $sesiones->modelKeys());
            $publicados = HorarioPlataformaTenant::query()
                ->where('proveedor', $proveedor->value)
                ->whereIn('sesion_id', $sesiones->modelKeys())
                ->get()
                ->keyBy('sesion_id');
            foreach ($sesiones as $sesion) {
                $cambios += $this->publicarSesion(
                    $publicador,
                    $listas[(int) $sesion->oferta_id],
                    $sesion,
                    $this->lugares($sesion, $delCanal[(int) $sesion->getKey()] ?? 0),
                    $publicados->get($sesion->getKey()),
                    $credenciales,
                );
            }
        }

        // Lo que ya no se publica y aún no empieza se retira: su clase dejó de publicarse,
        // o la sesión se canceló, cambió de sucursal o de oferta, o quedó fuera de los días.
        $publicaciones = array_map(fn (PublicacionPlataformaTenant $p): int => (int) $p->getKey(), array_values($listas));
        HorarioPlataformaTenant::query()
            ->where('proveedor', $proveedor->value)
            ->where('estado', '!=', HorarioPlataformaTenant::RETIRADO)
            ->whereNotNull('id_externo')
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', $ahora))
            ->where(fn ($q) => $q
                ->whereNotIn('publicacion_id', $publicaciones)
                ->orWhereHas('sesion', fn ($s) => $s
                    ->where('estado', '!=', EstadoSesionTenant::Programada->value)
                    ->orWhere('sucursal_id', '!=', $integracion->sucursal_id)
                    ->orWhereNotIn('oferta_id', array_keys($listas))
                    ->orWhere('inicia_en', '>', $hasta)))
            ->with(['sesion', 'publicacion'])
            ->limit(self::LOTE)
            ->get()
            ->each(function (HorarioPlataformaTenant $publicado) use ($publicador, $credenciales, &$cambios): void {
                $cambios += $this->retirar($publicador, $publicado, $credenciales);
            });

        return $cambios;
    }

    /**
     * Vuelve a mandar los lugares de una clase ya publicada (después de una reserva o
     * cancelación de la plataforma, o de un cambio en la clase).
     */
    public function sincronizarSesion(SesionTenant $sesion, ProveedorPartner $proveedor): void
    {
        $publicador = $this->publicador($proveedor);
        $integracion = IntegracionTenant::query()->where('proveedor', $proveedor->value)->first();
        $publicado = HorarioPlataformaTenant::query()
            ->where('proveedor', $proveedor->value)
            ->where('sesion_id', $sesion->getKey())
            ->where('estado', HorarioPlataformaTenant::PUBLICADO)
            ->with('publicacion')
            ->first();
        if (! $publicador instanceof PublicadorPlataforma || ! $integracion instanceof IntegracionTenant
            || ! $publicado instanceof HorarioPlataformaTenant || ! $publicado->publicacion instanceof PublicacionPlataformaTenant
            || ! $this->publica($integracion) || $sesion->inicia_en->isPast()) {
            return;
        }
        $fresca = SesionTenant::query()->whereKey($sesion->getKey())
            ->withCount(CupoDeSesionTenant::conteos())
            ->with(['recurso', 'instructor', 'oferta'])
            ->first();
        if (! $fresca instanceof SesionTenant) {
            return;
        }
        $this->cupo->cargar([$fresca], $proveedor->value);
        $delCanal = $this->delCanal($proveedor, [(int) $fresca->getKey()]);
        $this->publicarSesion(
            $publicador,
            $publicado->publicacion,
            $fresca,
            $this->lugares($fresca, $delCanal[(int) $fresca->getKey()] ?? 0),
            $publicado,
            $integracion->llaves(),
        );
    }

    /**
     * Los lugares que se le ofrecen a la plataforma en una clase ya cargada con los
     * conteos y lo apartado para los demás canales.
     */
    public function lugares(SesionTenant $sesion, int $delCanal): LugaresDeHorario
    {
        $ocupadas = CupoDeSesionTenant::ocupadosCargados($sesion);
        if ($sesion->capacidad === null) {
            $total = max($this->parametros->entero('plataformas.lugares_sin_tope'), $ocupadas);

            return new LugaresDeHorario($total, max(0, $total - $ocupadas), $delCanal);
        }

        return new LugaresDeHorario((int) $sesion->capacidad, (int) $this->cupo->libres($sesion), $delCanal);
    }

    /** ¿La integración publica sus clases? */
    public function publica(IntegracionTenant $integracion): bool
    {
        return $integracion->activa && $integracion->reservas_activas && $integracion->sucursal_id !== null;
    }

    /**
     * Las clases que se publican, ya listas en la plataforma, por oferta.
     *
     * @param  array<string, string>  $credenciales
     * @return array<int, PublicacionPlataformaTenant>
     */
    private function publicaciones(IntegracionTenant $integracion, PublicadorPlataforma $publicador, array $credenciales): array
    {
        if (! $this->publica($integracion)) {
            return [];
        }
        $listas = [];
        PublicacionPlataformaTenant::query()
            ->where('proveedor', $integracion->proveedor)
            ->where('activa', true)
            ->with('oferta.actividad')
            ->get()
            ->each(function (PublicacionPlataformaTenant $publicacion) use ($publicador, $credenciales, &$listas): void {
                if ($publicacion->oferta === null) {
                    return;
                }
                $error = $publicador->prepararClase($publicacion, $credenciales);
                $publicacion->error = $error !== null ? Str::limit($error, 290) : null;
                if ($publicacion->isDirty('error')) {
                    $publicacion->save();
                }
                if ($error === null) {
                    $listas[(int) $publicacion->oferta_id] = $publicacion;
                }
            });

        return $listas;
    }

    /**
     * Publica o actualiza una sesión si algo cambió; 1 si llamó a la plataforma.
     *
     * @param  array<string, string>  $credenciales
     */
    private function publicarSesion(
        PublicadorPlataforma $publicador,
        PublicacionPlataformaTenant $publicacion,
        SesionTenant $sesion,
        LugaresDeHorario $lugares,
        ?HorarioPlataformaTenant $publicado,
        array $credenciales,
    ): int {
        $horario = $publicador->horario($sesion, $publicacion);
        $firma = hash('sha256', (string) json_encode([$publicacion->clase_externa, $horario]));
        $numeros = $publicador->numeros($lugares);
        $fija = $publicador->firmaFija($horario);
        $publicado ??= new HorarioPlataformaTenant([
            'proveedor' => $publicador->proveedor()->value,
            'sesion_id' => $sesion->getKey(),
            'publicacion_id' => $publicacion->getKey(),
            'estado' => HorarioPlataformaTenant::ERROR,
        ]);

        $nuevo = $publicado->id_externo === null || $publicado->estado === HorarioPlataformaTenant::RETIRADO
            || (int) $publicado->publicacion_id !== (int) $publicacion->getKey();
        if ($nuevo) {
            $resultado = $publicador->crear($publicacion, $horario, $lugares, $credenciales);
            if ($resultado->ok && $resultado->id === null) {
                $resultado = ResultadoPlataforma::rechazado('La plataforma no devolvió el horario publicado.');
            }
        } elseif ($publicado->firma !== $firma) {
            $resultado = $publicador->actualizar($publicado, $publicacion, $horario, $lugares, $credenciales);
        } elseif ($publicado->estado === HorarioPlataformaTenant::ERROR
            || [$publicado->capacidad_publicada, $publicado->reservados_publicados] !== $numeros) {
            $resultado = $publicador->actualizarLugares($publicado, $publicacion, $lugares, $credenciales);
        } else {
            return 0;
        }

        if ($resultado->ok) {
            // Nuevo, o publicado de nuevo (TotalPass): con el id que dio la plataforma.
            $otroId = $nuevo || $resultado->id !== null;
            $publicado->fill([
                'publicacion_id' => $publicacion->getKey(),
                'id_externo' => $otroId ? $resultado->id : $publicado->id_externo,
                'evento_externo' => $otroId ? $resultado->evento : $publicado->evento_externo,
                'firma' => $firma,
                'fija' => $fija,
                'capacidad_publicada' => $numeros[0],
                'reservados_publicados' => $numeros[1],
                'estado' => HorarioPlataformaTenant::PUBLICADO,
                'error' => null,
                'sincronizado_en' => now(),
            ]);
        } else {
            $publicado->fill([
                'estado' => $nuevo ? HorarioPlataformaTenant::ERROR : $publicado->estado,
                'error' => Str::limit((string) $resultado->error, 290),
            ]);
            if (! $nuevo) {
                // Lo publicado no cambió: en la siguiente vuelta se reintenta.
                $publicado->firma = $publicado->firma === $firma ? $firma : null;
                $publicado->capacidad_publicada = null;
            }
        }
        $publicado->save();

        return 1;
    }

    /**
     * Retira un horario de la plataforma (ella cancela sus reservas) y cancela aquí las
     * reservas que tenía; 1 si llamó a la plataforma.
     *
     * @param  array<string, string>  $credenciales
     */
    private function retirar(PublicadorPlataforma $publicador, HorarioPlataformaTenant $publicado, array $credenciales): int
    {
        $publicacion = $publicado->publicacion;
        if (! $publicacion instanceof PublicacionPlataformaTenant) {
            return 0;
        }
        $resultado = $publicador->retirar($publicado, $publicacion, $credenciales);
        if (! $resultado->ok) {
            $publicado->fill(['error' => Str::limit((string) $resultado->error, 290)])->save();

            return 1;
        }
        $publicado->fill(['estado' => HorarioPlataformaTenant::RETIRADO, 'error' => null, 'sincronizado_en' => now()])->save();
        LugarPlataformaTenant::query()
            ->where('sesion_id', $publicado->sesion_id)
            ->where('proveedor', $publicado->proveedor)
            ->where('origen', LugarPlataformaTenant::ORIGEN_RESERVA)
            ->where('estado', LugarPlataformaTenant::RESERVADO)
            ->update(['estado' => LugarPlataformaTenant::CANCELADO, 'motivo' => 'CLASE_RETIRADA', 'updated_at' => now()]);

        return 1;
    }

    /**
     * Cuántos lugares ocupan ya los visitantes de esa plataforma en cada sesión.
     *
     * @param  list<int>  $sesiones
     * @return array<int, int>
     */
    private function delCanal(ProveedorPartner $proveedor, array $sesiones): array
    {
        return LugarPlataformaTenant::query()
            ->whereIn('sesion_id', $sesiones)
            ->where('proveedor', $proveedor->value)
            ->whereIn('estado', LugarPlataformaTenant::OCUPAN_LUGAR)
            ->selectRaw('sesion_id, COUNT(*) as total')
            ->groupBy('sesion_id')
            ->pluck('total', 'sesion_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }
}
