<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Medicion\EnviarConversionMedicion;
use App\Modules\Tenancy\Medicion\EnviosMedicion;
use App\Modules\Tenancy\Medicion\EstadoEnvioMedicion;
use App\Modules\Tenancy\Medicion\IdentificadoresNavegador;
use App\Modules\Tenancy\Medicion\ProveedorMedicion;
use App\Modules\Tenancy\Medicion\ResultadoEnvio;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\ContextoMedicionPlataforma;
use App\Modules\Tenancy\Models\DestinoMedicionPlataforma;
use App\Modules\Tenancy\Models\EnvioMedicionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * El embudo del SaaS hacia GA4 y Meta (ADR 0143). Cada resultado sale de lo que de
 * verdad pasó en el sistema, no de un clic:
 *
 * - negocio creado: el negocio quedó registrado y aprovisionado (no la activación de un
 *   miembro o un instructor invitado, que no es un negocio nuevo);
 * - prueba iniciada: su periodo de prueba arrancó (solo si el plan tiene prueba). Una
 *   prueba gratuita no es una venta;
 * - activación operativa: su primera operación real con un cliente que cuenta
 *   (`primera_operacion_en`, ADR 0090);
 * - primera suscripción pagada: su primer cargo de la suscripción realmente pagado
 *   (ni timbres, ni «sin cargo», ni montos en cero).
 *
 * `detectar` crea la conversión pendiente por cada destino encendido del producto del
 * negocio (una sola vez: `id_evento` es único por destino) y `enviar` la manda aparte.
 * Solo cuenta lo que pasó desde que el destino se encendió y dentro de la ventana del
 * proveedor. Las demos no se mandan.
 */
class ConversionesPlataforma
{
    /**
     * Cada resultado y cómo se llama en cada proveedor. Los de Meta sin equivalente
     * estándar son personalizados.
     *
     * @var array<string, array{ga4: string, meta: string, personalizado: bool, accion: string}>
     */
    public const EVENTOS = [
        'negocio_creado' => ['ga4' => 'sign_up', 'meta' => 'CompleteRegistration', 'personalizado' => false, 'accion' => 'website'],
        'prueba_iniciada' => ['ga4' => 'prueba_iniciada', 'meta' => 'StartTrial', 'personalizado' => false, 'accion' => 'website'],
        'negocio_activado' => ['ga4' => 'negocio_activado', 'meta' => 'ActivacionOperativa', 'personalizado' => true, 'accion' => 'system_generated'],
        'primera_suscripcion' => ['ga4' => 'purchase', 'meta' => 'Purchase', 'personalizado' => false, 'accion' => 'website'],
    ];

    public function __construct(
        private readonly EnviarConversionMedicion $enviarConversion,
        private readonly AlertasPlataforma $alertas,
    ) {}

    /**
     * Los identificadores de las conversiones del registro: el navegador manda con ellos
     * sus eventos del píxel para que Meta los deduplique con los del servidor.
     *
     * @return array<string, string>
     */
    public static function idsDelRegistro(Estudio $estudio): array
    {
        return array_filter([
            'negocio_creado' => 'negocio_creado-'.$estudio->ulid,
            'prueba_iniciada' => self::tienePrueba($estudio) ? 'prueba_iniciada-'.$estudio->ulid : null,
        ]);
    }

