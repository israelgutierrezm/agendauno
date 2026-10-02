<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use Illuminate\Support\Facades\DB;

/**
 * Alta de servicios o clases en una línea para la configuración inicial: «Corte de
 * cabello · 30 min · $250» o «Pole Nivel 1 · 60 min · 8 lugares». Arma por dentro la
 * estructura del catálogo (programa → actividad → oferta) con un solo grupo, sin que
 * el dueño tenga que entenderla; después puede reorganizarla en Catálogo.
 *
 * Un servicio se agenda como cita y se cobra por cita; una clase es grupal y se toma
 * con un plan (paquete o mensualidad). Si el negocio aún no tiene política de
 * cancelación, deja una razonable (cancelar sin costo hasta 6 horas antes), que se
 * cambia en Reglas de reserva.
 */
final class AltaRapidaCatalogoTenant
{
    /**
     * @param  list<array{nombre: string, duracion_minutos: int, precio_minor: int}>  $servicios
     * @return list<OfertaTenant>
     */
    public function servicios(array $servicios): array
    {
        return DB::connection('tenant')->transaction(function () use ($servicios): array {
            $actividad = $this->grupo('Servicios');
            $creadas = array_map(fn (array $s): OfertaTenant => $actividad->ofertas()->create([
                'nombre' => trim($s['nombre']),
                'modalidad' => ModalidadOfertaTenant::Individual->value,
                'capacidad' => 1,
                'politica_reserva' => PoliticaReservaTenant::Pago->value,
                'duracion_minutos' => $s['duracion_minutos'],
                'precio_clase_minor' => $s['precio_minor'],
            ]), $servicios);
            $this->politicaPorDefecto();

            return $creadas;
        });
    }

    /**
     * @param  list<array{nombre: string, duracion_minutos: int, capacidad: int}>  $clases
     * @return list<OfertaTenant>
     */
    public function clases(array $clases): array
    {
        return DB::connection('tenant')->transaction(function () use ($clases): array {
            $actividad = $this->grupo('Clases');
            $creadas = array_map(fn (array $c): OfertaTenant => $actividad->ofertas()->create([
                'nombre' => trim($c['nombre']),
                'modalidad' => ModalidadOfertaTenant::Grupal->value,
                'capacidad' => $c['capacidad'],
                'politica_reserva' => PoliticaReservaTenant::Entitlement->value,
                'duracion_minutos' => $c['duracion_minutos'],
            ]), $clases);
            $this->politicaPorDefecto();

            return $creadas;
        });
    }

    /** El grupo donde caen: un programa y una actividad con el mismo nombre. */
    private function grupo(string $nombre): ActividadTenant
    {
        $slug = str($nombre)->slug()->value();
        $programa = ProgramaTenant::query()->firstOrCreate(['slug' => $slug], ['nombre' => $nombre]);

        return $programa->actividades()->firstOrCreate(['slug' => $slug], ['nombre' => $nombre]);
    }

    private function politicaPorDefecto(): void
    {
        PoliticaCancelacionTenant::query()->firstOrCreate(['actividad_id' => null], [
            'horas_limite' => 6, 'penaliza_tarde' => true, 'penaliza_no_show' => true,
        ]);
    }
}
