<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\ModalidadOfertaTenant;
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
 * (con cupo), precios y reseñas. Sin auth, pero SOLO para estudios listados en el
 * directorio ({@see Estudio::enDirectorio()}); un estudio privado o no publicado no
 * tiene escaparate. Expone únicamente datos públicos (nunca IDs internos, correos ni
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
        // El escaparate es la cara pública: solo estudios listados en el directorio.
        abort_unless($estudio->enDirectorio(), 404);

        return response()->json(['data' => [
            'estudio' => [
                'slug' => $estudio->slug,
                'nombre' => $estudio->nombre,
                'logo_url' => $estudio->logo_url,
                'portada_url' => $estudio->portada_url,
                'descripcion' => $estudio->descripcion,
                'redes' => RedesSociales::publicas($estudio->redes),
                'perfil' => $estudio->perfil_negocio->value,
                'perfil_config' => $estudio->perfilConfig(),
                'ciudad' => $estudio->ciudad,
                'pais' => $estudio->pais,
                'whatsapp' => $estudio->whatsappCompleto(),
                'whatsapp_url' => self::enlaceWhatsapp($estudio->whatsappCompleto()),
                // ¿Ofrece servicios agendables como cita en línea? (para el CTA de reserva).
                'tiene_citas' => OfertaTenant::query()
                    ->where('politica_reserva', PoliticaReservaTenant::Pago->value)
                    ->exists(),
            ],
            'sucursales' => $this->sucursales(),
            'instructores' => $this->instructores(),
            'servicios' => $this->servicios(),
            'horario_clases' => $this->horarioClases(),
            'productos' => $this->productos(),
            'proximas_sesiones' => $this->proximasSesiones(),
            'resenas' => $this->resenas(),
        ]]);
    }

    /**
     * Enlace de WhatsApp (wa.me) desde un número capturado con o sin lada; un número
     * de 10 dígitos se toma como de México.
     */
    public static function enlaceWhatsapp(?string $numero): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $numero) ?? '';
        if (strlen($digitos) < 10) {
            return null;
        }

        return 'https://wa.me/'.(strlen($digitos) === 10 ? '52'.$digitos : $digitos);
    }

    /**
     * Cada sede con lo que necesita quien la busca: dónde está (con enlace al mapa),
     * cómo escribirle, sus redes y a qué hora atiende.
     *
     * @return list<array<string, mixed>>
     */
    private function sucursales(): array
    {
        return SucursalTenant::query()
            ->orderBy('nombre')
            ->get()
            ->map(static function (SucursalTenant $s): array {
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
                    'whatsapp_url' => self::enlaceWhatsapp($s->whatsapp),
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
            ->whereJsonContains('roles', 'instructor')
            ->orderBy('name')
            ->get()
            ->map(static fn (Usuario $u): array => ['nombre' => (string) $u->name, 'foto_url' => $u->fotoUrl()])
            ->values()
            ->all();
    }

    /**
     * Servicios o clases con su descripción, agrupables por categoría (la actividad del
     * catálogo) y con sus niveles; los de cita dicen si se agendan en línea.
     *
     * @return list<array<string, mixed>>
     */
    private function servicios(): array
    {
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
                'moneda' => 'MXN',
                'agendable' => $o->politica_reserva === PoliticaReservaTenant::Pago,
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
        $hoy = CarbonImmutable::today();
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
