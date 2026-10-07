<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Calendario personal (iCal, RFC 5545) para suscribirse desde Google Calendar, Apple u
 * Outlook: el alumno ve sus clases y citas; quien imparte, las que da. Se lee con un
 * enlace privado (token); regenerarlo invalida el anterior.
 */
class CalendarioPersonalTenant
{
    /** Ventana del calendario: lo reciente y lo que viene. */
    private const DIAS_ATRAS = 30;

    private const DIAS_ADELANTE = 120;

    /** Reservas que ocupan su lugar (las que van a su calendario). */
    private const RESERVA_VIGENTE = [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value];

    public function __construct(private readonly PersonaDeUsuarioTenant $personas) {}

    /**
     * Enlace https del calendario del usuario (lo crea si no tiene; `nuevo` lo cambia).
     */
    public function enlace(Usuario $usuario, Estudio $estudio, bool $nuevo = false): string
    {
        $token = $usuario->calendario_token;
        if ($nuevo || ! is_string($token) || $token === '') {
            $token = Str::random(40);
            $usuario->forceFill([
                'calendario_token' => $token,
                'calendario_token_hash' => hash('sha256', $token),
            ])->save();
        }

        return rtrim((string) config('app.url'), '/').'/api/v1/app/'.$estudio->slug.'/calendario/'.$token.'.ics';
    }

