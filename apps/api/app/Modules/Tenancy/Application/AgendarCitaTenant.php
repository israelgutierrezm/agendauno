<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\LimiteCitasPorPagar;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Agenda una CITA desde un hueco de disponibilidad (F-08): materializa la sesión del
 * servicio con el proveedor a la hora elegida (cupo 1, individual) y encadena la
 * reserva según la política de la oferta — `pago` crea reserva pendiente + orden por la
 * sesión (a pagar para confirmar); `entitlement` consume la membresía. Todo atómico.
 *
 * El servidor no confía en lo que ofreció la pantalla: el negocio debe ser de citas
 * (ADR 0104), el profesional debe serlo, la duración es la del servicio y, cuando
 * agenda el cliente (página pública o su cuenta), la hora debe ser futura y caer en
 * la atención del profesional en esa sede, en un día abierto. El negocio (recepción)
 * puede agendar fuera de horario, pero nunca encimado.
 *
 * Concurrencia: lo PRIMERO de la transacción es una escritura sin cambios sobre la
 * fila del profesional. En MySQL eso toma su bloqueo exclusivo; en SQLite, el de
 * escritura de la base. Así dos solicitudes para el mismo profesional se atienden en
 * fila y la segunda, al continuar, ya ve la cita de la primera. El choque se busca
 * por intervalo (cualquier solapamiento, no solo la misma hora de inicio). Si aun así
 * la base reporta un choque de concurrencia, se responde que el horario ya no está
 * disponible.
 *
 * Con «cualquier profesional disponible» ({@see agendarConCualquiera}) se intenta con
 * cada candidato, uno tras otro y cada uno con su candado.
 *
 * Anticipo (ADR 0161): si el servicio lo pide y el negocio cobra en línea, quien agenda
 * por su cuenta paga el anticipo para confirmar (o todo, si lo elige con `$pagarTodo`) y
 * el resto queda por cobrar. Lo que agenda el negocio no lleva anticipo.
 *
 * Una sola vez por intento (ADR 0201): con `$clave` (la `idempotency_key` de la
 * pantalla), un reintento de la misma solicitud (se perdió la respuesta, doble toque)
 * devuelve la cita que ya se agendó en lugar de otra; con «cualquier profesional» el
 * reintento podía quedar con otra persona y el cliente con dos citas y dos órdenes. La
 * clave se guarda con la reserva en la misma transacción (es única): de dos reintentos
 * a la vez, el segundo encuentra la del primero. Una cita nueva sale con
 * `wasRecentlyCreated`; la de un reintento, no.
 */
class AgendarCitaTenant
{
    use DetectsConcurrencyErrors;

    public function __construct(
        private readonly VerificarAgendaTenant $agenda,
        private readonly ReservasTenant $reservas,
        private readonly CalcularDisponibilidadTenant $disponibilidad,
        private readonly ElegirRecursoTenant $recursos,
        private readonly ParametrosTenant $parametros,
        private readonly CobroDeCitasTenant $cobro,
        private readonly ModalidadNegocioTenant $modalidad,
    ) {}

