<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeSesion;
use App\Modules\Tenancy\Creditos\Exceptions\SaldoInsuficiente;
use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReglaCapacidadCanalTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\CupoLleno;
use App\Modules\Tenancy\Reservas\Exceptions\FueraDeVentana;
use App\Modules\Tenancy\Reservas\Exceptions\LugarNoDisponible;
use App\Modules\Tenancy\Reservas\Exceptions\OfertaNoDisponible;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaException;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use App\Modules\Tenancy\Reservas\Exceptions\SinDerechoDisponible;
use App\Modules\Tenancy\Reservas\Exceptions\TransferenciaInvalida;
use App\Modules\Tenancy\Reservas\Exceptions\YaReservado;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor de reserva tenant-local (ver docs/BOOKING_ENGINE.md). Valida ventana,
 * estado de la sesion, duplicados y capacidad; resuelve un derecho y coloca una
 * retencion (hold); todo dentro de una transaccion con `lockForUpdate` sobre la
 * sesion (en la conexion del tenant) para que dos reservas concurrentes no
 * sobrepasen la capacidad (invariante de no-sobreventa).
 */
class ReservasTenant
{
    // Costo por defecto de una sesion: 1 credito = 1000 unidades escaladas.
    private const UNIDADES_POR_SESION = 1000;

    // Ventana (min) para aceptar una oferta de lista de espera antes de que expire (R7).
    private const VENTANA_OFERTA_MIN = 30;

    // Ventana (min) para pagar una reserva pago-para-reservar antes de liberar el cupo.
    private const VENTANA_PAGO_MIN = 30;

    public function __construct(
        private readonly ResolverDerechoTenant $resolver,
        private readonly CreditosTenant $creditos,
        private readonly ResolverPoliticaCancelacionTenant $politicas,
        private readonly RegistrarEventoTenant $eventos,
        private readonly OrdenesTenant $ordenes,
        private readonly EmitirReservaConfirmadaTenant $confirmada,
        private readonly EmitirReservaCanceladaTenant $cancelada,
        private readonly VerificarAgendaTenant $agenda,
        private readonly CerrarIntentosPagoTenant $intentos,
    ) {}

    /**
     * Reserva-y-paga (citas, pago-para-reservar): crea una reserva PENDIENTE DE PAGO que
     * RETIENE el cupo + una orden por la sesión, atómicamente y bajo lock (no-sobreventa).
     * Al pagar esa orden, el fulfillment confirma la reserva. Devuelve la reserva (con
     * `orden_id`); el cobro/checkout lo dispara el controlador con el motor de pago que
     * ya existe. No resuelve un derecho: el acceso lo habilita el PAGO, no una membresía.
     */
    public function reservarConPago(SesionTenant $sesion, PersonaTenant $persona, int $montoMinor, string $moneda, ?int $sucursalId = null, string $canal = 'directo'): ReservaTenant
    {
        return $this->reservarConOrden($sesion, $persona, $montoMinor, $moneda, $sucursalId, $canal, EstadoReserva::PendientePago);
    }

    /**
     * Cita agendada POR EL NEGOCIO (recepción, teléfono, mostrador) de un servicio de
     * pago: la reserva nace CONFIRMADA — no expira, porque se cobra en caja (casi
     * siempre al terminar el servicio) — y la orden por la sesión queda pendiente de
     * cobro. Atómico y bajo lock, igual que el pago en línea.
     */
    public function reservarPorNegocio(SesionTenant $sesion, PersonaTenant $persona, int $montoMinor, string $moneda, ?int $sucursalId = null): ReservaTenant
    {
        return $this->reservarConOrden($sesion, $persona, $montoMinor, $moneda, $sucursalId, 'directo', EstadoReserva::Confirmada);
    }

