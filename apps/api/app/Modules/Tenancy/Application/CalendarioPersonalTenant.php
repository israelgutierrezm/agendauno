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

    public function usuarioDe(string $token): ?Usuario
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
                ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value])
                ->whereHas('sesion', fn ($q) => $q->where('estado', EstadoSesionTenant::Programada->value)->whereBetween('inicia_en', [$desde, $hasta]))
                ->with(['sesion.oferta', 'sesion.sucursal', 'sesion.instructor'])
                ->get()
                ->each(function (ReservaTenant $r) use (&$eventos, $estudio): void {
                    $sesion = $r->sesion;
                    if ($sesion === null) {
                        return;
                    }
                    $con = $sesion->instructor?->nombreCorto();
                    $eventos[] = $this->evento(
                        'reserva-'.$r->ulid,
                        (string) $sesion->oferta?->nombre ?: 'Reserva',
                        $sesion->inicia_en,
                        $sesion->termina_en,
                        (string) $sesion->sucursal?->nombre,
                        trim($estudio->nombre.($con ? ' · con '.$con : '')),
                        $r->estado === EstadoReserva::PendientePago ? 'TENTATIVE' : 'CONFIRMED',
                    );
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
                $eventos[] = $this->evento(
                    'sesion-'.$s->ulid,
                    (string) $s->oferta?->nombre ?: 'Clase',
                    $s->inicia_en,
                    $s->termina_en,
                    (string) $s->sucursal?->nombre,
                    (string) $estudio->nombre,
                    'CONFIRMED',
                );
            });

        $lineas = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//AgendaUno//Calendario//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->texto($estudio->nombre),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
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
