<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\ResolverPoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\HorarioPlataformaTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\PublicacionPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\ProveedorPartner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Publica en TotalPass (ADR 0149): cada sesión es una ocurrencia sin repetición, con el
 * plan de TotalPass de su clase. TotalPass no tiene clases aparte: el plan va en cada
 * ocurrencia.
 *
 * - Lugares: TotalPass muestra los que se le dan para sus usuarios (`slots`) menos los
 *   que ya reservaron. Se le da lo que aún puede tomar más lo que ya ocupan sus
 *   visitantes (nunca menos de lo reservado).
 * - Horas en la zona de la Ciudad de México (`es-MX`), en su formato («03:00 PM»).
 * - La fecha, la hora y el plan no se cambian en una ocurrencia: si cambian, se retira y
 *   se publica de nuevo. El instructor, la duración y la ventana sí se cambian en su lugar.
 */
class PublicadorTotalPass implements PublicadorPlataforma
{
    /** La zona de `es-MX` en TotalPass. */
    private const ZONA = 'America/Mexico_City';

    public function __construct(
        private readonly ClienteTotalPassReservas $cliente,
        private readonly ParametrosTenant $parametros,
        private readonly ResolverPoliticaCancelacionTenant $politicas,
    ) {}

    public function proveedor(): ProveedorPartner
    {
        return ProveedorPartner::TotalPass;
    }

    public function registrarAvisos(IntegracionTenant $integracion, string $url): ?string
    {
        $resultado = $this->cliente->registrarAvisos($integracion->llaves()['place_api_key'] ?? '', $url);
        if (! $resultado->ok) {
            return $resultado->error ?? 'TotalPass no registró la dirección de avisos de reserva.';
        }
        $integracion->reservas_registradas_en = now();
        $integracion->save();

        return null;
    }

    public function prepararClase(PublicacionPlataformaTenant $publicacion, array $credenciales): ?string
    {
        return (string) $publicacion->producto_externo === '' ? 'Elige el plan de TotalPass con que se ofrece la clase.' : null;
    }

    public function cambiarProducto(PublicacionPlataformaTenant $publicacion, array $credenciales): ?string
    {
        // El plan va en cada ocurrencia: la sincronización las vuelve a publicar.
        return null;
    }

    public function horario(SesionTenant $sesion, PublicacionPlataformaTenant $publicacion): array
    {
        $inicio = CarbonImmutable::instance($sesion->inicia_en)->setTimezone(self::ZONA);
        $oferta = $sesion->oferta;
        $titulo = trim((string) ($oferta?->actividad->nombre ?? '').' '.(string) ($oferta->nombre ?? ''));
        $instructor = trim((string) $sesion->instructor?->name);

        $dias = $this->parametros->entero('reservas.dias_apertura');
        $cierre = max(1, $this->parametros->entero('reservas.minutos_cierre'));
        $horas = max(0, $this->politicas->paraSesion($sesion)->horasLimite);

        return [
            'title' => Str::limit($titulo !== '' ? $titulo : 'Clase', 120, ''),
            'responsible' => Str::limit($instructor !== '' ? $instructor : 'Por asignar', 120, ''),
            'duration' => max(1, (int) $sesion->inicia_en->diffInMinutes($sesion->termina_en)),
            'planId' => (int) $publicacion->producto_externo,
            'eventDate' => $inicio->format('Y-m-d'),
            'startTime' => $inicio->format('h:i A'),
            'timezone' => 'es-MX',
            'description' => Str::limit(trim((string) ($oferta->descripcion ?? '')) ?: ($titulo !== '' ? $titulo : 'Clase'), 1000, ''),
            'status' => 'ACTIVE',
            'externalReference' => (string) $sesion->getAttribute('ulid'),
            'bookingWindow' => [
                // «En cuanto se programa» (0 días) es lo más que deja abrir el negocio.
                'minTimeToBook' => $inicio->subDays($dias > 0 ? $dias : 60)->format('Y-m-d h:i A'),
                'maxTimeToBook' => $inicio->subMinutes($cierre)->format('Y-m-d h:i A'),
            ],
            'maxTimeToCancel' => $inicio->subHours($horas)->format('Y-m-d h:i A'),
        ];
    }

    /** Lo que no se cambia en una ocurrencia: su fecha, su hora y su plan. */
    public function firmaFija(array $horario): ?string
    {
        return hash('sha256', (string) json_encode([$horario['eventDate'] ?? null, $horario['startTime'] ?? null, $horario['planId'] ?? null]));
    }

    public function numeros(LugaresDeHorario $lugares): array
    {
        return [max(0, $lugares->libres + $lugares->delCanal), $lugares->delCanal];
    }

    public function crear(PublicacionPlataformaTenant $publicacion, array $horario, LugaresDeHorario $lugares, array $credenciales): ResultadoPlataforma
    {
        return $this->cliente->crearOcurrencia($credenciales['place_api_key'] ?? '', [
            ...$horario,
            'slots' => $this->numeros($lugares)[0],
        ]);
    }

    public function actualizar(HorarioPlataformaTenant $publicado, PublicacionPlataformaTenant $publicacion, array $horario, LugaresDeHorario $lugares, array $credenciales): ResultadoPlataforma
    {
        $llave = $credenciales['place_api_key'] ?? '';
        // Otra fecha, otra hora u otro plan: se retira y se publica de nuevo.
        if ($publicado->fija !== $this->firmaFija($horario)) {
            $retirada = $this->cliente->retirarOcurrencia($llave, (string) $publicado->id_externo);
            if (! $retirada->ok) {
                return $retirada;
            }

            return $this->crear($publicacion, $horario, $lugares, $credenciales);
        }
        $cambio = $this->cliente->actualizarOcurrencia($llave, (string) $publicado->id_externo, array_intersect_key($horario, array_flip([
            'title', 'responsible', 'duration', 'description', 'externalReference', 'bookingWindow',
        ])));
        if (! $cambio->ok) {
            return $cambio;
        }

        return $this->actualizarLugares($publicado, $publicacion, $lugares, $credenciales);
    }

    public function actualizarLugares(HorarioPlataformaTenant $publicado, PublicacionPlataformaTenant $publicacion, LugaresDeHorario $lugares, array $credenciales): ResultadoPlataforma
    {
        return $this->cliente->actualizarLugares($credenciales['place_api_key'] ?? '', (string) $publicado->id_externo, $this->numeros($lugares)[0]);
    }

    public function retirar(HorarioPlataformaTenant $publicado, PublicacionPlataformaTenant $publicacion, array $credenciales): ResultadoPlataforma
    {
        return $this->cliente->retirarOcurrencia($credenciales['place_api_key'] ?? '', (string) $publicado->id_externo);
    }
}