    /**
     * Reserva ligada a una orden por la sesión: `PendientePago` (retiene el cupo hasta
     * pagar en línea o expirar) o `Confirmada` (el negocio cobra en caja).
     */
    private function reservarConOrden(SesionTenant $sesion, PersonaTenant $persona, int $montoMinor, string $moneda, ?int $sucursalId, string $canal, EstadoReserva $estado): ReservaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($sesion, $persona, $montoMinor, $moneda, $sucursalId, $canal, $estado): ReservaTenant {
            $bloqueada = SesionTenant::query()->whereKey($sesion->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoSesionTenant::Programada) {
                throw new SesionNoReservable('La sesion no admite reservas.');
            }
            if ($bloqueada->inicia_en->isPast()) {
                throw new FueraDeVentana('La sesion ya inicio.');
            }

            $duplicada = ReservaTenant::query()
                ->where('sesion_id', $bloqueada->getKey())
                ->where('persona_id', $persona->getKey())
                ->whereIn('estado', [
                    EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                    EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
                ])
                ->exists();
            if ($duplicada) {
                throw new YaReservado('Ya existe una reserva para esta sesion.');
            }

            if ($bloqueada->capacidad !== null && $this->disponiblesParaCanal($bloqueada, $canal) <= 0) {
                throw new CupoLleno('La sesion esta llena.');
            }

            // Orden por la sesión (pendiente de cobro) + la reserva que retiene el cupo.
            $orden = $this->ordenes->crearPorSesion($persona, $bloqueada, $montoMinor, $moneda, $sucursalId);

            $politica = $this->politicas->paraSesion($bloqueada);

            $reserva = ReservaTenant::query()->create([
                'sesion_id' => $bloqueada->getKey(),
                'persona_id' => $persona->getKey(),
                'orden_id' => $orden->getKey(),
                'estado' => $estado->value,
                'canal' => $canal,
                'unidades' => 0,
                'costo_unidades' => 0,
                'horas_limite' => $politica->horasLimite,
                'penaliza_tarde' => $politica->penalizaTarde,
                'penaliza_no_show' => $politica->penalizaNoShow,
            ]);

            $this->emitirCreada($reserva, $bloqueada, $persona);

            return $reserva;
        });
    }

    public function crear(SesionTenant $sesion, PersonaTenant $persona, ?string $idempotencyKey = null, bool $permitirEspera = false, ?int $unidades = null, string $canal = 'directo', ?int $lugar = null): ReservaTenant
    {
        $costo = $unidades ?? self::UNIDADES_POR_SESION;

        // Reintento idempotente: la misma clave devuelve la reserva ya creada.
        if ($idempotencyKey !== null) {
            $previa = ReservaTenant::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($previa !== null) {
                return $previa;
            }
        }

        // Pre-check con el motor: decision estructurada + fail-fast. Traduce el codigo
        // estable a la excepcion de dominio. La transaccion re-valida lo critico bajo
        // lock (concurrencia). Ver evaluar() y docs/BOOKING_ENGINE.md.
        $decision = $this->evaluar($sesion, $persona, $costo, $permitirEspera, $canal, $lugar);
        if (! $decision->permitida) {
            throw $this->excepcionDe((string) $decision->codigo, (string) $decision->mensaje);
        }

        try {
            return DB::connection('tenant')->transaction(function () use ($sesion, $persona, $idempotencyKey, $permitirEspera, $costo, $canal, $lugar): ReservaTenant {
                $bloqueada = SesionTenant::query()->whereKey($sesion->getKey())->lockForUpdate()->firstOrFail();

                if ($bloqueada->estado !== EstadoSesionTenant::Programada) {
                    throw new SesionNoReservable('La sesion no admite reservas.');
                }

                $activa = ReservaTenant::query()
                    ->where('sesion_id', $bloqueada->getKey())
                    ->where('persona_id', $persona->getKey())
                    ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value, EstadoReserva::EnEspera->value])
                    ->exists();

                if ($activa) {
                    throw new YaReservado('Ya existe una reserva para esta sesion.');
                }

                $derecho = $this->resolver->paraSesion($persona, $bloqueada, $costo);

                if ($derecho === null) {
                    throw new SinDerechoDisponible('No hay un derecho con saldo para esta sesion.');
                }

                // Congela (snapshot) la politica de cancelacion/no-show vigente: cancelar
                // o marcar asistencia leeran ESTOS valores, no la config viva (R8).
                $politica = $this->politicas->paraSesion($bloqueada);
                $snapshot = [
                    'horas_limite' => $politica->horasLimite,
                    'penaliza_tarde' => $politica->penalizaTarde,
                    'penaliza_no_show' => $politica->penalizaNoShow,
                ];

                // Si hay cupo definido y esta lleno PARA ESTE CANAL: lista de espera
                // (sin hold) o rechazo. La disponibilidad descuenta los cupos que otras
                // reglas de canal (R20) aun tienen reservados y sin usar.
                if ($bloqueada->capacidad !== null && $this->disponiblesParaCanal($bloqueada, $canal) <= 0) {
                    if (! $permitirEspera) {
                        throw new CupoLleno('La sesion esta llena.');
                    }

                    $enEspera = ReservaTenant::query()->create([
                        'sesion_id' => $bloqueada->getKey(),
                        'persona_id' => $persona->getKey(),
                        'derecho_id' => $derecho->getKey(),
                        'retencion_id' => null,
                        'estado' => EstadoReserva::EnEspera->value,
                        'canal' => $canal,
                        'lugar' => null, // el lugar se asigna al confirmar, no en lista de espera
                        'unidades' => 0,
                        'costo_unidades' => $costo,
                        'idempotency_key' => $idempotencyKey,
                        ...$snapshot,
                    ]);

                    $this->emitirCreada($enEspera, $bloqueada, $persona);

                    return $enEspera;
                }

                // Mapa de lugares (R4): valida el lugar elegido bajo el lock de la sesion
                // (evita choque concurrente por el mismo lugar). Solo se guarda si la
                // oferta define lugares.
                if (! $this->lugarDisponible($bloqueada, $lugar)) {
                    throw new LugarNoDisponible('El lugar elegido no esta disponible.');
                }
                $lugarFinal = $this->lugaresDe($bloqueada) > 0 ? $lugar : null;

                $retencion = null;
                $unidadesReservadas = 0;

                if (! $derecho->ilimitado) {
                    $retencion = $this->creditos->retener($derecho, $costo, 'Reserva de sesion');
                    $unidadesReservadas = $costo;
                }

                $reserva = ReservaTenant::query()->create([
                    'sesion_id' => $bloqueada->getKey(),
                    'persona_id' => $persona->getKey(),
                    'derecho_id' => $derecho->getKey(),
                    'retencion_id' => $retencion?->getKey(),
                    'estado' => EstadoReserva::Confirmada->value,
                    'canal' => $canal,
                    'lugar' => $lugarFinal,
                    'unidades' => $unidadesReservadas,
                    'costo_unidades' => $costo,
                    'idempotency_key' => $idempotencyKey,
                    ...$snapshot,
                ]);

                $this->emitirCreada($reserva, $bloqueada, $persona);

                return $reserva;
            });
        } catch (QueryException $e) {
            // Carrera de idempotencia: otra peticion concurrente ya creo la reserva
            // con la misma clave (viola el unique). Se devuelve la existente, no un 500.
            if ($idempotencyKey !== null) {
                $previa = ReservaTenant::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($previa !== null) {
                    return $previa;
                }
            }

            throw $e;
        }
    }

    /**
     * Evalua (SIN crear ni bloquear) si una persona puede reservar una sesion y
     * devuelve una DECISION estructurada y explicable (para el endpoint de preview y
     * la salida del motor). `crear()` la reutiliza como pre-check y luego re-valida lo
     * critico bajo lock. Ver docs/BOOKING_ENGINE.md.
     *
     * @param  int|null  $unidades  costo en unidades escaladas (por defecto 1 credito)
     */
    public function evaluar(SesionTenant $sesion, PersonaTenant $persona, ?int $unidades = null, bool $permitirEspera = false, string $canal = 'directo', ?int $lugar = null): DecisionReserva
    {
        $costo = $unidades ?? self::UNIDADES_POR_SESION;
        $reglas = [];

        $reglas['sesion_programada'] = $programada = $sesion->estado === EstadoSesionTenant::Programada;
        if (! $programada) {
            return DecisionReserva::rechazar('SESSION_NOT_BOOKABLE', 'La sesion no admite reservas.', $reglas);
        }

        $reglas['dentro_de_ventana'] = $enVentana = ! $sesion->inicia_en->isPast();
        if (! $enVentana) {
            return DecisionReserva::rechazar('BOOKING_NOT_OPEN', 'La sesion ya inicio.', $reglas);
        }

        $duplicada = ReservaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value, EstadoReserva::EnEspera->value])
            ->exists();
        $reglas['sin_reserva_previa'] = ! $duplicada;
        if ($duplicada) {
            return DecisionReserva::rechazar('ALREADY_BOOKED', 'Ya existe una reserva para esta sesion.', $reglas);
        }

        $derecho = $this->resolver->paraSesion($persona, $sesion, $costo);
        $reglas['derecho_disponible'] = $derecho !== null;
        if ($derecho === null) {
            return DecisionReserva::rechazar('ENTITLEMENT_REQUIRED', 'No hay un derecho con saldo para esta sesion.', $reglas);
        }

        // Mapa de lugares (R4): si se eligió lugar, debe estar en rango y libre.
        $reglas['lugar_disponible'] = $lugarOk = $this->lugarDisponible($sesion, $lugar);
        if (! $lugarOk) {
            return DecisionReserva::rechazar('SPOT_UNAVAILABLE', 'El lugar elegido no esta disponible.', $reglas);
        }

        $llena = $sesion->capacidad !== null && $this->disponiblesParaCanal($sesion, $canal) <= 0;
        $reglas['con_cupo'] = ! $llena;
        $advertencias = [];
        if ($llena) {
            if (! $permitirEspera) {
                return DecisionReserva::rechazar('CAPACITY_FULL', 'La sesion esta llena.', $reglas);
            }
            $advertencias[] = 'WAITLIST';
        }

        // El hold solo se toma para un derecho limitado y con cupo (la lista de espera
        // no retiene credito hasta que se promueve).
        $costoCreditos = ($derecho->ilimitado || $llena) ? 0 : $costo;

        return DecisionReserva::permitir($reglas, $costoCreditos, $derecho->ulid, $advertencias);
    }

    /**
     * Traduce el codigo estable de una decision rechazada a su excepcion de dominio
     * (que `ApiExceptionRenderer` mapea al contrato de error de la API).
     */
    private function excepcionDe(string $codigo, string $mensaje): ReservaException
    {
        return match ($codigo) {
            'BOOKING_NOT_OPEN' => new FueraDeVentana($mensaje),
            'ALREADY_BOOKED' => new YaReservado($mensaje),
            'ENTITLEMENT_REQUIRED' => new SinDerechoDisponible($mensaje),
            'CAPACITY_FULL' => new CupoLleno($mensaje),
            'SPOT_UNAVAILABLE' => new LugarNoDisponible($mensaje),
            default => new SesionNoReservable($mensaje),
        };
    }

    /**
     * Cancela una reserva aplicando la politica por hold: a tiempo (a mas de
     * `horasLimite` del inicio) libera la retencion y el credito vuelve; tarde, la
     * confirma (penaliza). Al liberar un cupo confirmado, promueve de la lista de
     * espera. Idempotente: cancelar una reserva ya cancelada no hace nada.
     */
    /**
     * Transfiere (regala) el lugar de una reserva activa a otra persona (R9): el
     * crédito ya consumido por el titular original NO se mueve (es un regalo); solo
     * cambia el participante. No permite transferir tras registrar asistencia ni si el
     * destino ya tiene lugar en la clase.
     */
    public function transferir(ReservaTenant $reserva, PersonaTenant $destino): ReservaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($reserva, $destino): ReservaTenant {
            $bloqueada = ReservaTenant::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($bloqueada->estado, [EstadoReserva::Confirmada, EstadoReserva::Ofrecida], true)) {
                throw new TransferenciaInvalida('Solo se puede transferir una reserva activa.');
            }
            if ((int) $bloqueada->persona_id === (int) $destino->getKey()) {
                throw new TransferenciaInvalida('La reserva ya es de esa persona.');
            }
            if ($bloqueada->asistencia !== null) {
                throw new TransferenciaInvalida('No se puede transferir despues de registrar asistencia.');
            }

            $duplicada = ReservaTenant::query()
                ->where('sesion_id', $bloqueada->sesion_id)
                ->where('persona_id', $destino->getKey())
                ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value, EstadoReserva::EnEspera->value])
                ->exists();
            if ($duplicada) {
                throw new TransferenciaInvalida('Esa persona ya tiene lugar en esta clase.');
            }

            $bloqueada->update(['persona_id' => $destino->getKey()]);
            $bloqueada->refresh();
            if ($bloqueada->estado === EstadoReserva::Confirmada) {
                $this->confirmada->emitir($bloqueada);
            }

            return $bloqueada;
        });
    }

    public function cancelar(ReservaTenant $reserva, int $horasLimite = 6): ReservaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($reserva, $horasLimite): ReservaTenant {
            $bloqueada = ReservaTenant::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === EstadoReserva::Cancelada) {
                return $bloqueada;
            }

            // Cancelar un lugar en lista de espera: no hay hold ni promocion.
            if ($bloqueada->estado === EstadoReserva::EnEspera) {
                $bloqueada->update(['estado' => EstadoReserva::Cancelada->value]);

                return $bloqueada;
            }

            // Declinar una oferta (R7): libera el hold sin penalizar y re-ofrece el cupo.
            if ($bloqueada->estado === EstadoReserva::Ofrecida) {
                $sesion = SesionTenant::query()->whereKey($bloqueada->sesion_id)->lockForUpdate()->firstOrFail();
                if ($bloqueada->retencion !== null) {
                    $this->creditos->liberar($bloqueada->retencion);
                }
                $bloqueada->update([
                    'estado' => EstadoReserva::Cancelada->value,
                    'retencion_id' => null,
                    'unidades' => 0,
                    'oferta_expira_en' => null,
                ]);
                $this->promover($sesion);

                return $bloqueada;
            }

            // Bloquea la sesion para promover de forma segura tras liberar el cupo.
            $sesion = SesionTenant::query()->whereKey($bloqueada->sesion_id)->lockForUpdate()->firstOrFail();

            $estadoAnterior = $bloqueada->estado;
            $credito = '';
            $retencion = $bloqueada->retencion;
            if ($retencion !== null) {
                // Politica congelada en la reserva (R8); `$horasLimite` es solo el
                // respaldo para reservas anteriores al snapshot.
                $horas = $bloqueada->horas_limite ?? $horasLimite;
                $penalizaTarde = $bloqueada->penaliza_tarde ?? true;
                $momentoLimite = $sesion->inicia_en->copy()->subHours($horas);
                $aTiempo = now()->lessThanOrEqualTo($momentoLimite);

                if ($aTiempo || ! $penalizaTarde) {
                    // A tiempo, o el estudio no penaliza la cancelacion tardia: el
                    // credito retenido vuelve al miembro.
                    $this->creditos->liberar($retencion);
                    $credito = EmitirReservaCanceladaTenant::CREDITO_DEVUELTO;
                } else {
                    // Cancelación tardía con penalización: el crédito se cobra. Se deja
                    // trazable con el origen (reserva) y la reserva referida.
                    $this->creditos->confirmar($retencion, ContextoMovimiento::para(
                        OrigenMovimiento::Reserva,
                        'reserva',
                        $bloqueada->ulid,
                        null,
                        ['motivo' => 'cancelacion_tardia'],
                    ));
                    $credito = EmitirReservaCanceladaTenant::creditoCobrado($horas);
                }
            }

            $bloqueada->update(['estado' => EstadoReserva::Cancelada->value]);
            // Aviso al alumno (no al salir de la lista de espera ni de una reserva ya vencida).
            if (in_array($estadoAnterior, [EstadoReserva::Confirmada, EstadoReserva::PendientePago], true)) {
                $this->cancelada->reservaCancelada($bloqueada, $credito);
            }
            // Si su orden (cita de pago) seguía sin cobrar, ya no se entregará: se cancela.
            $this->cancelarOrdenPendiente($bloqueada);

            if (! $this->liberarCita($sesion)) {
                $this->promover($sesion);
            }

            return $bloqueada;
        });
    }

    /**
     * OFRECE el cupo liberado al siguiente de la lista de espera (FIFO), en vez de
     * confirmarlo directamente (waitlist robusta, R7): toma el hold (reserva el credito
     * durante la oferta), pasa la reserva a `ofrecida` con ventana de aceptacion y
     * notifica (evento `reserva.ofrecida`). Si acepta a tiempo -> `aceptar()`; si no,
     * el relay la expira y re-ofrece. Debe llamarse DENTRO de una transaccion con la
     * sesion ya bloqueada.
     */
    public function promover(SesionTenant $sesion): ?ReservaTenant
    {
        if ($sesion->capacidad === null) {
            return null;
        }

        if ($this->ocupadas($sesion) >= $sesion->capacidad) {
            return null;
        }

        $siguiente = ReservaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->where('estado', EstadoReserva::EnEspera->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($siguiente === null) {
            return null;
        }

        $derecho = $siguiente->derecho;
        $retencion = null;
        $unidadesReservadas = 0;
        // Reserva el costo REAL con el que se creo la reserva (no un valor fijo).
        $costo = $siguiente->costo_unidades ?? self::UNIDADES_POR_SESION;

        if ($derecho !== null && ! $derecho->ilimitado) {
            try {
                $retencion = $this->creditos->retener($derecho, $costo, 'Oferta de lista de espera');
                $unidadesReservadas = $costo;
            } catch (SaldoInsuficiente) {
                // Sin credito al ofrecer: se queda en espera para intentar luego.
                return null;
            }
        }

        $siguiente->update([
            'estado' => EstadoReserva::Ofrecida->value,
            'retencion_id' => $retencion?->getKey(),
            'unidades' => $unidadesReservadas,
            'oferta_expira_en' => now()->addMinutes(self::VENTANA_OFERTA_MIN),
        ]);

        // Notificacion (outbox): "tienes un lugar, acepta antes de que expire".
        $this->eventos->registrar('reserva.ofrecida', 'reserva', $siguiente->ulid, [
            ...DatosDeSesion::para($sesion),
            'persona_id' => $siguiente->persona?->ulid,
            'expira_en' => $siguiente->oferta_expira_en?->toIso8601String(),
        ]);

        return $siguiente;
    }

    /**
     * Smart-fill (R32): OFRECE de golpe todos los cupos libres de una sesion al inicio
     * de la lista de espera (FIFO), hasta llenarla o agotar a los que esperan. Abre la
     * transaccion y bloquea la sesion (contrato de {@see promover()}); devuelve cuantas
     * ofertas se emitieron. Idempotente en el sentido de que no re-ofrece cupos ya
     * ofrecidos ni sobrepasa la capacidad (invariante de no-sobreventa).
     */
    public function promoverCupos(SesionTenant $sesion): int
    {
        return DB::connection('tenant')->transaction(function () use ($sesion): int {
            $bloqueada = SesionTenant::query()->whereKey($sesion->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoSesionTenant::Programada || $bloqueada->capacidad === null) {
                return 0;
            }

            $ofrecidas = 0;
            // Cada promover() convierte una en_espera -> ofrecida (sube `ocupadas`), asi
            // que el bucle termina al llenar el cupo o cuando ya no hay a quien ofrecer.
            while ($this->promover($bloqueada) !== null) {
                $ofrecidas++;
            }

            return $ofrecidas;
        });
    }

    /**
     * El ofrecido ACEPTA su lugar (R7): la reserva `ofrecida` (con hold ya tomado) pasa
     * a `confirmada`. Idempotente si ya estaba confirmada. Rechaza si no esta ofrecida
     * o si la ventana expiro (OFFER_NOT_AVAILABLE).
     */
    public function aceptar(ReservaTenant $reserva): ReservaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($reserva): ReservaTenant {
            $bloqueada = ReservaTenant::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === EstadoReserva::Confirmada) {
                return $bloqueada;
            }

            if ($bloqueada->estado !== EstadoReserva::Ofrecida
                || ($bloqueada->oferta_expira_en !== null && now()->greaterThan($bloqueada->oferta_expira_en))) {
                throw new OfertaNoDisponible('La oferta no esta disponible o ya expiro.');
            }

            $bloqueada->update([
                'estado' => EstadoReserva::Confirmada->value,
                'oferta_expira_en' => null,
            ]);
            $this->confirmada->emitir($bloqueada);

            return $bloqueada;
        });
    }

    /**
     * Expira las ofertas vencidas de la BD del tenant (R7): libera su hold, las marca
     * `expirada` y RE-OFRECE el cupo al siguiente. Idempotente (revalida bajo lock).
     * Debe correr con la conexion del tenant activa (ver el comando que lo orquesta).
     */
    public function expirarOfertasVencidas(): int
    {
        $expiradas = 0;

        ReservaTenant::query()
            ->where('estado', EstadoReserva::Ofrecida->value)
            ->whereNotNull('oferta_expira_en')
            ->where('oferta_expira_en', '<', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) use (&$expiradas): void {
                DB::connection('tenant')->transaction(function () use ($id, &$expiradas): void {
                    $oferta = ReservaTenant::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $oferta instanceof ReservaTenant || $oferta->estado !== EstadoReserva::Ofrecida) {
                        return;
                    }
                    if ($oferta->oferta_expira_en === null || now()->lessThanOrEqualTo($oferta->oferta_expira_en)) {
                        return;
                    }

                    $sesion = SesionTenant::query()->whereKey($oferta->sesion_id)->lockForUpdate()->firstOrFail();

                    if ($oferta->retencion !== null) {
                        $this->creditos->liberar($oferta->retencion);
                    }

                    $oferta->update([
                        'estado' => EstadoReserva::Expirada->value,
                        'retencion_id' => null,
                        'unidades' => 0,
                        'oferta_expira_en' => null,
                    ]);
                    $expiradas++;

                    // El cupo liberado se re-ofrece al siguiente de la lista.
                    $this->promover($sesion);
                });
            });

        return $expiradas;
    }

    /**
     * Expira las reservas PENDIENTES DE PAGO (citas) que no se pagaron dentro de la
     * ventana: las cancela (libera el cupo retenido), cancela su orden pendiente y
     * promueve la lista de espera. Idempotente (revalida bajo lock). Debe correr con la
     * conexión del tenant activa (ver el comando que lo orquesta).
     */
    public function expirarReservasPendientes(): int
    {
        $limite = now()->subMinutes(self::VENTANA_PAGO_MIN);
        $expiradas = 0;
        $ordenes = [];

        ReservaTenant::query()
            ->where('estado', EstadoReserva::PendientePago->value)
            ->where('created_at', '<', $limite)
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) use (&$expiradas, &$ordenes): void {
                DB::connection('tenant')->transaction(function () use ($id, &$expiradas, &$ordenes): void {
                    $reserva = ReservaTenant::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $reserva instanceof ReservaTenant || $reserva->estado !== EstadoReserva::PendientePago) {
                        return;
                    }

                    // Libera el cupo bajo el lock de la sesión (para promover con seguridad).
                    $sesion = SesionTenant::query()->whereKey($reserva->sesion_id)->lockForUpdate()->firstOrFail();

                    // Venció sin pago: si el pago llega después, solo esta se puede reconfirmar.
                    $reserva->update(['estado' => EstadoReserva::Cancelada->value, 'motivo_cancelacion' => 'vencio_pago']);
                    $this->cancelarOrdenPendiente($reserva);
                    $expiradas++;
                    if ($reserva->orden_id !== null) {
                        $ordenes[] = (int) $reserva->orden_id;
                    }

                    // Una cita sin pagar libera el horario del profesional; en una clase,
                    // el cupo liberado se ofrece al siguiente en lista de espera.
                    if (! $this->liberarCita($sesion)) {
                        $this->promover($sesion);
                    }
                });
            });

        // Fuera de la transacción: el cobro que quedó abierto en la pasarela se cierra
        // para que no pueda pagarse tarde (si ya se cobró, se atiende como pago tardío).
        foreach ($ordenes as $orden) {
            $this->intentos->deOrden($orden);
        }

        return $expiradas;
    }

    /**
     * Llegó el pago de una reserva cuyo apartado venció sin pago (1.2): se reconfirma
     * solo si su horario sigue libre y aún no empieza, revalidando bajo candado (nunca
     * desplaza a otro cliente). En una cita, que el profesional no tenga otra cosa a
     * esa hora; en una clase, que haya lugar. La deja pendiente de pago para que el
     * fulfillment la confirme. Debe llamarse dentro de una transacción.
     */
    public function reconfirmarPagoTardio(ReservaTenant $reserva): bool
    {
        $bloqueada = ReservaTenant::query()->whereKey($reserva->getKey())->lockForUpdate()->first();
        if (! $bloqueada instanceof ReservaTenant
            || $bloqueada->estado !== EstadoReserva::Cancelada
            || $bloqueada->motivo_cancelacion !== 'vencio_pago') {
            return false;
        }

        $sesion = SesionTenant::query()->whereKey($bloqueada->sesion_id)->lockForUpdate()->first();
        if (! $sesion instanceof SesionTenant || ! $sesion->inicia_en->isFuture()) {
            return false;
        }

        if ($sesion->esCita()) {
            // Mismo punto de serialización por profesional que al agendar una cita.
            if ($sesion->instructor_id !== null) {
                DB::connection('tenant')->table('users')->where('id', $sesion->instructor_id)->update(['id' => DB::raw('id')]);
            }
            if ($sesion->estado !== EstadoSesionTenant::Programada
                && $this->agenda->conflictos($sesion->instructor_id, null, $sesion->inicia_en, $sesion->termina_en, (int) $sesion->getKey()) !== []) {
                return false;
            }
            $sesion->update(['estado' => EstadoSesionTenant::Programada->value]);
        } else {
            $canal = (string) ($bloqueada->canal ?? 'directo');
            if ($sesion->estado !== EstadoSesionTenant::Programada || $this->disponiblesParaCanal($sesion, $canal) < 1) {
                return false;
            }
        }

        $bloqueada->update(['estado' => EstadoReserva::PendientePago->value, 'motivo_cancelacion' => null]);

        return true;
    }

    /**
     * Cancela una sesion (cancelacion del NEGOCIO): marca la sesion como cancelada y
     * cancela TODAS sus reservas activas (confirmadas + en espera) liberando sus holds
     * — el credito retenido vuelve al miembro, sin penalizarlo. Atomico (bloquea la
     * sesion) e idempotente. No promueve lista de espera: la sesion no ocurrira.
     */
    public function cancelarSesion(SesionTenant $sesion): void
    {
        DB::connection('tenant')->transaction(function () use ($sesion): void {
            $bloqueada = SesionTenant::query()->whereKey($sesion->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === EstadoSesionTenant::Cancelada) {
                return;
            }

            $reservas = ReservaTenant::query()
                ->where('sesion_id', $bloqueada->getKey())
                ->whereIn('estado', [
                    EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                    EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
                ])
                ->with('retencion')
                ->lockForUpdate()
                ->get();

            foreach ($reservas as $reserva) {
                $devuelto = false;
                if ($reserva->retencion !== null) {
                    $this->creditos->liberar($reserva->retencion);
                    $devuelto = true;
                }

                $reserva->update(['estado' => EstadoReserva::Cancelada->value]);
                $this->cancelarOrdenPendiente($reserva);
                // Cada persona afectada recibe el aviso, con el enlace para reservar otra.
                $this->cancelada->sesionCancelada($reserva, $devuelto);
            }

            $bloqueada->update(['estado' => EstadoSesionTenant::Cancelada->value]);
        });
    }

    /**
     * Una CITA es la sesión de una sola persona: cuando su reserva termina (cancelada o
     * sin pagar a tiempo) la sesión se cancela para liberar el horario del profesional
     * (si no, quedaría una "clase" de cupo 1 huérfana que bloquea la disponibilidad).
     * Debe llamarse con la sesión ya bloqueada. Devuelve si la liberó.
     */
    private function liberarCita(SesionTenant $sesion): bool
    {
        if (! $sesion->esCita() || $sesion->estado !== EstadoSesionTenant::Programada) {
            return false;
        }

        $activas = ReservaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->whereIn('estado', [
                EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
            ])
            ->exists();
        if ($activas) {
            return false;
        }

        $sesion->update(['estado' => EstadoSesionTenant::Cancelada->value]);

        return true;
    }

    /**
     * Cancela la orden pendiente de una reserva de pago-para-reservar, para que no se
     * pueda pagar algo que ya no se va a entregar. Una orden ya pagada no se toca.
     */
    private function cancelarOrdenPendiente(ReservaTenant $reserva): void
    {
        if ($reserva->orden_id === null) {
            return;
        }

        OrdenTenant::query()->whereKey($reserva->orden_id)
            ->where('estado', EstadoOrden::Pendiente->value)
            ->update(['estado' => EstadoOrden::Cancelada->value, 'cancelada_en' => now()]);
    }

    /**
     * Asienta en el outbox el evento `reserva.creada` (dentro de la transaccion de
     * creacion, para que evento y reserva sean atomicos). R39.
     */
    private function emitirCreada(ReservaTenant $reserva, SesionTenant $sesion, PersonaTenant $persona): void
    {
        $this->eventos->registrar('reserva.creada', 'reserva', $reserva->ulid, [
            'sesion_id' => $sesion->ulid,
            'persona_id' => $persona->ulid,
            'estado' => $reserva->estado->value,
            'costo_unidades' => $reserva->costo_unidades,
        ]);

        if ($reserva->estado === EstadoReserva::Confirmada) {
            $this->confirmada->emitir($reserva);
        }
    }

    /**
     * Cupos OCUPADOS de una sesion: confirmadas MAS ofrecidas (una oferta vigente
     * reserva el lugar mientras el ofrecido decide), para no ofrecer/confirmar de mas.
     */
    private function ocupadas(SesionTenant $sesion): int
    {
        return ReservaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            // Una reserva pendiente de pago (citas) RETIENE el cupo mientras se paga,
            // igual que una confirmada/ofrecida (no-sobreventa).
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value, EstadoReserva::PendientePago->value])
            ->count();
    }

    /**
     * Cupos disponibles de una sesion PARA UN CANAL (R20): los libres del pool general
     * menos los que otras reglas de canal aun tienen reservados y sin usar. El canal
     * solicitante nunca se descuenta a si mismo (su reserva de cupos es un piso
     * garantizado, no un tope). Sin cupo definido = ilimitado. El resultado nunca supera
     * `capacidad - ocupadas`, de modo que el invariante de no-sobreventa se mantiene.
     */
    private function disponiblesParaCanal(SesionTenant $sesion, string $canal): int
    {
        if ($sesion->capacidad === null) {
            return PHP_INT_MAX;
        }

        $libres = $sesion->capacidad - $this->ocupadas($sesion);
        if ($libres <= 0) {
            return 0;
        }

        return max(0, $libres - $this->cuposReservadosOtrosCanales($sesion, $canal));
    }

    /**
     * Suma de cupos que OTROS canales (distintos de `$canal`) tienen reservados por regla
     * activa y aun no han usado, siempre que la regla siga vigente (aun no llega su
     * ventana de liberacion `liberar_horas_antes` antes del inicio). Liberacion
     * progresiva (R20): pasada esa ventana, esos cupos vuelven al pool general.
     */
    private function cuposReservadosOtrosCanales(SesionTenant $sesion, string $canal): int
    {
        $reglas = ReglaCapacidadCanalTenant::query()
            ->where('oferta_id', $sesion->oferta_id)
            ->where('activa', true)
            ->where('canal', '!=', $canal)
            ->get();

        if ($reglas->isEmpty()) {
            return 0;
        }

        $ahora = Carbon::now();
        $total = 0;

        foreach ($reglas as $regla) {
            $liberaEn = $sesion->inicia_en->copy()->subHours($regla->liberar_horas_antes);
            if ($ahora->greaterThanOrEqualTo($liberaEn)) {
                continue; // ya se liberaron esos cupos al pool general
            }

            $usados = ReservaTenant::query()
                ->where('sesion_id', $sesion->getKey())
                ->where('canal', $regla->canal->value)
                ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value])
                ->count();

            $total += max(0, $regla->cupos - $usados);
        }

        return $total;
    }

    /**
     * Número de lugares numerados de la oferta de la sesión (0 = sin lugares). R4.
     */
    private function lugaresDe(SesionTenant $sesion): int
    {
        return (int) OfertaTenant::query()->whereKey($sesion->oferta_id)->value('lugares');
    }

    /**
     * ¿El lugar elegido está disponible? null = no eligió (permitido). Si la oferta no
     * define lugares, se ignora (permitido). Si define, debe estar en rango [1..N] y no
     * estar tomado por otra reserva activa de la sesión (R4).
     */
    private function lugarDisponible(SesionTenant $sesion, ?int $lugar): bool
    {
        if ($lugar === null) {
            return true;
        }

        $lugares = $this->lugaresDe($sesion);
        if ($lugares <= 0) {
            return true;
        }
        if ($lugar < 1 || $lugar > $lugares) {
            return false;
        }

        return ! ReservaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->where('lugar', $lugar)
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value, EstadoReserva::EnEspera->value])
            ->exists();
    }
}
