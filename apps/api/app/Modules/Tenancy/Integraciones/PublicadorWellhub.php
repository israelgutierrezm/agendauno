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
 * Publica en Wellhub (ADR 0149): cada oferta es una clase allá y cada sesión, un horario
 * («slot») de esa clase, con la hora del negocio y su zona.
 *
 * - Lugares: Wellhub muestra `total_capacity - total_booked`. Se le manda el cupo de la
 *   clase y como ocupado todo lo que Wellhub no puede tomar, así muestra exactamente lo
 *   que le queda (lo apartado para otros canales no se le ofrece).
 * - Ventana para reservar: la del negocio (desde cuántos días antes y hasta cuántos
 *   minutos antes de empezar).
 * - Cancelar sin costo: hasta las horas de la política del negocio, con el tope de 24
 *   horas de Wellhub.
 */
class PublicadorWellhub implements PublicadorPlataforma
{
    public function __construct(
        private readonly ClienteWellhubReservas $cliente,
        private readonly ParametrosTenant $parametros,
        private readonly ResolverPoliticaCancelacionTenant $politicas,
    ) {}

    public function proveedor(): ProveedorPartner
    {
        return ProveedorPartner::Wellhub;
    }

    /**
     * Con su Integration Setup API: la dirección de avisos (check-in y reservas) con la
     * llave con que Wellhub los firma. Si el negocio no capturó una, se genera la suya.
     */
    public function registrarAvisos(IntegracionTenant $integracion, string $url): ?string
    {
        $llaves = $integracion->llaves();
        $secreto = $llaves['webhook_secret'] ?? '';
        if ($secreto === '') {
            $secreto = Str::random(40);
        }
        $resultado = $this->cliente->registrarAvisos($llaves, $url, $secreto);
        if (! $resultado->ok) {
            return $resultado->error ?? 'Wellhub no registró la dirección de avisos.';
        }
        // La llave solo se guarda si Wellhub ya firma con ella.
        $integracion->credenciales = [...$llaves, 'webhook_secret' => $secreto];
        $integracion->webhook_registrado_en = now();
        $integracion->reservas_registradas_en = now();
        $integracion->save();

        return null;
    }

    public function prepararClase(PublicacionPlataformaTenant $publicacion, array $credenciales): ?string
    {
        if ($publicacion->clase_externa !== null && $publicacion->clase_externa !== '') {
            return null;
        }
        if ((string) $publicacion->producto_externo === '') {
            return 'Elige el producto de Wellhub con que se ofrece la clase.';
        }
        [$nombre, $descripcion, $referencia] = self::clase($publicacion);
        $resultado = $this->cliente->crearClase($credenciales, $nombre, $descripcion, (string) $publicacion->producto_externo, $referencia);
        if (! $resultado->ok || $resultado->id === null) {
            return $resultado->error ?? 'Wellhub no devolvió la clase creada.';
        }
        $publicacion->clase_externa = $resultado->id;
        $publicacion->save();

        return null;
    }

    public function cambiarProducto(PublicacionPlataformaTenant $publicacion, array $credenciales): ?string
    {
        if ($publicacion->clase_externa === null || $publicacion->clase_externa === '') {
            return null;
        }
        [$nombre, $descripcion, $referencia] = self::clase($publicacion);
        $resultado = $this->cliente->actualizarClase(
            $credenciales,
            (string) $publicacion->clase_externa,
            $nombre,
            $descripcion,
            (string) $publicacion->producto_externo,
            $referencia,
        );

        return $resultado->ok ? null : ($resultado->error ?? 'Wellhub no cambió el producto de la clase.');
    }

    public function horario(SesionTenant $sesion, PublicacionPlataformaTenant $publicacion): array
    {
        $zona = $sesion->zona_horaria !== '' ? $sesion->zona_horaria : 'America/Mexico_City';
        $inicio = CarbonImmutable::instance($sesion->inicia_en)->setTimezone($zona);
        $horario = [
            'occur_date' => $inicio->toIso8601String(),
            'status' => 1,
            'length_in_minutes' => max(1, min(200, (int) $sesion->inicia_en->diffInMinutes($sesion->termina_en))),
            'product_id' => (int) $publicacion->producto_externo,
            'virtual' => false,
        ];
        $sala = trim((string) $sesion->recurso?->nombre);
        if (mb_strlen($sala) >= 2) {
            $horario['room'] = Str::limit($sala, 200, '');
        }
        $instructor = trim((string) $sesion->instructor?->name);
        $horario['instructors'] = $instructor !== '' ? [['name' => Str::limit($instructor, 100, ''), 'substitute' => false]] : [];

        $ventana = [];
        $dias = $this->parametros->entero('reservas.dias_apertura');
        if ($dias > 0) {
            $ventana['opens_at'] = $inicio->subDays($dias)->toIso8601String();
        }
        $ventana['closes_at'] = $inicio->subMinutes($this->parametros->entero('reservas.minutos_cierre'))->toIso8601String();
        $horario['booking_window'] = $ventana;

        $horas = min(24, max(0, $this->politicas->paraSesion($sesion)->horasLimite));
        $horario['cancellable_until'] = $inicio->subHours($horas)->toIso8601String();

        return $horario;
    }

    /**
     * El nombre (actividad y oferta), la descripción y la referencia (el ULID de la
     * oferta) de la clase en Wellhub.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function clase(PublicacionPlataformaTenant $publicacion): array
    {
        $oferta = $publicacion->oferta;
        $nombre = trim((string) ($oferta?->actividad->nombre ?? '').' '.(string) ($oferta->nombre ?? ''));

        return [
            $nombre !== '' ? $nombre : 'Clase',
            trim((string) ($oferta->descripcion ?? '')),
            (string) $oferta?->getAttribute('ulid'),
        ];
    }

    public function numeros(LugaresDeHorario $lugares): array
    {
        return [$lugares->total, max(0, $lugares->total - $lugares->libres)];
    }

    public function crear(PublicacionPlataformaTenant $publicacion, array $horario, LugaresDeHorario $lugares, array $credenciales): ResultadoPlataforma
    {
        [$capacidad, $reservados] = $this->numeros($lugares);

        return $this->cliente->crearHorario($credenciales, (string) $publicacion->clase_externa, [
            ...$horario,
            'total_capacity' => $capacidad,
            'total_booked' => $reservados,
        ]);
    }

    public function actualizar(HorarioPlataformaTenant $publicado, PublicacionPlataformaTenant $publicacion, array $horario, LugaresDeHorario $lugares, array $credenciales): ResultadoPlataforma
    {
        [$capacidad, $reservados] = $this->numeros($lugares);

        return $this->cliente->actualizarHorario($credenciales, (string) $publicacion->clase_externa, (string) $publicado->id_externo, [
            ...$horario,
            'total_capacity' => $capacidad,
            'total_booked' => $reservados,
        ]);
    }

    public function actualizarLugares(HorarioPlataformaTenant $publicado, PublicacionPlataformaTenant $publicacion, LugaresDeHorario $lugares, array $credenciales): ResultadoPlataforma
    {
        [$capacidad, $reservados] = $this->numeros($lugares);

        return $this->cliente->actualizarLugares($credenciales, (string) $publicacion->clase_externa, (string) $publicado->id_externo, $capacidad, $reservados);
    }

    public function retirar(HorarioPlataformaTenant $publicado, PublicacionPlataformaTenant $publicacion, array $credenciales): ResultadoPlataforma
    {
        return $this->cliente->retirarHorario($credenciales, (string) $publicacion->clase_externa, (string) $publicado->id_externo);
    }
}
