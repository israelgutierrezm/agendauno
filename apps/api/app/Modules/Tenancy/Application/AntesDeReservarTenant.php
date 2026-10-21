<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\FueraDeVentana;

/**
 * Lo que pasará si el alumno reserva esta clase, antes de confirmar (plan de mejoras
 * M05): si queda reservado, en lista de espera o no se puede (con el motivo principal y
 * la acción que le sirve), qué plan usa y cuánto consume, y hasta cuándo puede cancelar
 * sin costo, en la hora del negocio.
 *
 * Es una lectura con las mismas reglas que el motor ({@see ReservasTenant::evaluar()},
 * la ventana de reserva, la cobertura del plan y la política de cancelación que se
 * congelaría en la reserva). Reservar vuelve a validar todo bajo el candado de la sesión.
 */
class AntesDeReservarTenant
{
    /** Queda reservado. */
    public const RESERVA = 'reserva';

    /** La clase está llena: puede anotarse en la lista de espera (no consume hasta que le toque). */
    public const LISTA_ESPERA = 'lista_espera';

    /** No puede reservarla: `motivo` y `accion` dicen por qué y qué hacer. */
    public const NO_DISPONIBLE = 'no_disponible';

    public function __construct(
        private readonly ReservasTenant $reservas,
        private readonly ResolverDerechoTenant $resolver,
        private readonly ResolverPoliticaCancelacionTenant $politicas,
        private readonly VentanaDeReservaTenant $ventana,
        private readonly CupoDeSesionTenant $cupo,
        private readonly LibroMayorTenant $libro,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function deClase(SesionTenant $sesion, PersonaTenant $persona): array
    {
        $sesion->loadMissing(['oferta', 'sucursal']);
        $base = [
            'sesion_id' => $sesion->ulid,
            'oferta' => $sesion->oferta?->nombre,
            'inicia_en' => $sesion->inicia_en->toIso8601String(),
            'zona_horaria' => $sesion->zona_horaria,
            'sucursal' => $sesion->sucursal === null ? null : [
                'nombre' => $sesion->sucursal->nombre,
                'mapa_url' => $sesion->sucursal->enlaceMapa(),
            ],
            // Para quien llega por primera vez (ADR 0118).
            'requisitos' => $sesion->oferta?->requisitos,
            'que_llevar' => $sesion->oferta?->que_llevar,
            'cancelacion' => $this->cancelacion($sesion),
            // Pases de invitado que le quedan para esa clase (ADR 0182).
            'pases_invitado' => app(InvitadosTenant::class)->quedanPara($persona, $sesion),
        ];

        try {
            $this->ventana->exigirParaClase($sesion, $persona);
        } catch (FueraDeVentana $e) {
            return [...$base, ...$this->noDisponible('BOOKING_NOT_OPEN', 'fuera_de_ventana', $e->getMessage())];
        }
        // La clase pide un nivel que aún no tiene (ADR 0187).
        $nivel = app(NivelesPersonaTenant::class)->motivo($persona, $sesion);
        if ($nivel !== null) {
            return [...$base, ...$this->noDisponible('LEVEL_REQUIRED', 'nivel', $nivel)];
        }

        if ($sesion->oferta?->politica_reserva === PoliticaReservaTenant::Pago) {
            return [...$base, ...$this->dePago($sesion, $persona)];
        }

        $decision = $this->reservas->evaluar($sesion, $persona);
        if (! $decision->permitida && $decision->codigo === 'CAPACITY_FULL') {
            $conEspera = $this->reservas->evaluar($sesion, $persona, null, true);
            if ($conEspera->permitida) {
                // En la lista de espera no se aparta lugar: se elige al confirmar.
                return [...$base, 'resultado' => self::LISTA_ESPERA, 'codigo' => null, 'motivo' => null,
                    'accion' => null, 'mensaje' => null, 'unidades' => 0,
                    'plan' => $this->plan($conEspera->derecho, 0), 'de_pago' => null, 'lugares' => null];
            }
        }
        if (! $decision->permitida) {
            [$motivo, $accion] = $this->motivoYAccion((string) $decision->codigo, $sesion, $persona);

            return [...$base, ...$this->noDisponible((string) $decision->codigo, $motivo, (string) $decision->mensaje, $accion)];
        }

        return [...$base, 'resultado' => self::RESERVA, 'codigo' => null, 'motivo' => null, 'accion' => null,
            'mensaje' => null, 'unidades' => $decision->costoCreditos,
            'plan' => $this->plan($decision->derecho, $decision->costoCreditos), 'de_pago' => null,
            // Si la clase numera lugares, cuáles están libres para elegir el suyo (B06).
            'lugares' => $this->reservas->lugaresDeSesion($sesion)];
    }

    /**
     * Clase de pago por clase: se aparta el lugar y se paga para confirmarla.
     *
     * @return array<string, mixed>
     */
    private function dePago(SesionTenant $sesion, PersonaTenant $persona): array
    {
        if ($sesion->estado !== EstadoSesionTenant::Programada || $sesion->inicia_en->isPast()) {
            return $this->noDisponible('SESSION_NOT_BOOKABLE', 'no_reservable', 'Esta clase ya no admite reservas.');
        }
        $yaTiene = ReservaTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [
                EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
            ])
            ->exists();
        if ($yaTiene) {
            return $this->noDisponible('ALREADY_BOOKED', 'ya_reservada', 'Ya tienes esta clase.', 'ver_reserva');
        }
        $libres = $this->cupo->disponibles($sesion);
        if ($libres !== null && $libres <= 0) {
            return $this->noDisponible('CAPACITY_FULL', 'llena', 'La clase está llena.', 'otras_clases');
        }

        return ['resultado' => self::RESERVA, 'codigo' => null, 'motivo' => null, 'accion' => null,
            'mensaje' => null, 'unidades' => 0, 'plan' => null, 'lugares' => null,
            'de_pago' => [
                'precio_minor' => (int) ($sesion->oferta->precio_clase_minor ?? 0),
                'moneda' => $this->parametros->monedaDe($sesion->sucursal),
            ]];
    }

