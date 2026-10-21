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
 *   que ya reservaron en su app. Se le da lo que aún puede tomar más sus reservas vivas
 *   (nunca menos de lo reservado allá).
 * - Ventana: abre los días por adelantado del negocio antes de la clase y cierra con el
 *   cierre de reservas; TotalPass no acepta fechas pasadas, así que al mandarla lo que
 *   ya pasó se cuenta desde ahora. Lo que se compara para saber si cambió es la ventana
 *   del negocio, no la hora en que se mandó.
 * - Horas en la zona de la Ciudad de México (`es-MX`), en su formato («03:00 PM»).
 * - La fecha, la hora y el plan no se cambian en una ocurrencia: si cambian, se retira y
 *   se publica de nuevo. El instructor, la duración y la ventana sí se cambian en su lugar.
 */
class PublicadorTotalPass implements PublicadorPlataforma
{
    /** La zona de `es-MX` en TotalPass. */
    private const ZONA = 'America/Mexico_City';

    /** Sus fechas con hora: «2026-10-02 07:59 AM». */
    private const FORMATO = 'Y-m-d h:i A';

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

    public function dejarDeRecibir(IntegracionTenant $integracion): void
    {
        $this->cliente->quitarAvisos($integracion->llaves()['place_api_key'] ?? '');
        $integracion->reservas_registradas_en = null;
        $integracion->save();
    }

    public function prepararClase(PublicacionPlataformaTenant $publicacion, array $credenciales): ?string
    {
        return (string) $publicacion->producto_externo === '' ? 'Elige el plan de TotalPass con que se ofrece la clase.' : null;
    }

    public function visibilidadDeClase(PublicacionPlataformaTenant $publicacion, array $credenciales, bool $visible): ?string
    {
        // En TotalPass no hay clase aparte: lo que deja de publicarse se retira.
        return null;
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
        $formato = self::FORMATO;

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
                // «En cuanto se programa» (0 días): abre desde que se publica.
                'minTimeToBook' => $dias > 0 ? $inicio->subDays($dias)->format($formato) : null,
                'maxTimeToBook' => $inicio->subMinutes($cierre)->format($formato),
            ],
            'maxTimeToCancel' => $inicio->subHours($horas)->format($formato),
        ];
    }

    /**
     * Lo que se manda: la ventana sin fechas pasadas (TotalPass las rechaza). Si las
     * reservas ya cerraron, no se manda ventana (TotalPass conserva la que tenía).
     *
     * @param  array<string, mixed>  $horario
     * @return array<string, mixed>
     */
    private static function paraMandar(array $horario): array
    {
        $ahora = CarbonImmutable::now(self::ZONA)->addMinute()->startOfMinute();
        $leer = static fn (mixed $valor): ?CarbonImmutable => is_string($valor) && $valor !== ''
            ? CarbonImmutable::createFromFormat(self::FORMATO, $valor, self::ZONA) ?: null
            : null;
        $ventana = (array) ($horario['bookingWindow'] ?? []);
        $abre = $leer($ventana['minTimeToBook'] ?? null);
        $cierra = $leer($ventana['maxTimeToBook'] ?? null);
        $abre = $abre === null || $abre->lessThan($ahora) ? $ahora : $abre;
        if ($cierra === null || ! $abre->lessThan($cierra)) {
            unset($horario['bookingWindow']);
        } else {
            $horario['bookingWindow'] = ['minTimeToBook' => $abre->format(self::FORMATO), 'maxTimeToBook' => $cierra->format(self::FORMATO)];
        }
        $cancelar = $leer($horario['maxTimeToCancel'] ?? null);
        if ($cancelar !== null && $cancelar->lessThan($ahora)) {
            $horario['maxTimeToCancel'] = $ahora->format(self::FORMATO);
        }

        return $horario;
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
            ...self::paraMandar($horario),
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
        $cambio = $this->cliente->actualizarOcurrencia($llave, (string) $publicado->id_externo, array_intersect_key(self::paraMandar($horario), array_flip([
            'title', 'responsible', 'duration', 'description', 'externalReference', 'bookingWindow', 'maxTimeToCancel',
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