    public function usuarioDe(#[\SensitiveParameter] string $token): ?Usuario
    {
        if ($token === '') {
            return null;
        }

        $usuario = Usuario::query()->where('calendario_token_hash', hash('sha256', $token))->first();

        return $usuario instanceof Usuario && $usuario->activo ? $usuario : null;
    }

    public function ics(Usuario $usuario, Estudio $estudio): string
    {
        $desde = CarbonImmutable::now()->subDays(self::DIAS_ATRAS);
        $hasta = CarbonImmutable::now()->addDays(self::DIAS_ADELANTE);
        $eventos = [];

        // Sus reservas (clases y citas).
        $persona = $this->personas->buscar($usuario);
        if ($persona !== null) {
            ReservaTenant::query()
                ->where('persona_id', $persona->getKey())
                ->whereIn('estado', self::RESERVA_VIGENTE)
                ->whereHas('sesion', fn ($q) => $q->where('estado', EstadoSesionTenant::Programada->value)->whereBetween('inicia_en', [$desde, $hasta]))
                ->with(['sesion.oferta', 'sesion.sucursal', 'sesion.instructor'])
                ->get()
                ->each(function (ReservaTenant $r) use (&$eventos, $estudio): void {
                    $evento = $this->eventoDeReserva($r, $estudio);
                    if ($evento !== null) {
                        $eventos[] = $evento;
                    }
                });
        }

        // Lo que imparte (clases y citas asignadas).
        SesionTenant::query()
            ->where('instructor_id', $usuario->getKey())
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->whereBetween('inicia_en', [$desde, $hasta])
            ->with(['oferta', 'sucursal'])
            ->get()
            ->each(function (SesionTenant $s) use (&$eventos, $estudio): void {
                $eventos[] = $this->eventoDeSesion($s, $estudio);
            });

        return $this->calendario($estudio, $eventos, suscripcion: true);
    }

    /**
     * Un solo evento ("Agregar a mi calendario" desde la app): `reserva-{ulid}` de una
     * reserva suya o `sesion-{ulid}` de algo que imparte. Null si no es suyo o ya no
     * sigue en pie. El UID es el mismo que en su calendario suscrito.
     */
    public function icsDeEvento(Usuario $usuario, Estudio $estudio, string $evento): ?string
    {
        [$tipo, $ulid] = array_pad(explode('-', $evento, 2), 2, '');
        $lineas = null;

        if ($tipo === 'reserva') {
            $persona = $this->personas->buscar($usuario);
            $reserva = $persona === null ? null : ReservaTenant::query()
                ->where('ulid', $ulid)
                ->where('persona_id', $persona->getKey())
                ->whereIn('estado', self::RESERVA_VIGENTE)
                ->whereHas('sesion', fn ($q) => $q->where('estado', EstadoSesionTenant::Programada->value))
                ->with(['sesion.oferta', 'sesion.sucursal', 'sesion.instructor'])
                ->first();
            $lineas = $reserva instanceof ReservaTenant ? $this->eventoDeReserva($reserva, $estudio) : null;
        } elseif ($tipo === 'sesion') {
            $sesion = SesionTenant::query()
                ->where('ulid', $ulid)
                ->where('instructor_id', $usuario->getKey())
                ->where('estado', EstadoSesionTenant::Programada->value)
                ->with(['oferta', 'sucursal'])
                ->first();
            $lineas = $sesion instanceof SesionTenant ? $this->eventoDeSesion($sesion, $estudio) : null;
        }

        return $lineas === null ? null : $this->calendario($estudio, [$lineas], suscripcion: false);
    }

    /**
     * @return list<string>|null
     */
    private function eventoDeReserva(ReservaTenant $r, Estudio $estudio): ?array
    {
        $sesion = $r->sesion;
        if ($sesion === null) {
            return null;
        }
        $con = $sesion->instructor?->nombreCorto();

        return $this->evento(
            'reserva-'.$r->ulid,
            (string) $sesion->oferta?->nombre ?: 'Reserva',
            $sesion->inicia_en,
            $sesion->termina_en,
            (string) $sesion->sucursal?->nombre,
            trim($estudio->nombre.($con ? ' · con '.$con : '')),
            $r->estado === EstadoReserva::PendientePago ? 'TENTATIVE' : 'CONFIRMED',
        );
    }

    /**
     * @return list<string>
     */
    private function eventoDeSesion(SesionTenant $s, Estudio $estudio): array
    {
        return $this->evento(
            'sesion-'.$s->ulid,
            (string) $s->oferta?->nombre ?: 'Clase',
            $s->inicia_en,
            $s->termina_en,
            (string) $s->sucursal?->nombre,
            (string) $estudio->nombre,
            'CONFIRMED',
        );
    }

    /**
     * El archivo: un calendario suscrito (se refresca cada hora) o un evento suelto.
     *
     * @param  list<list<string>>  $eventos
     */
    private function calendario(Estudio $estudio, array $eventos, bool $suscripcion): string
    {
        $lineas = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//AgendaUno//Calendario//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            ...($suscripcion ? [
                'X-WR-CALNAME:'.$this->texto($estudio->nombre),
                'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
                'X-PUBLISHED-TTL:PT1H',
            ] : []),
            ...array_merge(...($eventos ?: [[]])),
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map($this->doblar(...), $lineas))."\r\n";
    }

    /**
     * @return list<string>
     */
    private function evento(string $uid, string $titulo, CarbonInterface $inicia, ?CarbonInterface $termina, string $lugar, string $descripcion, string $estado): array
    {
        $formato = static fn (CarbonInterface $f): string => CarbonImmutable::instance($f)->utc()->format('Ymd\\THis\\Z');

        return array_values(array_filter([
            'BEGIN:VEVENT',
            'UID:'.$uid.'@agendauno',
            'DTSTAMP:'.$formato(CarbonImmutable::now()),
            'DTSTART:'.$formato($inicia),
            'DTEND:'.$formato($termina ?? CarbonImmutable::instance($inicia)->addHour()),
            'SUMMARY:'.$this->texto($titulo),
            $lugar !== '' ? 'LOCATION:'.$this->texto($lugar) : null,
            $descripcion !== '' ? 'DESCRIPTION:'.$this->texto($descripcion) : null,
            'STATUS:'.$estado,
            'END:VEVENT',
        ]));
    }

    /**
     * Escapa texto según RFC 5545 (\\, ; , y saltos de línea).
     */
    private function texto(string $valor): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $valor);
    }

    /**
     * Dobla líneas de más de 75 octetos (continuación con un espacio).
     */
    private function doblar(string $linea): string
    {
        if (strlen($linea) <= 75) {
            return $linea;
        }

        $partes = [];
        while (strlen($linea) > 75) {
            $corte = 75;
            // No partir un carácter UTF-8 a la mitad.
            while ($corte > 0 && (ord($linea[$corte]) & 0xC0) === 0x80) {
                $corte--;
            }
            $partes[] = substr($linea, 0, $corte);
            $linea = ' '.substr($linea, $corte);
        }
        $partes[] = $linea;

        return implode("\r\n", $partes);
    }
}
