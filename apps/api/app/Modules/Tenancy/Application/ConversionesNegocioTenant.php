<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Medicion\EnviarConversionMedicion;
use App\Modules\Tenancy\Medicion\EnviosMedicion;
use App\Modules\Tenancy\Medicion\EstadoEnvioMedicion;
use App\Modules\Tenancy\Medicion\IdentificadoresNavegador;
use App\Modules\Tenancy\Medicion\ProveedorMedicion;
use App\Modules\Tenancy\Medicion\ResultadoEnvio;
use App\Modules\Tenancy\Models\AsistenciaTenant;
use App\Modules\Tenancy\Models\ContextoMedicionTenant;
use App\Modules\Tenancy\Models\DestinoMedicionTenant;
use App\Modules\Tenancy\Models\EnvioMedicionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Las conversiones de un negocio hacia su GA4 y su Meta (ADR 0143). Salen de sus
 * eventos de dominio (outbox), es decir, después de que la operación quedó confirmada:
 *
 * - solicitud: un prospecto nuevo de su formulario (un reenvío no es otro);
 * - cuenta activada: el cliente invitado activó su cuenta (la invitación sola no cuenta);
 * - compra: una orden realmente pagada, con monto (nunca una pendiente ni el regreso
 *   de la pasarela); `customer_type` nuevo o recurrente. Pagada con llaves de prueba de
 *   la pasarela, no se manda;
 * - devolución: un reembolso realmente aplicado o un cobro anulado, solo si su compra
 *   ya se había mandado a ese destino (solo GA4: Meta no tiene devolución);
 * - reserva confirmada: la primera de cada persona (lo demás es operación interna);
 * - si el negocio lo enciende: la primera asistencia y las renovaciones pagadas.
 *
 * La conversión se crea pendiente, una vez por destino, y `enviar` la manda aparte con
 * lo que la persona autorizó. Nada de un negocio sale a los destinos de otro ni a los
 * de la plataforma. En un negocio de salud solo se mandan eventos estándar, sin nombres
 * de servicios.
 */
class ConversionesNegocioTenant
{
    /**
     * Cada resultado, cómo se llama en cada proveedor (null = no se manda ahí) y, si es
     * adicional, qué lo enciende.
     *
     * @var array<string, array{ga4: string|null, meta: string|null, personalizado: bool, extra: string|null, parametros: array<string, string>}>
     */
    public const EVENTOS = [
        'solicitud' => ['ga4' => 'generate_lead', 'meta' => 'Lead', 'personalizado' => false, 'extra' => null, 'parametros' => ['lead_source' => 'formulario']],
        'cuenta_activada' => ['ga4' => 'sign_up', 'meta' => 'CompleteRegistration', 'personalizado' => false, 'extra' => null, 'parametros' => ['method' => 'invitacion']],
        'compra' => ['ga4' => 'purchase', 'meta' => 'Purchase', 'personalizado' => false, 'extra' => null, 'parametros' => []],
        'devolucion' => ['ga4' => 'refund', 'meta' => null, 'personalizado' => false, 'extra' => null, 'parametros' => []],
        'reserva' => ['ga4' => 'reserva_confirmada', 'meta' => 'Schedule', 'personalizado' => false, 'extra' => null, 'parametros' => []],
        'renovacion' => ['ga4' => 'renovacion_pagada', 'meta' => 'RenovacionPagada', 'personalizado' => true, 'extra' => 'renovacion', 'parametros' => []],
        'primera_asistencia' => ['ga4' => 'primera_asistencia', 'meta' => 'PrimeraAsistencia', 'personalizado' => true, 'extra' => 'asistencia', 'parametros' => []],
    ];