    /**
     * El motivo principal (para el texto) y la acción que le sirve: ver planes, revisar
     * su vigencia, cambiar de sucursal, ver su reserva u otras clases. Sin acción, el
     * camino es hablar con el negocio (pausa o suspensión).
     *
     * @return array{0: string, 1: string|null}
     */
    private function motivoYAccion(string $codigo, SesionTenant $sesion, PersonaTenant $persona): array
    {
        return match ($codigo) {
            'ALREADY_BOOKED' => ['ya_reservada', 'ver_reserva'],
            'CAPACITY_FULL' => ['llena', 'otras_clases'],
            // Llegó al tope de reservas de su plan (ADR 0182).
            'BOOKING_LIMIT_REACHED' => ['tope', 'otras_clases'],
            'ENTITLEMENT_REQUIRED' => $this->sinPlan($sesion, $persona),
            default => ['no_reservable', null],
        };
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function sinPlan(SesionTenant $sesion, PersonaTenant $persona): array
    {
        $motivo = $this->resolver->coberturaDeSesiones($persona, [$sesion], ReservasTenant::UNIDADES_POR_SESION)[(int) $sesion->getKey()]['motivo'] ?? 'sin_plan';

        return [$motivo, match ($motivo) {
            'sucursal' => 'cambiar_sucursal',
            'vigencia' => 'revisar_vigencia',
            'pausa', 'suspendido' => null,
            default => 'ver_planes',
        }];
    }

    /**
     * @return array<string, mixed>
     */
    private function noDisponible(string $codigo, string $motivo, string $mensaje, ?string $accion = null): array
    {
        return ['resultado' => self::NO_DISPONIBLE, 'codigo' => $codigo, 'motivo' => $motivo,
            'accion' => $accion, 'mensaje' => $mensaje, 'unidades' => 0, 'plan' => null, 'de_pago' => null,
            'lugares' => null];
    }

    /**
     * El plan que usaría: su nombre, hasta cuándo vale y lo que le quedaría.
     *
     * @return array{nombre: string|null, ilimitado: bool, vence: string|null, disponible_despues: int|null}|null
     */
    private function plan(?string $ulid, int $unidades): ?array
    {
        $derecho = $ulid === null ? null : DerechoTenant::query()->where('ulid', $ulid)->with('acuerdo.producto')->first();
        if (! $derecho instanceof DerechoTenant) {
            return null;
        }

        return [
            'nombre' => $derecho->acuerdo?->producto?->nombre,
            'ilimitado' => $derecho->ilimitado,
            'vence' => $derecho->valido_hasta?->toDateString(),
            'disponible_despues' => $derecho->ilimitado ? null : max(0, $this->libro->disponible($derecho) - $unidades),
        ];
    }

    /**
     * La política que se congelaría en la reserva (R8): hasta cuándo cancela sin costo
     * y qué pasa después.
     *
     * @return array{limite: string, horas_limite: int, penaliza_tarde: bool, cancela_tarde: bool}
     */
    private function cancelacion(SesionTenant $sesion): array
    {
        $politica = $this->politicas->paraSesion($sesion);

        return [
            'limite' => $sesion->inicia_en->copy()->subHours($politica->horasLimite)->toIso8601String(),
            'horas_limite' => $politica->horasLimite,
            'penaliza_tarde' => $politica->penalizaTarde,
            // Pasado el límite, ¿puede cancelar él mismo (con la penalización que aplique)?
            'cancela_tarde' => $this->parametros->siNo('cancelacion.cliente_cancela_tarde'),
        ];
    }
}
