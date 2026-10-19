<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\InscribirEnGrupoTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\GrupoTenant;
use App\Modules\Tenancy\Models\InscripcionGrupoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;

/**
 * Las fechas que la agenda genera DESPUÉS de inscribir (la ventana que avanza cada
 * día, días nuevos de la serie) también son de los inscritos: al publicarse
 * `agenda.sesiones_generadas`, reserva a cada inscrito de esa serie en las fechas que
 * caen en su vigencia (R03: desde su alta y hasta antes de su baja), con el mismo motor
 * y los mismos motivos que al inscribir. Una baja o un cierre con fecha futura no quita
 * las fechas anteriores a esa fecha.
 *
 * Idempotente (el relay es at-least-once): quien ya tuvo una reserva en esa fecha, aun
 * cancelada, no se vuelve a reservar. Lo que no se pudo reservar queda en la bitácora
 * del grupo con su motivo.
 */
class ReservarInscritosEnFechasNuevas
{
    public const TIPO = 'agenda.sesiones_generadas';

    public function __construct(
        private readonly InscribirEnGrupoTenant $inscribir,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        if ($evento->tipo !== self::TIPO) {
            return;
        }
        $ulids = array_values(array_filter((array) ($evento->payload['sesiones'] ?? []), 'is_string'));
        if ($ulids === []) {
            return;
        }
        $sesiones = SesionTenant::query()
            ->whereIn('ulid', $ulids)
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>', now())
            ->orderBy('inicia_en')
            ->get();
        if ($sesiones->isEmpty()) {
            return;
        }
        $serie = $sesiones->first()->serie_id;
        $fechas = app(FechasNegocioTenant::class);
        $primerDia = $fechas->fecha($sesiones->first()->inicia_en);

        // También un grupo cerrado con fecha futura: sus inscritos siguen hasta ese día.
        GrupoTenant::query()
            ->where('plantilla_id', $serie)
            ->orderBy('id')
            ->get()
            ->each(function (GrupoTenant $grupo) use ($sesiones, $fechas, $primerDia): void {
                $cuenta = [InscribirEnGrupoTenant::RESERVADA => 0, InscribirEnGrupoTenant::EN_ESPERA => 0, InscribirEnGrupoTenant::NO_RESERVADA => 0];
                $sinReservar = [];
                $grupo->inscripciones()->vigentesDesde($primerDia)->with('persona')->orderBy('id')->get()
                    ->each(function (InscripcionGrupoTenant $inscripcion) use ($sesiones, $fechas, &$cuenta, &$sinReservar): void {
                        $persona = $inscripcion->persona;
                        if ($persona === null) {
                            return;
                        }
                        foreach ($sesiones as $sesion) {
                            if (! $inscripcion->vigenteEn($sesion->inicia_en, $fechas)) {
                                continue;
                            }
                            $tuvo = ReservaTenant::query()
                                ->where('sesion_id', $sesion->getKey())
                                ->where('persona_id', $persona->getKey())
                                ->exists();
                            if ($tuvo) {
                                continue;
                            }
                            $fila = $this->inscribir->reservarFecha($sesion, $persona);
                            $cuenta[$fila['estado']]++;
                            if ($fila['estado'] === InscribirEnGrupoTenant::NO_RESERVADA) {
                                $sinReservar[] = ['persona' => $persona->nombreCompleto(), 'inicia_en' => $fila['inicia_en'], 'motivo' => $fila['motivo']];
                            }
                        }
                    });
                if (array_sum($cuenta) === 0) {
                    return;
                }
                $this->auditoria->registrar(null, 'grupo.fechas_nuevas', 'grupo', (string) $grupo->ulid, null, [
                    'grupo' => $grupo->nombre,
                    'fechas' => $sesiones->count(),
                    'reservadas' => $cuenta[InscribirEnGrupoTenant::RESERVADA],
                    'en_espera' => $cuenta[InscribirEnGrupoTenant::EN_ESPERA],
                    'no_reservadas' => $cuenta[InscribirEnGrupoTenant::NO_RESERVADA],
                    'sin_reservar' => array_slice($sinReservar, 0, 50),
                ]);
            });
    }
}