    public function agendar(
        OfertaTenant $oferta,
        SucursalTenant $sucursal,
        PersonaTenant $persona,
        ?int $instructorId,
        CarbonImmutable $inicia,
        int $duracionMin,
        bool $porNegocio = false,
        bool $pagarTodo = false,
        ?string $clave = null,
    ): ReservaTenant {
        $this->exigirNegocioDeCitas();
        $previa = $this->yaAgendada($clave, $persona, $oferta, $sucursal, $inicia, $instructorId);
        if ($previa instanceof ReservaTenant) {
            return $previa;
        }

        // La duración la fija el servicio; la que manda la pantalla solo cuenta si el
        // servicio no tiene una.
        $duracion = $oferta->duracion_minutos !== null && $oferta->duracion_minutos > 0
            ? (int) $oferta->duracion_minutos
            : ($duracionMin > 0 ? $duracionMin : $this->parametros->entero('citas.duracion_defecto'));
        $termina = $inicia->addMinutes($duracion);

        if (! $porNegocio) {
            if (! $inicia->isFuture()) {
                throw new SesionNoReservable('Ese horario ya pasó.');
            }
            // La anticipación mínima y el horizonte que decide el negocio.
            app(VentanaDeReservaTenant::class)->exigirParaCita($inicia);
            if ($instructorId === null || ! $this->disponibilidad->cabeEnHorario($instructorId, $sucursal, $inicia, $termina)) {
                throw new SesionNoReservable('Ese horario está fuera de la atención del profesional.');
            }
        }

        try {
            return $this->agendarEnTransaccion($oferta, $sucursal, $persona, $instructorId, $inicia, $termina, $porNegocio, $pagarTodo, $clave);
        } catch (SesionNoReservable|QueryException $e) {
            // Otro intento con la misma clave llegó antes: tomó el horario (o la clave
            // única) y su cita es la respuesta.
            $previa = $this->yaAgendada($clave, $persona, $oferta, $sucursal, $inicia, $instructorId);
            if ($previa instanceof ReservaTenant) {
                return $previa;
            }
            if ($e instanceof QueryException && $this->causedByConcurrencyError($e)) {
                throw new SesionNoReservable('Ese horario ya no está disponible.');
            }

            throw $e;
        }
    }

    /**
     * El cliente no eligió profesional: se asigna, entre quienes atienden en la sede a
     * esa hora, al que tiene menos citas y clases ese día (reparte el trabajo) y, a
     * igualdad, por nombre. Cada intento es el agendado normal: si el hueco de uno ya
     * se ocupó (otro cliente llegó antes), se intenta con el siguiente. Un rechazo que
     * no depende del profesional (sin derechos, fuera de la ventana) se responde tal
     * cual. Solo para el cliente: el negocio siempre agenda con alguien.
     */
    public function agendarConCualquiera(
        OfertaTenant $oferta,
        SucursalTenant $sucursal,
        PersonaTenant $persona,
        CarbonImmutable $inicia,
        int $duracionMin,
        bool $pagarTodo = false,
        ?string $clave = null,
    ): ReservaTenant {
        $this->exigirNegocioDeCitas();
        // Antes de buscar a otro profesional: el reintento ya tiene a quien le tocó.
        $previa = $this->yaAgendada($clave, $persona, $oferta, $sucursal, $inicia, null);
        if ($previa instanceof ReservaTenant) {
            return $previa;
        }
        if (! $inicia->isFuture()) {
            throw new SesionNoReservable('Ese horario ya pasó.');
        }
        app(VentanaDeReservaTenant::class)->exigirParaCita($inicia);

        [$duracion] = $this->disponibilidad->duracionYMargenes($oferta, $duracionMin);
        $termina = $inicia->addMinutes($duracion);
        $dia = $inicia->setTimezone((string) ($sucursal->zona_horaria ?: app(FechasNegocioTenant::class)->zona()));

        $candidatos = $this->disponibilidad->profesionalesDeSede($sucursal, $dia->toDateString())
            ->filter(fn (Usuario $u): bool => $this->disponibilidad->cabeEnHorario((int) $u->getKey(), $sucursal, $inicia, $termina));

        // Carga del día de cada candidato; el orden estable conserva el de nombre.
        $carga = SesionTenant::query()
            ->whereIn('instructor_id', $candidatos->modelKeys())
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>=', $dia->startOfDay()->utc())
            ->where('inicia_en', '<', $dia->addDay()->startOfDay()->utc())
            ->selectRaw('instructor_id, count(*) as total')
            ->groupBy('instructor_id')
            ->pluck('total', 'instructor_id');

        $ultimo = null;
        foreach ($candidatos->sortBy(static fn (Usuario $u): int => (int) ($carga[$u->getKey()] ?? 0)) as $profesional) {
            try {
                return $this->agendar($oferta, $sucursal, $persona, (int) $profesional->getKey(), $inicia, $duracionMin, pagarTodo: $pagarTodo, clave: $clave);
            } catch (SesionNoReservable $e) {
                $ultimo = $e;
            }
        }

        throw $ultimo ?? new SesionNoReservable('No hay profesionales disponibles a esa hora.');
    }