    public function detectar(CarbonImmutable $ahora): int
    {
        /** @var Collection<int, DestinoMedicionPlataforma> $destinos */
        $destinos = DestinoMedicionPlataforma::query()->where('activo', true)->get()->filter->enUso()->values();
        if ($destinos->isEmpty()) {
            return 0;
        }
        $desde = $ahora->subHours(ProveedorMedicion::Meta->horasMaximas());
        $creadas = 0;

        $registrados = Estudio::query()->where('es_demo', false)->whereNotNull('aprovisionado_en')
            ->where('aprovisionado_en', '>=', $desde)->get();
        foreach ($registrados as $estudio) {
            $en = CarbonImmutable::parse($estudio->aprovisionado_en);
            $creadas += $this->crear($destinos, $estudio, 'negocio_creado', $en, $ahora);
            if (self::tienePrueba($estudio)) {
                $creadas += $this->crear($destinos, $estudio, 'prueba_iniciada', $en, $ahora, parametros: [
                    'dias_prueba' => (int) $estudio->trial_inicia_en?->diffInDays($estudio->trial_termina_en),
                ]);
            }
        }

        $activados = Estudio::query()->where('es_demo', false)->where('primera_operacion_en', '>=', $desde)->get();
        foreach ($activados as $estudio) {
            $creadas += $this->crear($destinos, $estudio, 'negocio_activado', CarbonImmutable::parse($estudio->primera_operacion_en), $ahora);
        }

        $pagados = CargoRenta::query()->from('cargos_renta as c')->select('c.*')
            ->where('c.estado', EstadoCargoRenta::Pagado->value)->whereNotIn('c.concepto', ['timbres', 'whatsapp'])
            ->where('c.monto_minor', '>', 0)->whereNotNull('c.pagado_en')->where('c.pagado_en', '>=', $desde)
            ->whereNotExists(fn ($q) => $q->from('cargos_renta as previo')->whereColumn('previo.estudio_id', 'c.estudio_id')
                ->where('previo.estado', EstadoCargoRenta::Pagado->value)->whereNotIn('previo.concepto', ['timbres', 'whatsapp'])
                ->where('previo.monto_minor', '>', 0)->whereColumn('previo.pagado_en', '<', 'c.pagado_en'))
            ->get();
        foreach ($pagados as $cargo) {
            $estudio = Estudio::query()->find($cargo->estudio_id);
            if (! $estudio instanceof Estudio || $estudio->es_demo) {
                continue;
            }
            $producto = $estudio->producto();
            $creadas += $this->crear($destinos, $estudio, 'primera_suscripcion', CarbonImmutable::parse($cargo->getAttribute('pagado_en')), $ahora,
                idEvento: 'primera_suscripcion-'.$cargo->ulid,
                valor: [(int) $cargo->monto_minor, (string) $cargo->getAttribute('moneda'), (string) $cargo->ulid],
                items: [['id' => 'suscripcion-'.$producto->value, 'nombre' => 'Suscripción '.$producto->nombre(), 'precio_minor' => (int) $cargo->monto_minor, 'cantidad' => 1]],
                parametros: ['customer_type' => 'new'],
            );
        }

        return $creadas;
    }

    public function enviar(CarbonImmutable $ahora, int $lote = 100): int
    {
        $envios = EnviosMedicion::porMandar(EnvioMedicionPlataforma::query(), $ahora)
            ->with(['destino', 'estudio'])
            ->limit($lote)
            ->get();

        foreach ($envios as $envio) {
            $destino = $envio->destino;
            $resultado = match (true) {
                ! $destino instanceof DestinoMedicionPlataforma || ! $destino->enUso() => ResultadoEnvio::omitido('destino_apagado'),
                $envio->estudio?->es_demo === true => ResultadoEnvio::omitido('negocio_demo'),
                default => $this->enviarConversion->enviar(
                    $destino->proveedor,
                    (string) $destino->identificador,
                    (string) $destino->secreto,
                    $destino->codigo_prueba,
                    EnviosMedicion::conversion($envio, $destino->proveedor),
                    ContextoMedicionPlataforma::query()->where('estudio_id', $envio->estudio_id)->first()?->contexto(),
                    $ahora,
                ),
            };
            if (EnviosMedicion::aplicar($envio, $destino, $resultado, $ahora) && $destino instanceof DestinoMedicionPlataforma) {
                // Al superadmin: un token que ya no sirve o una conversión que ya no se reintenta.
                $this->alertas->registrar(
                    'medicion_fallida',
                    "plataforma:{$destino->producto->value}:{$destino->proveedor->value}",
                    "{$destino->proveedor->etiqueta()} de {$destino->producto->nombre()}: ".($resultado->mensaje ?? 'no se pudo mandar una conversión.'),
                );
            }
        }

        return $envios->count();
    }

