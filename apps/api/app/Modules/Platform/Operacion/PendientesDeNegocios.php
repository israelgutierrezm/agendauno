<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Modules\Tenancy\Application\DespacharOutboxTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\AvisoPasarelaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que espera en la base de cada negocio: eventos del outbox y avisos de pasarela
 * (ADR 0232). Medirlo abre una conexión por negocio, así que lo mide el programador
 * (`agendauno:revisar-pendientes`) y la verificación lee la última medición.
 */
class PendientesDeNegocios
{
    private const CLAVE = 'operacion:pendientes-negocios';

    /** Dos vueltas perdidas del programador no cuentan: la siguiente medición lo sustituye. */
    private const MINUTOS_VIGENCIA = 30;

    private const MINUTOS_OUTBOX = 15;

    /** Más que el mayor reintento de un aviso (16 min). */
    private const MINUTOS_AVISO = 60;

    private const HORAS_FALLIDOS = 24;

    /** Negocios que se nombran en el detalle. */
    private const NEGOCIOS_EN_DETALLE = 10;

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /**
     * @return array{outbox: array{atrasados: int, aparcados: int, negocios: list<string>}, avisos: array{atascados: int, fallidos: int, negocios: list<string>}}
     */
    public function medir(): array
    {
        $outbox = ['atrasados' => 0, 'aparcados' => 0, 'negocios' => []];
        $avisos = ['atascados' => 0, 'fallidos' => 0, 'negocios' => []];
        $estudios = Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
            ->orderBy('id')
            ->get();

        foreach ($estudios as $estudio) {
            if (! $this->gestor->baseDeDatosExiste($estudio)) {
                continue;
            }
            $cuenta = $this->gestor->ejecutarAislado($estudio, fn (): array => $this->contar(), null);
            if ($cuenta === null) {
                continue;
            }
            $outbox['atrasados'] += $cuenta['atrasados'];
            $outbox['aparcados'] += $cuenta['aparcados'];
            $avisos['atascados'] += $cuenta['atascados'];
            $avisos['fallidos'] += $cuenta['fallidos'];
            if ($cuenta['atrasados'] + $cuenta['aparcados'] > 0 && count($outbox['negocios']) < self::NEGOCIOS_EN_DETALLE) {
                $outbox['negocios'][] = (string) $estudio->slug;
            }
            if ($cuenta['atascados'] + $cuenta['fallidos'] > 0 && count($avisos['negocios']) < self::NEGOCIOS_EN_DETALLE) {
                $avisos['negocios'][] = (string) $estudio->slug;
            }
        }

        $medido = ['outbox' => $outbox, 'avisos' => $avisos];
        Cache::put(self::CLAVE, $medido, self::MINUTOS_VIGENCIA * 60);

        return $medido;
    }

    /**
     * La última medición vigente o, si no hay (el programador no corre), una nueva.
     *
     * @return array{outbox: array{atrasados: int, aparcados: int, negocios: list<string>}, avisos: array{atascados: int, fallidos: int, negocios: list<string>}}
     */
    public function ultima(): array
    {
        /** @var array{outbox: array{atrasados: int, aparcados: int, negocios: list<string>}, avisos: array{atascados: int, fallidos: int, negocios: list<string>}}|null $medido */
        $medido = Cache::get(self::CLAVE);

        return $medido ?? $this->medir();
    }

    /**
     * En el negocio conectado. Uno sin la migración aún no cuenta.
     *
     * @return array{atrasados: int, aparcados: int, atascados: int, fallidos: int}
     */
    private function contar(): array
    {
        $ahora = CarbonImmutable::now();
        $cuenta = ['atrasados' => 0, 'aparcados' => 0, 'atascados' => 0, 'fallidos' => 0];
        if (Schema::connection('tenant')->hasTable('eventos_outbox')) {
            $sinPublicar = fn () => EventoOutboxTenant::query()->whereNull('publicado_en');
            $cuenta['atrasados'] = $sinPublicar()->where('intentos', '<', DespacharOutboxTenant::MAX_INTENTOS)
                ->where('ocurrido_en', '<', $ahora->subMinutes(self::MINUTOS_OUTBOX))->count();
            $cuenta['aparcados'] = $sinPublicar()->where('intentos', '>=', DespacharOutboxTenant::MAX_INTENTOS)->count();
        }
        if (Schema::connection('tenant')->hasTable('avisos_pasarela')) {
            $cuenta['atascados'] = AvisoPasarelaTenant::query()->where('estado', AvisoPasarelaTenant::PENDIENTE)
                ->where('recibido_en', '<', $ahora->subMinutes(self::MINUTOS_AVISO))->count();
            $cuenta['fallidos'] = AvisoPasarelaTenant::query()->where('estado', AvisoPasarelaTenant::FALLIDO)
                ->where('procesado_en', '>=', $ahora->subHours(self::HORAS_FALLIDOS))->count();
        }

        return $cuenta;
    }
}