    /**
     * La cita que ya se agendó con esta clave, o null si no hay (o no hay clave). Es un
     * reintento solo si es de la misma persona, servicio, sede, hora y, si se eligió,
     * profesional: otra cosa con la misma clave se rechaza (nunca se devuelve la cita
     * de otra persona ni una distinta de la que se pidió).
     */
    private function yaAgendada(?string $clave, PersonaTenant $persona, OfertaTenant $oferta, SucursalTenant $sucursal, CarbonImmutable $inicia, ?int $instructorId): ?ReservaTenant
    {
        if ($clave === null || $clave === '') {
            return null;
        }
        $previa = ReservaTenant::query()->where('idempotency_key', $clave)->with('sesion')->first();
        if (! $previa instanceof ReservaTenant) {
            return null;
        }
        $sesion = $previa->sesion;
        $misma = $sesion instanceof SesionTenant
            && (int) $previa->persona_id === (int) $persona->getKey()
            && (int) $sesion->oferta_id === (int) $oferta->getKey()
            && (int) $sesion->sucursal_id === (int) $sucursal->getKey()
            && $sesion->inicia_en->equalTo($inicia)
            && ($instructorId === null || (int) $sesion->instructor_id === $instructorId);
        if (! $misma) {
            throw new SesionNoReservable('Esta solicitud ya se usó para otra cita. Vuelve a elegir el horario.');
        }

        return $previa;
    }

    /**
     * Un negocio es solo de clases o solo de citas (ADR 0104): en uno de citas todo
     * servicio se agenda como cita; en uno de clases, ninguno.
     */
    private function exigirNegocioDeCitas(): void
    {
        if (! $this->modalidad->esCitas()) {
            throw new SesionNoReservable('Este negocio trabaja con clases.');
        }
    }

    /**
     * Cuenta sus citas próximas con la orden sin pagar (apartadas o confirmadas por
     * cobrar) bajo el candado de su ficha: dos solicitudes a la vez del mismo cliente
     * no rebasan el límite. Debe llamarse dentro de la transacción.
     */
    private function verificarCitasPorPagar(PersonaTenant $persona): void
    {
        $maximo = $this->parametros->entero('citas.maximo_por_pagar');
        if ($maximo <= 0) {
            return;
        }

        PersonaTenant::query()->whereKey($persona->getKey())->lockForUpdate()->first();
        $porPagar = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [EstadoReserva::PendientePago->value, EstadoReserva::Confirmada->value])
            ->whereHas('orden', fn ($q) => $q->where('estado', EstadoOrden::Pendiente->value))
            ->whereHas('sesion', fn ($q) => $q->where('tipo', TipoSesionTenant::Cita->value)->where('inicia_en', '>', now()))
            ->count();

