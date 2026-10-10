<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ResenaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Support\HorarioSucursal;
use App\Modules\Tenancy\Support\RedesSociales;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Escaparate PÚBLICO del estudio (embudo público, P0 #3): la página que ve un
 * prospecto antes de registrarse — identidad (descripción, portada, redes), sus
 * sucursales (dirección, mapa, WhatsApp, redes y horario), profesionales con foto,
 * servicios o clases con su descripción, el horario semanal de clases, próximas clases
 * (con cupo), precios y reseñas. Sin auth, pero SOLO con la página pública abierta
 * ({@see Estudio::paginaPublica()}): publicado, esté o no en el directorio («solo con
 * enlace»); uno no publicado no tiene escaparate. Expone únicamente datos públicos (nunca IDs internos, correos ni
 * datos sensibles). Opera sobre la BD del estudio ya resuelto por `estudio.resolver`.
 */
class EscaparateController
{
    // Cuántas próximas clases mostrar en el escaparate.
    private const PROXIMAS = 12;

    public function __invoke(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        // El escaparate es la cara pública: solo con la página abierta.
        abort_unless($estudio->paginaPublica(), 404);
        // La lada del país del negocio completa los números capturados sin «+» (ADR 0103).
        $region = app(RegionNegocioTenant::class);
        $lada = $region->lada();
        // Solo clases o solo citas (ADR 0104): la modalidad guardada, no sus ofertas.
        $modalidad = $estudio->modalidad();
        $esCitas = $modalidad === ModalidadServicio::Citas;

        return response()->json(['data' => [
            'estudio' => [
                'slug' => $estudio->slug,
                'nombre' => $estudio->nombre,
                'logo_url' => $estudio->logo_url,
                'portada_url' => $estudio->portada_url,
                'color_marca' => $estudio->color_marca,
                'descripcion' => $estudio->descripcion,
                'redes' => RedesSociales::publicas($estudio->redes),
                'perfil' => $estudio->perfil_negocio->value,
                'perfil_config' => $estudio->perfilConfig(),
                'ciudad' => $estudio->ciudad,
                'pais' => $region->pais(),
                'lada' => $lada,
                'whatsapp' => $estudio->whatsappCompleto(),
                'whatsapp_url' => self::enlaceWhatsapp($estudio->whatsappCompleto(), $estudio->contacto_whatsapp_pais),
                'modalidad' => $modalidad->value,
                'capacidades' => $modalidad->capacidades(),
                // ¿Se agendan citas en línea? (para el CTA de reserva): un negocio de citas.
                'tiene_citas' => $esCitas,
                // ¿Publicó su aviso de privacidad para sus clientes? (enlace en su página).
                'aviso_privacidad' => app(WaiversTenant::class)->avisoPrivacidad() !== null,
            ],
            'sucursales' => $this->sucursales($lada),
            'instructores' => $this->instructores(),
            'servicios' => $this->servicios($esCitas),
            'horario_clases' => $this->horarioClases(),
            'productos' => $this->productos(),
            'proximas_sesiones' => $this->proximasSesiones(),
            'resenas' => $this->resenas(),
        ]]);
    }

    /**
     * Enlace de WhatsApp (wa.me) desde un número capturado con o sin lada: sin «+» se
     * completa con la lada que se da (la del país del negocio), como los avisos.
     */
    public static function enlaceWhatsapp(?string $numero, ?string $lada): ?string
    {
        $digitos = TelefonoWhatsApp::normalizar($numero, $lada);

        return $digitos !== null ? 'https://wa.me/'.$digitos : null;
    }

    /**
     * Cada sede con lo que necesita quien la busca: dónde está (con enlace al mapa),
     * cómo escribirle, sus redes y a qué hora atiende.
     *
     * @return list<array<string, mixed>>
     */
    private function sucursales(string $lada): array
    {
        return SucursalTenant::query()
            ->orderBy('nombre')
            ->get()
            ->map(static function (SucursalTenant $s) use ($lada): array {
                return [
                    'id' => $s->ulid,
                    'nombre' => $s->nombre,
                    'zona_horaria' => $s->zona_horaria,
                    'region' => $s->region,
                    'direccion' => $s->direccion,
                    'foto_url' => $s->fotoUrl(),
                    'mapa_url' => $s->enlaceMapa(),
                    'telefono' => $s->telefono,
                    'whatsapp' => $s->whatsapp,
                    'whatsapp_url' => self::enlaceWhatsapp($s->whatsapp, $lada),
                    'redes' => RedesSociales::publicas($s->redes),
                    'horario' => HorarioSucursal::publico($s),
                ];
            })->all();
    }

    /**
     * Profesionales o instructores por nombre y foto (nunca correo ni datos sensibles).
     *
     * @return list<array{nombre: string, foto_url: string|null}>
     */
    private function instructores(): array
    {
        return Usuario::query()
            ->profesionales()
            ->orderBy('name')
            ->get()
            ->map(static fn (Usuario $u): array => ['nombre' => (string) $u->name, 'foto_url' => $u->fotoUrl()])
            ->values()
            ->all();
    }

    /**
     * Servicios o clases con su descripción, agrupables por categoría (la actividad del
     * catálogo) y con sus niveles. En un negocio de citas, los que se cobran al agendar
     * se agendan en línea; una clase de pago suelto no es una cita.
     *
     * @return list<array<string, mixed>>
     */
    private function servicios(bool $esCitas): array
    {
        // Los precios, en la moneda del negocio (ADR 0097).
        $moneda = app(ParametrosTenant::class)->moneda();

        return OfertaTenant::query()
            ->with(['actividad.niveles', 'incluidas'])
            ->orderBy('nombre')
            ->get()
            ->map(static fn (OfertaTenant $o): array => [
                'id' => $o->ulid,
                'nombre' => $o->nombre,
                'descripcion' => $o->descripcion,
                'categoria' => $o->actividad?->nombre,
                // Paquete: qué incluye y cuánto costaría por separado.
                'incluye' => $o->incluidas->pluck('nombre')->values()->all(),
                'precio_por_separado_minor' => $o->precioPorSeparadoMinor(),
                'foto_url' => $o->fotoUrl(),
                'grupal' => $o->modalidad === ModalidadOfertaTenant::Grupal,
                'duracion_minutos' => $o->duracion_minutos,
                'precio_minor' => $o->precio_clase_minor,
                'moneda' => $moneda,
                'agendable' => $esCitas && $o->politica_reserva === PoliticaReservaTenant::Pago,
                'niveles' => $o->actividad?->niveles->sortBy('orden')->pluck('nombre')->values()->all() ?? [],
            ])->values()->all();
    }

    /**
     * El horario semanal de clases (las recurrentes vigentes): por día, hora, clase,
     * quién la da y en qué sede. Es lo que un estudio publica en sus redes.
     *
     * @return list<array<string, mixed>>
     */
    private function horarioClases(): array
    {
        $hoy = app(FechasNegocioTenant::class)->hoy();
        $filas = [];
        PlantillaHorarioTenant::query()
            ->where('activo', true)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $hoy))
            ->with(['oferta.actividad', 'sucursal', 'instructor'])
            ->get()
            ->each(function (PlantillaHorarioTenant $p) use (&$filas): void {
                if ($p->oferta === null || $p->oferta->modalidad !== ModalidadOfertaTenant::Grupal) {
                    return;
                }
                foreach ($p->dias_semana ?? [] as $dia) {
                    $filas[] = [
                        'dia' => (int) $dia,
                        'hora' => (string) $p->hora_local,
                        'duracion_minutos' => $p->duracion_minutos,
                        'clase' => $p->oferta->nombre,
                        'categoria' => $p->oferta->actividad?->nombre,
                        'instructor' => $p->instructor?->name,
                        'sucursal' => $p->sucursal?->nombre,
                    ];
                }
            });
        usort($filas, static fn (array $a, array $b): int => [$a['dia'], $a['hora']] <=> [$b['dia'], $b['hora']]);

        return $filas;
    }

    /**
     * Precios públicos (productos vendibles). Dinero en minor + moneda.
     *
     * @return list<array<string, mixed>>
     */
    private function productos(): array
    {
        return ProductoTenant::query()
            ->where('archivado', false)
            ->orderBy('precio_minor')
            ->get()
            ->map(static fn (ProductoTenant $p): array => [
                'nombre' => $p->nombre,
                'tipo' => $p->tipo->value,
                'precio_minor' => $p->precio_minor,
                'moneda' => $p->moneda,
                'ilimitado' => $p->ilimitado,
                'creditos_incluidos' => $p->creditos_incluidos,
                ...$p->coberturaSucursales(),
                // Cuánto dura lo que se compra (p. ej. 1 mes, o hasta fin de mes).
                'vigencia_tipo' => $p->vigencia_tipo?->value,
                'vigencia_cantidad' => $p->vigencia_cantidad,
            ])->all();
    }

    /**
     * Próximas clases programadas con cupo libre estimado (capacidad − confirmadas).
     *
     * @return list<array<string, mixed>>
     */
    private function proximasSesiones(): array
    {
        $sesiones = SesionTenant::query()
            ->where('estado', EstadoSesionTenant::Programada->value)
            // Las citas son privadas: el escaparate solo muestra clases abiertas.
            ->where('tipo', TipoSesionTenant::Clase->value)
            ->where('inicia_en', '>=', CarbonImmutable::now())
            ->withCount(['reservas as confirmadas' => fn ($q) => $q->where('estado', EstadoReserva::Confirmada->value)])
            ->with(['oferta', 'sucursal', 'instructor'])
            ->orderBy('inicia_en')
            ->limit(self::PROXIMAS)
            ->get();

        return $sesiones->map(static function (SesionTenant $s): array {
            $confirmadas = (int) ($s->getAttribute('confirmadas') ?? 0);
            $capacidad = $s->capacidad;

            return [
                'clase' => $s->oferta?->nombre,
                'inicia_en' => $s->inicia_en->toIso8601String(),
                'zona_horaria' => $s->zona_horaria,
                'sucursal' => $s->sucursal?->nombre,
                'instructor' => $s->instructor?->name,
                'capacidad' => $capacidad,
                'lugares_libres' => $capacidad !== null ? max(0, $capacidad - $confirmadas) : null,
            ];
        })->all();
    }

    /**
     * Calificación pública: promedio y las reseñas más recientes con comentario
     * (solo las visibles; sin apellidos).
     *
     * @return array{promedio: float|null, total: int, recientes: list<array<string, mixed>>}
     */
    private function resenas(): array
    {
        $visibles = ResenaTenant::query()->where('visible', true);
        $total = (clone $visibles)->count();

        return [
            'promedio' => $total > 0 ? round((float) (clone $visibles)->avg('calificacion'), 1) : null,
            'total' => $total,
            'recientes' => (clone $visibles)
                ->whereNotNull('comentario')
                ->with(['persona', 'oferta'])
                ->orderByDesc('id')
                ->limit(6)
                ->get()
                ->map(static fn (ResenaTenant $r): array => [
                    'calificacion' => $r->calificacion,
                    'comentario' => $r->comentario,
                    'nombre' => $r->persona?->nombre,
                    'actividad' => $r->oferta?->nombre,
                    'fecha' => $r->created_at?->toDateString(),
                ])->values()->all(),
        ];
    }
}