    /**
     * Pasado el tiempo de retención, los identificadores del navegador se borran.
     */
    public function vencerIdentificadores(CarbonImmutable $ahora): int
    {
        return ContextoMedicionPlataforma::query()
            ->where('capturado_en', '<', $ahora->subDays((int) config('agendauno.medicion.retencion_identificadores_dias', 120)))
            ->where(fn ($q) => $q->whereNotNull('ga_client_id')->orWhereNotNull('fbp')->orWhereNotNull('fbc')
                ->orWhereNotNull('agente')->orWhereNotNull('ip'))
            ->update(IdentificadoresNavegador::alVencer());
    }

    /**
     * Aplica un cambio de preferencias de un visitante a lo que ya registró: lo que
     * retiró se borra y lo pendiente se revisa de nuevo al mandarlo.
     */
    public static function cambiarPreferencias(string $visitante, bool $analitica, bool $publicidad, CarbonImmutable $ahora): int
    {
        return ContextoMedicionPlataforma::query()->where('visitante', $visitante)
            ->update(IdentificadoresNavegador::alCambiar($analitica, $publicidad, $ahora));
    }

    /**
     * Una conversión pendiente por cada destino del producto del negocio, si pasó
     * después de que el destino se encendió y dentro de su ventana.
     *
     * @param  Collection<int, DestinoMedicionPlataforma>  $destinos
     * @param  array{0: int, 1: string, 2: string}|null  $valor  monto, moneda y transacción
     * @param  list<array{id: string, nombre?: string|null, precio_minor?: int|null, cantidad: int}>  $items
     * @param  array<string, string|int|bool>  $parametros
     */
    private function crear(Collection $destinos, Estudio $estudio, string $evento, CarbonImmutable $en, CarbonImmutable $ahora,
        ?string $idEvento = null, ?array $valor = null, array $items = [], array $parametros = []): int
    {
        $definicion = self::EVENTOS[$evento];
        $creadas = 0;
        foreach ($destinos as $destino) {
            if ($destino->producto !== $estudio->producto()
                || ($destino->activo_desde !== null && $en->lessThan($destino->activo_desde))
                || $en->lessThan($ahora->subHours($destino->proveedor->horasMaximas()))) {
                continue;
            }
            try {
                $envio = EnvioMedicionPlataforma::query()->firstOrCreate([
                    'destino_id' => $destino->getKey(),
                    'id_evento' => $idEvento ?? "{$evento}-{$estudio->ulid}",
                ], [
                    'estudio_id' => $estudio->getKey(),
                    'evento' => $evento,
                    'nombre' => $definicion[$destino->proveedor->value],
                    'ocurrido_en' => $en,
                    'accion' => $definicion['accion'],
                    'personalizado' => $destino->proveedor === ProveedorMedicion::Meta && $definicion['personalizado'],
                    'valor_minor' => $valor[0] ?? null,
                    'moneda' => $valor[1] ?? null,
                    'transaccion' => $valor[2] ?? null,
                    'items' => $items !== [] ? $items : null,
                    'parametros' => $parametros !== [] ? $parametros : null,
                    'estado' => EstadoEnvioMedicion::Pendiente->value,
                ]);
                $creadas += $envio->wasRecentlyCreated ? 1 : 0;
            } catch (QueryException) {
                // Otra corrida la creó al mismo tiempo: ya existe.
            }
        }

        return $creadas;
    }

    private static function tienePrueba(Estudio $estudio): bool
    {
        return $estudio->trial_inicia_en !== null && $estudio->trial_termina_en !== null
            && $estudio->trial_termina_en->greaterThan($estudio->trial_inicia_en);
    }
}
