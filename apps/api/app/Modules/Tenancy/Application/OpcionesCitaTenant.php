<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Support\RedesSociales;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que se puede elegir al agendar una cita: los servicios agendables, las sedes y
 * los profesionales. Lo usan la página pública del negocio y la cuenta del cliente.
 *
 * En la página pública solo se agendan servicios con precio (se pagan ahí o en la
 * sucursal). Con la cuenta, además, los que se toman con un bono o membresía que la
 * persona tiene vigente y con saldo (`con_plan`): así se canjean sus sesiones
 * (ADR 0091). `hay_con_plan` avisa a la página pública que existen.
 */
class OpcionesCitaTenant
{
    /** Lo que se descuenta del bono por una cita (1000 unidades = 1 sesión). */
    private const UNIDADES_POR_CITA = 1000;

    /** Redes que se muestran en la tarjeta de la sede al agendar. */
    private const REDES_EN_TARJETA = ['instagram', 'facebook'];

    public function __construct(
        private readonly CobroDeCitasTenant $cobro,
        private readonly WhatsAppTenant $whatsapp,
        private readonly ResolverDerechoTenant $derechos,
    ) {}

    /**
     * @return array{servicios: list<array<string, mixed>>, hay_con_plan: bool, sucursales: list<array<string, mixed>>, instructores: list<array<string, mixed>>, cobro: array{pago_obligatorio: bool, pago_en_linea: bool}, whatsapp: bool}
     */
    public function listar(?PersonaTenant $persona = null): array
    {
        return [
            'servicios' => $this->servicios($persona),
            // Hay servicios que se toman con bono o membresía (desde la cuenta).
            'hay_con_plan' => $this->conPlan()->exists(),
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
                ->profesionales()
                ->orderBy('name')
                ->get()
                ->map(static fn (Usuario $u): array => [
                    'id' => $u->ulid,
                    'nombre' => (string) $u->name,
                    'foto_url' => $u->fotoUrl(),
                ])->values()->all(),
            // Si se paga en línea para confirmar o se puede pagar en la sucursal.
            'cobro' => $this->cobro->paraPantalla(),
            // Si se ofrece recibir los avisos de la cita por WhatsApp (ADR 0069).
            'whatsapp' => $this->whatsapp->enUso(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function servicios(?PersonaTenant $persona): array
    {
        $deCobro = OfertaTenant::query()
            ->where('politica_reserva', PoliticaReservaTenant::Pago->value)
            ->with(['actividad', 'incluidas'])
            ->get();
        $canjeables = collect();
        if ($persona instanceof PersonaTenant) {
            $conPlan = $this->conPlan()->with(['actividad', 'incluidas'])->get();
            $cubiertas = $this->derechos->ofertasCubiertas($persona, $conPlan, self::UNIDADES_POR_CITA);
            $canjeables = $conPlan->filter(static fn (OfertaTenant $o): bool => in_array((int) $o->getKey(), $cubiertas, true));
        }

        return $deCobro->concat($canjeables)
            ->sortBy('nombre')
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
                // Se descuenta de su bono o membresía (no se paga al agendar).
                'con_plan' => $o->politica_reserva !== PoliticaReservaTenant::Pago,
            ])->values()->all();
    }

    /**
     * Servicios de cita (individuales o privados) que se toman con un bono o membresía.
     *
     * @return Builder<OfertaTenant>
     */
    private function conPlan(): Builder
    {
        return OfertaTenant::query()
            ->where('politica_reserva', '!=', PoliticaReservaTenant::Pago->value)
            ->whereIn('modalidad', [ModalidadOfertaTenant::Individual->value, ModalidadOfertaTenant::Privada->value]);
    }
}
