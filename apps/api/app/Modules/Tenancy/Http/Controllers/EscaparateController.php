<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Reservas\EstadoReserva;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Escaparate PÚBLICO del estudio (embudo público, P0 #3): la página que ve un
 * prospecto antes de registrarse — identidad, próximas clases (con cupo), precios,
 * instructores y ubicación. Sin auth, pero SOLO para estudios listados en el
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
                'perfil' => $estudio->perfil_negocio->value,
                'perfil_config' => $estudio->perfilConfig(),
                'ciudad' => $estudio->ciudad,
                'pais' => $estudio->pais,
                'whatsapp' => $estudio->whatsappCompleto(),
                // ¿Ofrece servicios agendables como cita en línea? (para el CTA de reserva).
                'tiene_citas' => OfertaTenant::query()
                    ->where('politica_reserva', PoliticaReservaTenant::Pago->value)
                    ->exists(),
            ],
            'sucursales' => $this->sucursales(),
            'instructores' => $this->instructores(),
            'productos' => $this->productos(),
            'proximas_sesiones' => $this->proximasSesiones(),
        ]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sucursales(): array
    {
        return SucursalTenant::query()
            ->orderBy('nombre')
            ->get(['nombre', 'zona_horaria', 'region'])
            ->map(static fn (SucursalTenant $s): array => [
                'nombre' => $s->nombre,
                'zona_horaria' => $s->zona_horaria,
                'region' => $s->region,
            ])->all();
    }

    /**
     * Instructores por nombre (nunca correo ni datos sensibles).
     *
     * @return list<string>
     */
    private function instructores(): array
    {
        return Usuario::query()
            ->whereJsonContains('roles', 'instructor')
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn ($n): string => (string) $n)
            ->all();
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
}
