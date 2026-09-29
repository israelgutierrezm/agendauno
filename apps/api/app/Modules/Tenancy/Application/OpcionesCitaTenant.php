<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Support\RedesSociales;

/**
 * Lo que se puede elegir al agendar una cita: los servicios agendables, las sedes y
 * los profesionales. Lo usan la página pública del negocio y la cuenta del cliente.
 */
class OpcionesCitaTenant
{
    /** Redes que se muestran en la tarjeta de la sede al agendar. */
    private const REDES_EN_TARJETA = ['instagram', 'facebook'];

    public function __construct(private readonly CobroDeCitasTenant $cobro) {}

    /**
     * @return array{servicios: list<array<string, mixed>>, sucursales: list<array<string, mixed>>, instructores: list<array<string, mixed>>, cobro: array{pago_obligatorio: bool, pago_en_linea: bool}}
     */
    public function listar(): array
    {
        return [
            'servicios' => OfertaTenant::query()
                ->where('politica_reserva', PoliticaReservaTenant::Pago->value)
                ->with(['actividad', 'incluidas'])
                ->orderBy('nombre')
                ->get()
                ->map(static fn (OfertaTenant $o): array => [
                    'id' => $o->ulid,
                    'nombre' => $o->nombre,
                    // Para elegir con información: qué incluye y en qué grupo va.
                    'descripcion' => $o->descripcion,
                    'categoria' => $o->actividad?->nombre,
                    // Paquete: qué incluye y cuánto costaría por separado.
                    'incluye' => $o->incluidas->pluck('nombre')->values()->all(),
                    'precio_por_separado_minor' => $o->precioPorSeparadoMinor(),
                    'foto_url' => $o->fotoUrl(),
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
                    'region' => $s->region,
                    // Para reconocerla y llegar a la correcta (ADR 0064).
                    'direccion' => $s->direccion,
                    'foto_url' => $s->fotoUrl(),
                    'mapa_url' => $s->enlaceMapa(),
                    'redes' => array_values(array_filter(
                        RedesSociales::publicas($s->redes),
                        static fn (array $r): bool => in_array($r['red'], self::REDES_EN_TARJETA, true),
                    )),
                ])->values()->all(),
            'instructores' => Usuario::query()
                ->whereJsonContains('roles', 'instructor')
                ->orderBy('name')
                ->get()
                ->map(static fn (Usuario $u): array => [
                    'id' => $u->ulid,
                    'nombre' => (string) $u->name,
                    'foto_url' => $u->fotoUrl(),
                ])->values()->all(),
            // Si se paga en línea para confirmar o se puede pagar en la sucursal.
            'cobro' => $this->cobro->paraPantalla(),
        ];
    }
}
