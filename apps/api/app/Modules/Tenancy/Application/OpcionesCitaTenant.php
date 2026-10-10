<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\HorarioAtencionTenant;
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
 * Los servicios son las ofertas del negocio de citas (ADR 0104); uno de clases no
 * agenda citas y no tiene ninguno. En la página pública solo se agendan los que se
 * cobran al agendar (se pagan ahí o en la sucursal). Con la cuenta, además, los que se
 * toman con un bono o membresía que la persona tiene vigente y con saldo
 * (`con_plan`): así se canjean sus sesiones (ADR 0091). `hay_con_plan` avisa a la
 * página pública que existen.
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
        private readonly ModalidadNegocioTenant $modalidad,
    ) {}

    /**
     * @return array{servicios: list<array<string, mixed>>, hay_con_plan: bool, sucursales: list<array<string, mixed>>, instructores: list<array<string, mixed>>, cobro: array{pago_obligatorio: bool, pago_en_linea: bool}, whatsapp: bool, reglas: array{minutos_anticipacion_minima: int, dias_maximos_adelante: int, agendar_sin_cuenta: bool}}
     */
    public function listar(?PersonaTenant $persona = null): array
    {
        $sedes = $this->sedesDeCadaProfesional();

        return [
            'servicios' => $this->servicios($persona),
            // Hay servicios que se toman con bono o membresía (desde la cuenta).
            'hay_con_plan' => $this->modalidad->esCitas() && $this->conPlan()->exists(),
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
                    // Dónde atiende (tiene horario): en otra sede no se le ofrece.
                    'sucursales' => $sedes[(int) $u->getKey()] ?? [],
                ])->values()->all(),
            // Si se paga en línea para confirmar o se puede pagar en la sucursal.
            'cobro' => $this->cobro->paraPantalla(),
            // Si se ofrece recibir los avisos de la cita por WhatsApp (ADR 0069).
            'whatsapp' => $this->whatsapp->enUso(),
            // Lo que decide el negocio para agendar en línea: con cuánta anticipación,
            // hasta cuándo y si se puede sin cuenta (la web y la app arman su calendario).
            'reglas' => [
                ...app(VentanaDeReservaTenant::class)->reglasDeCita(),
                'agendar_sin_cuenta' => app(ParametrosTenant::class)->siNo('citas.agendar_sin_cuenta'),
            ],
        ];
    }

    /**
     * Las sedes (ULID) donde atiende cada profesional: en las que tiene horario de
     * atención, que es de donde sale su disponibilidad.
     *
     * @return array<int, list<string>> id del profesional → sedes
     */
    private function sedesDeCadaProfesional(): array
    {
        $sedes = [];
        $filas = HorarioAtencionTenant::query()
            ->join('sucursales', 'sucursales.id', '=', 'horarios_atencion.sucursal_id')
            ->distinct()
            ->get(['horarios_atencion.instructor_id as profesional', 'sucursales.ulid as sede']);
        foreach ($filas as $fila) {
            $sedes[(int) $fila->getAttribute('profesional')][] = (string) $fila->getAttribute('sede');
        }

        return $sedes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function servicios(?PersonaTenant $persona): array
    {
        if (! $this->modalidad->esCitas()) {
            return [];
        }

        // Se cobran al agendar: `pago` dice cómo se habilita la reserva, no si es cita.
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

        // Sin duración propia, la que el negocio fijó para sus citas: la pantalla agenda
        // con ella (antes suponía 60 minutos).
        $duracionDefecto = app(ParametrosTenant::class)->entero('citas.duracion_defecto');

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
                'moneda' => app(ParametrosTenant::class)->moneda(),
                'duracion_minutos' => $o->duracion_minutos !== null && $o->duracion_minutos > 0 ? (int) $o->duracion_minutos : $duracionDefecto,
                // Se descuenta de su bono o membresía (no se paga al agendar).
                'con_plan' => $o->politica_reserva !== PoliticaReservaTenant::Pago,
            ])->values()->all();
    }

    /**
     * Servicios que se toman con un bono o membresía (no se cobran al agendar).
     *
     * @return Builder<OfertaTenant>
     */
    private function conPlan(): Builder
    {
        return OfertaTenant::query()
            ->where('politica_reserva', '!=', PoliticaReservaTenant::Pago->value);
    }
}