        if ($porPagar >= $maximo) {
            throw new LimiteCitasPorPagar(($porPagar === 1 ? 'Ya tienes una cita por pagar.' : "Ya tienes {$porPagar} citas por pagar.")
                .' Paga o cancela alguna para agendar otra.');
        }
    }

    private function agendarEnTransaccion(
        OfertaTenant $oferta,
        SucursalTenant $sucursal,
        PersonaTenant $persona,
        ?int $instructorId,
        CarbonImmutable $inicia,
        CarbonImmutable $termina,
        bool $porNegocio,
        bool $pagarTodo,
        ?string $clave,
    ): ReservaTenant {
        return DB::connection('tenant')->transaction(function () use ($oferta, $sucursal, $persona, $instructorId, $inicia, $termina, $porNegocio, $pagarTodo, $clave): ReservaTenant {
            $reserva = $this->crearCita($oferta, $sucursal, $persona, $instructorId, $inicia, $termina, $porNegocio, $pagarTodo);
            // En la misma transacción: si otro intento ya guardó esta clave, el índice
            // único deshace esta cita completa (sesión, orden y retención).
            if ($clave !== null && $clave !== '') {
                $reserva->update(['idempotency_key' => $clave]);
            }

            return $reserva;
        }, 3);
    }

    /**
     * Crea la sesión de la cita y su reserva (dentro de la transacción de
     * {@see agendarEnTransaccion}).
     */
    private function crearCita(
        OfertaTenant $oferta,
        SucursalTenant $sucursal,
        PersonaTenant $persona,
        ?int $instructorId,
        CarbonImmutable $inicia,
        CarbonImmutable $termina,
        bool $porNegocio,
        bool $pagarTodo,
    ): ReservaTenant {
        // Punto de serialización por profesional (ver arriba). Debe ir antes de
        // leer su agenda para que la lectura vea lo que otra solicitud guardó.
        $this->agenda->bloquear($instructorId, null);
        $profesional = $instructorId !== null ? Usuario::query()->find($instructorId) : null;
        // Un profesional invitado que aún no activa su cuenta sí atiende; uno dado
        // de baja ya no se encuentra.
        if (! $profesional instanceof Usuario || ! $profesional->esProfesional()) {
            throw new SesionNoReservable('Esa persona no atiende citas.');
        }

        // El cliente no aparta sin fin citas que no paga (ADR 0065).
        if (! $porNegocio && $oferta->politica_reserva === PoliticaReservaTenant::Pago) {
            $this->verificarCitasPorPagar($persona);
        }

        // El hueco debe seguir libre (el proveedor no puede tener dos cosas a la vez),
        // con la preparación y la limpieza del servicio.
        if ($this->agenda->conflictos($instructorId, null, $inicia, $termina, sucursalId: (int) $sucursal->getKey(), margenes: MargenesServicio::de($oferta)) !== []) {
            throw new SesionNoReservable('Ese horario ya no está disponible.');
        }

        // Si el servicio requiere cabina o equipo (2.4), toma uno libre de la sede
        // bajo su candado: dos profesionales no se quedan con la misma cabina.
        $recurso = null;
        if ($this->recursos->requiere($oferta)) {
            $recurso = $this->recursos->libre($oferta, (int) $sucursal->getKey(), $inicia, $termina, MargenesServicio::de($oferta), bloquear: true);
            if (! $recurso instanceof RecursoTenant) {
                throw new SesionNoReservable('No hay un espacio libre para ese servicio en ese horario.');
            }
        }

        $sesion = SesionTenant::query()->create([
            'oferta_id' => $oferta->getKey(),
            'sucursal_id' => $sucursal->getKey(),
            'instructor_id' => $instructorId,
            'recurso_id' => $recurso?->getKey(),
            'inicia_en' => $inicia,
            'termina_en' => $termina,
            'zona_horaria' => $sucursal->zona_horaria,
            'capacidad' => 1,
            'estado' => EstadoSesionTenant::Programada->value,
            'tipo' => $this->modalidad->tipoSesion()->value,
        ]);

        if ($oferta->politica_reserva === PoliticaReservaTenant::Pago) {
            $precio = (int) ($oferta->precio_clase_minor ?? 0);
            $moneda = app(ParametrosTenant::class)->monedaDe($sucursal);
            // Con anticipo, quien agenda por su cuenta lo paga en línea para confirmar
            // (o todo, si lo elige); sin cobro en línea no se puede pedir.
            $anticipo = ! $porNegocio && $this->cobro->pagoEnLinea() ? $oferta->anticipoMinor() : null;
            if ($anticipo !== null) {
                return $this->reservas->reservarConPago($sesion, $persona, $precio, $moneda, (int) $sucursal->getKey(), anticipoMinor: $anticipo, completo: $pagarTodo);
            }
            // El negocio agenda y cobra en caja (confirmada). El cliente paga en
            // línea para confirmar (pendiente de pago, expira si no paga) solo si el
            // negocio lo pide y puede cobrar en línea; si no, queda confirmada y
            // paga en línea o en la sucursal (ADR 0065).
            if ($porNegocio || ! $this->cobro->pagoObligatorio()) {
                return $this->reservas->reservarPorCobrar($sesion, $persona, $precio, $moneda, (int) $sucursal->getKey());
            }

            return $this->reservas->reservarConPago($sesion, $persona, $precio, $moneda, (int) $sucursal->getKey());
        }

        return $this->reservas->crear($sesion, $persona);
    }
}
