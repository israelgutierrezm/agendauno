<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\PoliticaReservaTenant;

/**
 * Lo que se puede elegir al agendar una cita: los servicios agendables, las sedes y
 * los profesionales. Lo usan la página pública del negocio y la cuenta del cliente.
 */
class OpcionesCitaTenant
{
    /**
     * @return array{servicios: list<array<string, mixed>>, sucursales: list<array<string, mixed>>, instructores: list<array<string, mixed>>}
     */
    public function listar(): array
    {
        return [
            'servicios' => OfertaTenant::query()
                ->where('politica_reserva', PoliticaReservaTenant::Pago->value)
                ->orderBy('nombre')
                ->get()
                ->map(static fn (OfertaTenant $o): array => [
                    'id' => $o->ulid,
                    'nombre' => $o->nombre,
                    'precio_minor' => $o->precio_clase_minor,
                    'moneda' => 'MXN',
                    'duracion_minutos' => $o->duracion_minutos,
                ])->values()->all(),
            'sucursales' => SucursalTenant::query()
                ->orderBy('nombre')
                ->get()
                ->map(static fn (SucursalTenant $s): array => [
                    'id' => $s->ulid,
                    'nombre' => $s->nombre,
                    'zona_horaria' => $s->zona_horaria,
                ])->values()->all(),
            'instructores' => Usuario::query()
                ->whereJsonContains('roles', 'instructor')
                ->orderBy('name')
                ->get()
                ->map(static fn (Usuario $u): array => [
                    'id' => $u->ulid,
                    'nombre' => (string) $u->name,
                ])->values()->all(),
        ];
    }
}