    /** Pasarelas en línea: lo pagado ahí pasó en la web; lo demás, en el mostrador. */
    private const EN_LINEA = ['stripe', 'mercadopago', 'openpay'];

    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly FuncionesPlan $funciones,
        private readonly RegistroDePasarelasTenant $pasarelas,
        private readonly EnviarConversionMedicion $enviarConversion,
        private readonly AlertasPlataforma $alertas,
    ) {}

    /** Del evento de dominio, las conversiones pendientes de cada destino encendido. */
    public function desdeEvento(EventoDeDominioTenant $evento): int
    {
        if (! in_array($evento->tipo, ['prospecto.creado', 'cuenta.creada', 'orden.pagada', 'pago.reembolsado', 'pago.anulado', 'reserva.confirmada', 'asistencia.marcada'], true)) {
            return 0;
        }
        /** @var Collection<int, DestinoMedicionTenant> $destinos */
        $destinos = DestinoMedicionTenant::query()->where('activo', true)->get()->filter->enUso()->values();
        if ($destinos->isEmpty() || ! $this->funciones->tieneElNegocioActual('integraciones')) {
            return 0;
        }
        $en = CarbonImmutable::parse(EventoOutboxTenant::query()->where('ulid', $evento->eventoUlid)->value('ocurrido_en') ?? now());

        return match ($evento->tipo) {
            'prospecto.creado' => $this->solicitud($destinos, $evento, $en),
            'cuenta.creada' => $this->cuentaActivada($destinos, $evento, $en),
            'orden.pagada' => $this->compra($destinos, $evento, $en),
            'pago.reembolsado', 'pago.anulado' => $this->devolucion($destinos, $evento, $en),
            'reserva.confirmada' => $this->reserva($destinos, $evento, $en),
            default => $this->primeraAsistencia($destinos, $evento, $en),
        };
    }

    public function enviar(CarbonImmutable $ahora, int $lote = 100): int
    {
        $estudio = $this->gestor->actual();
        $envios = EnviosMedicion::porMandar(EnvioMedicionTenant::query(), $ahora)->with(['destino', 'contexto'])->limit($lote)->get();
        foreach ($envios as $envio) {
            $destino = $envio->destino;
            $resultado = match (true) {
                ! $destino instanceof DestinoMedicionTenant || ! $destino->enUso() => ResultadoEnvio::omitido('destino_apagado'),
                $estudio instanceof Estudio && $estudio->es_demo => ResultadoEnvio::omitido('negocio_demo'),
                default => $this->enviarConversion->enviar(
                    $destino->proveedor,
                    (string) $destino->identificador,
                    (string) $destino->secreto,
                    $destino->codigo_prueba,
                    EnviosMedicion::conversion($envio, $destino->proveedor),
                    $envio->contexto?->contexto(),
                    $ahora,
                ),
            };
            if (EnviosMedicion::aplicar($envio, $destino, $resultado, $ahora) && $destino instanceof DestinoMedicionTenant) {
                $this->alertas->registrar(
                    'medicion_fallida',
                    ($estudio->slug ?? 'negocio').":{$destino->proveedor->value}",
                    "{$destino->proveedor->etiqueta()} de ".($estudio->nombre ?? 'un negocio').': '.($resultado->mensaje ?? 'no se pudo mandar una conversión.'),
                );
            }
        }

        return $envios->count();
    }

    /** Pasado el tiempo de retención, los identificadores del navegador se borran. */
    public function vencerIdentificadores(CarbonImmutable $ahora): int
    {
        return ContextoMedicionTenant::query()
            ->where('capturado_en', '<', $ahora->subDays((int) config('agendauno.medicion.retencion_identificadores_dias', 120)))
            ->where(fn ($q) => $q->whereNotNull('ga_client_id')->orWhereNotNull('fbp')->orWhereNotNull('fbc')
                ->orWhereNotNull('agente')->orWhereNotNull('ip'))
            ->update(IdentificadoresNavegador::alVencer());
    }

    /** Lo que retira un visitante se borra de lo que ya registró en este negocio. */
    public static function cambiarPreferencias(string $visitante, bool $analitica, bool $publicidad, CarbonImmutable $ahora): int
    {
        return ContextoMedicionTenant::query()->where('visitante', $visitante)
            ->update(IdentificadoresNavegador::alCambiar($analitica, $publicidad, $ahora));
    }

    /**
     * @param  Collection<int, DestinoMedicionTenant>  $destinos
     */
    private function solicitud(Collection $destinos, EventoDeDominioTenant $evento, CarbonImmutable $en): int
    {
        $prospecto = ProspectoTenant::query()->where('ulid', $evento->agregadoId)->first();
        if (! $prospecto instanceof ProspectoTenant) {
            return 0;
        }
        $contexto = $prospecto->contexto_medicion_id !== null ? ContextoMedicionTenant::query()->find($prospecto->contexto_medicion_id) : null;

        return $this->crear($destinos, 'solicitud', "solicitud-{$prospecto->ulid}", $en, $evento, null, $contexto);
    }

    /**
     * @param  Collection<int, DestinoMedicionTenant>  $destinos
     */
    private function cuentaActivada(Collection $destinos, EventoDeDominioTenant $evento, CarbonImmutable $en): int
    {
        $persona = $this->persona($evento->payload['persona_id'] ?? null);
        if (! $persona instanceof PersonaTenant) {
            return 0;
        }

        return $this->crear($destinos, 'cuenta_activada', "cuenta_activada-{$persona->ulid}", $en, $evento, $persona->getKey(), $this->contextoDe($persona->getKey()));
    }

    /**
     * @param  Collection<int, DestinoMedicionTenant>  $destinos
     */
    private function compra(Collection $destinos, EventoDeDominioTenant $evento, CarbonImmutable $en): int
    {
        $orden = OrdenTenant::query()->where('ulid', $evento->agregadoId)->with(['lineas.producto', 'pagos'])->first();
        if (! $orden instanceof OrdenTenant || $orden->estado !== EstadoOrden::Pagada || $orden->total_minor <= 0) {
            return 0;
        }
        $pago = $orden->pagos->sortByDesc('id')->first(fn (PagoTenant $p): bool => $p->getAttribute('aprobado_en') !== null) ?? $orden->pagos->sortByDesc('id')->first();
        $proveedor = $pago instanceof PagoTenant ? (string) $pago->proveedor : null;
        $enLinea = in_array($proveedor, self::EN_LINEA, true);
        $contexto = $orden->contexto_medicion_id !== null
            ? ContextoMedicionTenant::query()->find($orden->contexto_medicion_id)
            : $this->contextoDe($orden->persona_id);
        $renovacion = $orden->renueva_acuerdo_id !== null;
        $previa = OrdenTenant::query()->where('persona_id', $orden->persona_id)->where('estado', EstadoOrden::Pagada->value)
            ->whereNull('renueva_acuerdo_id')->where('id', '<', $orden->getKey())->exists();
        $items = $orden->lineas->map(fn (LineaOrdenTenant $l): array => [
            'id' => (string) ($l->producto->ulid ?? $l->getKey()),
            'nombre' => $l->producto->nombre ?? $l->getAttribute('descripcion'),
            'precio_minor' => (int) $l->getAttribute('precio_unitario_minor'),
            'cantidad' => (int) $l->getAttribute('cantidad'),
        ])->values()->all();

        return $this->crear(
            $destinos,
            $renovacion ? 'renovacion' : 'compra',
            ($renovacion ? 'renovacion' : 'compra')."-{$orden->ulid}",
            CarbonImmutable::parse($orden->pagada_en ?? $en),
            $evento,
            $orden->persona_id,
            $contexto,
            accion: $enLinea ? 'website' : 'physical_store',
            valor: [$orden->total_minor, (string) $orden->moneda, (string) $orden->ulid],
            items: $items,
            parametros: $renovacion ? [] : ['customer_type' => $previa ? 'returning' : 'new'],
            // Pagada con las llaves de prueba de la pasarela: no es una venta real.
            omitir: $enLinea && $this->pasarelas->enPrueba($proveedor) ? 'pago_de_prueba' : null,
        );
    }

    /**
     * @param  Collection<int, DestinoMedicionTenant>  $destinos
     */
    private function devolucion(Collection $destinos, EventoDeDominioTenant $evento, CarbonImmutable $en): int
    {
        $pago = PagoTenant::query()->where('ulid', $evento->agregadoId)->first();
        $orden = $pago instanceof PagoTenant ? OrdenTenant::query()->find($pago->orden_id) : null;
        if (! $orden instanceof OrdenTenant) {
            return 0;
        }
        $reembolso = $evento->tipo === 'pago.reembolsado'
            ? ReembolsoTenant::query()->where('ulid', (string) ($evento->payload['reembolso_id'] ?? ''))->first()
            : null;
        $monto = (int) ($evento->payload['monto_minor'] ?? 0);
        if ($monto <= 0) {
            return 0;
        }
        // Solo a los destinos que ya recibieron esa compra: devolver algo que no se
        // mandó solo confundiría sus reportes.
        $enviadas = EnvioMedicionTenant::query()->where('evento', 'compra')->where('transaccion', (string) $orden->ulid)
            ->where('estado', EstadoEnvioMedicion::Enviado->value)->pluck('contexto_medicion_id', 'destino_id');
        $destinos = $destinos->filter(fn (DestinoMedicionTenant $d): bool => $enviadas->has($d->getKey()))->values();
        $contextoId = $enviadas->first();

        return $this->crear(
            $destinos,
            'devolucion',
            $reembolso instanceof ReembolsoTenant ? "devolucion-{$reembolso->ulid}" : 'anulacion-'.$pago->ulid,
            CarbonImmutable::parse($reembolso?->getAttribute('aplicado_en') ?? $en),
            $evento,
            $orden->persona_id,
            $contextoId !== null ? ContextoMedicionTenant::query()->find($contextoId) : null,
            valor: [$monto, (string) ($evento->payload['moneda'] ?? $orden->moneda), (string) $orden->ulid],
        );
    }

    /**
     * @param  Collection<int, DestinoMedicionTenant>  $destinos
     */
    private function reserva(Collection $destinos, EventoDeDominioTenant $evento, CarbonImmutable $en): int
    {
        $reserva = ReservaTenant::query()->where('ulid', $evento->agregadoId)->first();
        if (! $reserva instanceof ReservaTenant) {
            return 0;
        }
        // Solo la primera reserva confirmada de la persona: las siguientes son su uso.
        $antes = ReservaTenant::query()->where('persona_id', $reserva->persona_id)->where('id', '<', $reserva->getKey())
            ->where('estado', EstadoReserva::Confirmada->value)->exists();
        if ($antes) {
            return 0;
        }

        return $this->crear($destinos, 'reserva', "reserva-{$reserva->ulid}", $en, $evento, (int) $reserva->persona_id, $this->contextoDe((int) $reserva->persona_id),
            parametros: ['tipo' => (string) ($evento->payload['tipo'] ?? 'clase')]);
    }

    /**
     * @param  Collection<int, DestinoMedicionTenant>  $destinos
     */
    private function primeraAsistencia(Collection $destinos, EventoDeDominioTenant $evento, CarbonImmutable $en): int
    {
        if (($evento->payload['estado'] ?? null) !== 'presente') {
            return 0;
        }
        $persona = $this->persona($evento->payload['persona_id'] ?? null);
        if (! $persona instanceof PersonaTenant) {
            return 0;
        }
        $previas = AsistenciaTenant::query()->where('estado', 'presente')
            ->whereHas('reserva', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->count();
        if ($previas > 1) {
            return 0;
        }

        return $this->crear($destinos, 'primera_asistencia', "primera_asistencia-{$persona->ulid}", $en, $evento, $persona->getKey(), $this->contextoDe($persona->getKey()));
    }

    /**
     * Una conversión pendiente por destino (si ese proveedor la recibe, si es
     * adicional y el negocio la encendió, y si pasó después de encender el destino).
     *
     * @param  Collection<int, DestinoMedicionTenant>  $destinos
     * @param  array{0: int, 1: string, 2: string}|null  $valor  monto, moneda y transacción
     * @param  list<array{id: string, nombre?: string|null, precio_minor?: int|null, cantidad: int}>  $items
     * @param  array<string, string|int|bool>  $parametros
     */
    private function crear(Collection $destinos, string $evento, string $idEvento, CarbonImmutable $en, EventoDeDominioTenant $origen,
        ?int $personaId, ?ContextoMedicionTenant $contexto, ?string $accion = null, ?array $valor = null, array $items = [],
        array $parametros = [], ?string $omitir = null): int
    {
        $definicion = self::EVENTOS[$evento];
        $estudio = $this->gestor->actual();
        $sensible = $estudio instanceof Estudio && $estudio->perfil_negocio === PerfilNegocio::Salud;
        // En la web si su navegador dejó página y agente; si no, lo registró el sistema.
        $accion ??= $contexto !== null && $contexto->url !== null && $contexto->agente !== null ? 'website' : 'system_generated';
        $creadas = 0;
        foreach ($destinos as $destino) {
            $nombre = $definicion[$destino->proveedor->value];
            if ($nombre === null
                || ($definicion['extra'] !== null && ! $destino->manda($definicion['extra']))
                || ($destino->activo_desde !== null && $en->lessThan($destino->activo_desde))) {
                continue;
            }
            try {
                $envio = EnvioMedicionTenant::query()->firstOrCreate([
                    'destino_id' => $destino->getKey(),
                    'id_evento' => $idEvento,
                ], [
                    'evento' => $evento,
                    'nombre' => $nombre,
                    'evento_ulid' => $origen->eventoUlid,
                    'persona_id' => $personaId,
                    'contexto_medicion_id' => $contexto?->getKey(),
                    'ocurrido_en' => $en,
                    'accion' => $accion,
                    'personalizado' => $destino->proveedor === ProveedorMedicion::Meta && $definicion['personalizado'],
                    'sensible' => $sensible,
                    'valor_minor' => $valor[0] ?? null,
                    'moneda' => $valor[1] ?? null,
                    'transaccion' => $valor[2] ?? null,
                    'items' => $items !== [] ? $items : null,
                    'parametros' => [...$definicion['parametros'], ...$parametros] ?: null,
                    'estado' => $omitir !== null ? EstadoEnvioMedicion::Omitido->value : EstadoEnvioMedicion::Pendiente->value,
                    'motivo' => $omitir,
                ]);
                $creadas += $envio->wasRecentlyCreated ? 1 : 0;
            } catch (QueryException) {
                // Ya existe (otro despacho del mismo evento).
            }
        }

        return $creadas;
    }

    /** El contexto más reciente de la persona: el suyo o el de su solicitud. */
    private function contextoDe(?int $personaId): ?ContextoMedicionTenant
    {
        if ($personaId === null) {
            return null;
        }
        $propio = ContextoMedicionTenant::query()->where('persona_id', $personaId)->latest('id')->first();
        if ($propio instanceof ContextoMedicionTenant) {
            return $propio;
        }
        $deSolicitud = ProspectoTenant::query()->where('persona_id', $personaId)->whereNotNull('contexto_medicion_id')->latest('id')->value('contexto_medicion_id');

        return $deSolicitud !== null ? ContextoMedicionTenant::query()->find($deSolicitud) : null;
    }

    private function persona(mixed $ulid): ?PersonaTenant
    {
        return is_string($ulid) && $ulid !== '' ? PersonaTenant::query()->withTrashed()->where('ulid', $ulid)->first() : null;
    }
}
