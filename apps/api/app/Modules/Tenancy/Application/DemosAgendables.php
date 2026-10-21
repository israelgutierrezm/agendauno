<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\DemoAgendada;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * La demo de 15 minutos que se agenda desde la landing (ADR 0193).
 *
 * - El horario lo fija el superadmin: de qué hora a qué hora, de lunes a viernes (y
 *   sábados, si quiere), cuántos días hacia adelante, con cuánta anticipación y cuántas
 *   demos caben a la misma hora (cuántas personas atienden). Todo en la zona de ventas.
 * - Cada demo ocupa un lugar de su horario (`ranura`, único): dos personas no toman el
 *   mismo aunque agenden a la vez. Cancelarla lo libera.
 * - Apagadas (`demos.activas`), la landing no las ofrece y no se agenda.
 */
class DemosAgendables
{
    public function __construct(private readonly ParametrosTenant $parametros) {}

    public function activas(): bool
    {
        return $this->parametros->siNo('demos.activas');
    }

    public function minutos(): int
    {
        return max(5, $this->parametros->entero('demos.minutos'));
    }

    public function zona(): string
    {
        return (string) config('agendauno.ventas.zona', 'America/Mexico_City');
    }

    /**
     * Los horarios libres de los próximos días, en la zona de ventas.
     *
     * @return list<array{fecha: string, horas: list<string>}>
     */
    public function horarios(): array
    {
        if (! $this->activas()) {
            return [];
        }
        $zona = $this->zona();
        $ahora = CarbonImmutable::now($zona);
        $desde = $ahora->addHours($this->parametros->entero('demos.horas_anticipacion'));
        $minutos = $this->minutos();
        $inicio = $this->parametros->entero('demos.hora_inicio') * 60;
        $fin = $this->parametros->entero('demos.hora_fin') * 60;
        $porHorario = max(1, $this->parametros->entero('demos.por_horario'));
        $dias = max(1, $this->parametros->entero('demos.dias_adelante'));
        $sabados = $this->parametros->siNo('demos.sabados');

        $ocupadas = DemoAgendada::query()
            ->where('estado', 'agendada')
            ->whereBetween('inicia_en', [$ahora->utc(), $ahora->addDays($dias + 1)->utc()])
            ->pluck('inicia_en')
            ->countBy(fn (mixed $f): string => CarbonImmutable::parse($f)->utc()->format('Y-m-d H:i'));

        $libres = [];
        for ($d = 0; $d < $dias; $d++) {
            $dia = $ahora->startOfDay()->addDays($d);
            if ($dia->isSunday() || ($dia->isSaturday() && ! $sabados)) {
                continue;
            }
            $horas = [];
            for ($m = $inicio; $m + $minutos <= $fin; $m += $minutos) {
                $hora = $dia->addMinutes($m);
                if ($hora->lessThan($desde) || ($ocupadas[$hora->utc()->format('Y-m-d H:i')] ?? 0) >= $porHorario) {
                    continue;
                }
                $horas[] = $hora->format('H:i');
            }
            if ($horas !== []) {
                $libres[] = ['fecha' => $dia->toDateString(), 'horas' => $horas];
            }
        }

        return $libres;
    }

    /**
     * Agenda una demo en un horario libre. 422 si ya no lo está.
     *
     * @param  array{producto: string, fecha: string, hora: string, nombre: string, negocio: string, giro?: string|null, correo: string, telefono: string, acepta_whatsapp: bool, aviso_version?: int|null}  $datos
     */
    public function agendar(array $datos): DemoAgendada
    {
        $libre = collect($this->horarios())->firstWhere('fecha', $datos['fecha']);
        if (! is_array($libre) || ! in_array($datos['hora'], $libre['horas'], true)) {
            throw ValidationException::withMessages(['hora' => ['Ese horario ya no está disponible: elige otro.']]);
        }
        $inicio = CarbonImmutable::createFromFormat('Y-m-d H:i', $datos['fecha'].' '.$datos['hora'], $this->zona());
        if (! $inicio instanceof CarbonImmutable) {
            throw ValidationException::withMessages(['hora' => ['Ese horario no es válido.']]);
        }

        $porHorario = max(1, $this->parametros->entero('demos.por_horario'));
        for ($lugar = 1; $lugar <= $porHorario; $lugar++) {
            try {
                return DemoAgendada::query()->create([
                    'producto' => $datos['producto'],
                    'inicia_en' => $inicio->utc(),
                    'ranura' => $inicio->utc()->format('Y-m-d H:i').'#'.$lugar,
                    'nombre' => trim($datos['nombre']),
                    'negocio' => trim($datos['negocio']),
                    'giro' => $datos['giro'] ?? null,
                    'correo' => mb_strtolower(trim($datos['correo'])),
                    'telefono' => trim($datos['telefono']),
                    'acepta_whatsapp' => $datos['acepta_whatsapp'],
                    'estado' => 'agendada',
                    'token' => bin2hex(random_bytes(20)),
                    'aviso_version' => $datos['aviso_version'] ?? null,
                    'acepto_aviso_en' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Otro tomó ese lugar al mismo tiempo: el siguiente del horario.
                continue;
            }
        }

        throw ValidationException::withMessages(['hora' => ['Ese horario ya no está disponible: elige otro.']]);
    }

    /** La cancela y libera su lugar. */
    public function cancelar(DemoAgendada $demo): void
    {
        $demo->forceFill(['estado' => 'cancelada', 'ranura' => null])->save();
    }

    /** La fecha y la hora de la demo, en la zona de ventas, para los avisos. */
    public function cuando(DemoAgendada $demo): CarbonImmutable
    {
        return CarbonImmutable::parse($demo->inicia_en)->setTimezone($this->zona());
    }
}
