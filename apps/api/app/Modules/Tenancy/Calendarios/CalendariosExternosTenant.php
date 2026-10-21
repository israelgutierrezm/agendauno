<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Calendarios;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\BloqueoAgendaTenant;
use App\Modules\Tenancy\Models\CalendarioExternoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoCalendarioExternoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Google Calendar y Outlook en las dos direcciones (ADR 0199).
 *
 * - Hacia allá: las clases y citas que atiende cada quien se publican en un calendario
 *   propio del negocio dentro de su cuenta (no en el principal), sin datos de clientes.
 * - Hacia acá: lo ocupado de su calendario principal se vuelve bloqueos de su agenda,
 *   que se reemplazan en cada pasada; el verificador de agenda ya los respeta.
 *
 * La autorización es OAuth con el cliente de la plataforma; las llaves de cada cuenta van
 * cifradas en la base del negocio. El estado del OAuth va cifrado y vence en 15 minutos.
 */
class CalendariosExternosTenant
{
    public const ACTIVO = 'activo';

    /** Google o Microsoft retiraron el permiso: hay que volver a conectar. */
    public const RECONECTAR = 'reconectar';

    private const VIGENCIA_ESTADO_MIN = 15;

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function proveedor(string $clave): ProveedorCalendario
    {
        return match ($clave) {
            'google' => app(CalendarioGoogle::class),
            'microsoft' => app(CalendarioMicrosoft::class),
            default => throw new InvalidArgumentException('Proveedor de calendario desconocido.'),
        };
    }

    /**
     * Qué proveedores tiene configurados la plataforma.
     *
     * @return list<string>
     */
    public function disponibles(): array
    {
        return array_values(array_filter(CalendarioExternoTenant::PROVEEDORES, fn (string $p): bool => $this->proveedor($p)->configurado()));
    }

    /** A dónde regresa Google o Microsoft tras autorizar (la misma para todos los negocios). */
    public function vuelta(string $proveedor): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/calendarios/'.$proveedor.'/vuelta';
    }

    public function urlParaConectar(Usuario $usuario, Estudio $estudio, string $proveedor): string
    {
        $estado = Crypt::encryptString((string) json_encode([
            'e' => (string) $estudio->slug,
            'u' => (string) $usuario->ulid,
            'p' => $proveedor,
            'v' => CarbonImmutable::now()->addMinutes(self::VIGENCIA_ESTADO_MIN)->getTimestamp(),
        ]));

        return $this->proveedor($proveedor)->urlAutorizacion($estado, $this->vuelta($proveedor));
    }

    /**
     * El estado que regresó con la autorización, si es nuestro y está vigente.
     *
     * @return array{estudio: string, usuario: string, proveedor: string}|null
     */
    public function leerEstado(string $estado): ?array
    {
        try {
            $datos = json_decode(Crypt::decryptString($estado), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($datos) || ! is_string($datos['e'] ?? null) || ! is_string($datos['u'] ?? null) || ! is_string($datos['p'] ?? null)
            || (int) ($datos['v'] ?? 0) < CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        return ['estudio' => $datos['e'], 'usuario' => $datos['u'], 'proveedor' => $datos['p']];
    }

    /**
     * Canjea el código y guarda la conexión (en la base del negocio actual). Si conecta
     * otra cuenta del mismo proveedor, lo publicado en la anterior se olvida.
     */
    public function conectar(Usuario $usuario, string $proveedor, string $codigo): CalendarioExternoTenant
    {
        $tokens = $this->proveedor($proveedor)->canjearCodigo($codigo, $this->vuelta($proveedor));

        $calendario = CalendarioExternoTenant::query()->firstOrNew(['usuario_id' => $usuario->getKey(), 'proveedor' => $proveedor]);
        if ($calendario->exists && $calendario->cuenta !== $tokens['cuenta']) {
            EventoCalendarioExternoTenant::query()->where('calendario_externo_id', $calendario->getKey())->delete();
            $calendario->calendario_id = null;
        }
        $calendario->fill([
            'cuenta' => $tokens['cuenta'],
            'token_refresco' => $tokens['refresco'],
            'token_acceso' => $tokens['acceso'],
            'token_vence_en' => $tokens['vence_en'],
            'estado' => self::ACTIVO,
            'ultimo_error' => null,
        ])->save();

        return $calendario;
    }

    /**
     * Quita la conexión: borra de su cuenta el calendario del negocio (si se puede) y,
     * con la fila, sus bloqueos traídos.
     */
    public function desconectar(CalendarioExternoTenant $calendario): void
    {
        if ($calendario->calendario_id !== null && $calendario->estado === self::ACTIVO) {
            try {
                $this->proveedor($calendario->proveedor)->borrarCalendario($this->acceso($calendario), $calendario->calendario_id);
            } catch (Throwable $e) {
                report($e);
            }
        }
        DB::connection('tenant')->transaction(function () use ($calendario): void {
            BloqueoAgendaTenant::withTrashed()->where('calendario_externo_id', $calendario->getKey())->forceDelete();
            $calendario->delete();
        });
    }

    /**
     * Una pasada completa de un calendario: publica lo suyo y trae lo ocupado. Nunca
     * lanza: deja el error en la conexión (y si el permiso se retiró, pide reconectar).
     */
    public function sincronizar(CalendarioExternoTenant $calendario): bool
    {
        $llave = 'calendario-externo:'.($this->gestor->actual()->slug ?? '').':'.$calendario->getKey();

        return (bool) Cache::lock($llave, 300)->get(function () use ($calendario): bool {
            try {
                $acceso = $this->acceso($calendario);
                $this->publicar($calendario, $acceso);
                $this->traerOcupado($calendario, $acceso);
                $calendario->forceFill(['estado' => self::ACTIVO, 'ultimo_error' => null, 'sincronizado_en' => CarbonImmutable::now()])->save();

                return true;
            } catch (Throwable $e) {
                $retirado = $e instanceof RequestException && in_array($e->response->status(), [400, 401], true)
                    && in_array((string) $e->response->json('error'), ['invalid_grant', 'unauthorized_client'], true);
                $calendario->forceFill([
                    'estado' => $retirado ? self::RECONECTAR : $calendario->estado,
                    'ultimo_error' => Str::limit($retirado ? 'El permiso se retiró: vuelve a conectar tu calendario.' : $e->getMessage(), 290),
                ])->save();
                if (! $retirado) {
                    report($e);
                }

                return false;
            }
        });
    }

    /** Todas las conexiones activas del negocio actual; cuántas quedaron al día. */
    public function sincronizarTodas(): int
    {
        $bien = 0;
        CalendarioExternoTenant::query()->where('estado', self::ACTIVO)->with('usuario')->each(function (CalendarioExternoTenant $c) use (&$bien): void {
            $bien += $this->sincronizar($c) ? 1 : 0;
        });

        return $bien;
    }

    /** El token de acceso vigente (lo renueva si venció). */
    private function acceso(CalendarioExternoTenant $calendario): string
    {
        if ($calendario->token_acceso !== null && $calendario->token_vence_en !== null && $calendario->token_vence_en->isFuture()) {
            return $calendario->token_acceso;
        }
        $nuevo = $this->proveedor($calendario->proveedor)->refrescar($calendario->token_refresco);
        $calendario->forceFill([
            'token_acceso' => $nuevo['acceso'],
            'token_vence_en' => $nuevo['vence_en'],
            'token_refresco' => $nuevo['refresco'] ?? $calendario->token_refresco,
        ])->save();

        return $nuevo['acceso'];
    }

    private function publicar(CalendarioExternoTenant $calendario, string $acceso): void
    {
        $proveedor = $this->proveedor($calendario->proveedor);
        $estudio = $this->gestor->actual();
        if ($calendario->calendario_id === null) {
            $calendario->forceFill(['calendario_id' => $proveedor->crearCalendario($acceso, (string) ($estudio->nombre ?? 'Agenda'), app(FechasNegocioTenant::class)->zona())])->save();
        }
        $destino = (string) $calendario->calendario_id;

        $ahora = CarbonImmutable::now();
        $desde = $ahora->subDay();
        $hasta = $ahora->addDays($this->dias());
        $sesiones = SesionTenant::query()
            ->where('instructor_id', $calendario->usuario_id)
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->whereBetween('inicia_en', [$desde, $hasta])
            ->with(['oferta', 'sucursal'])
            ->orderBy('inicia_en')
            ->get()
            ->keyBy('id');
        $publicados = EventoCalendarioExternoTenant::query()->where('calendario_externo_id', $calendario->getKey())->get()->keyBy('sesion_id');

        foreach ($sesiones as $id => $sesion) {
            $evento = [
                'titulo' => (string) (($sesion->oferta->nombre ?? '') ?: 'Clase'),
                'inicio' => CarbonImmutable::instance($sesion->inicia_en),
                'fin' => CarbonImmutable::instance($sesion->termina_en ?? $sesion->inicia_en->copy()->addHour()),
                'lugar' => (string) ($sesion->sucursal->nombre ?? ''),
                'descripcion' => (string) ($estudio->nombre ?? ''),
            ];
            $huella = hash('sha256', (string) json_encode([$evento['titulo'], $evento['inicio']->getTimestamp(), $evento['fin']->getTimestamp(), $evento['lugar'], $evento['descripcion']]));
            $previo = $publicados->get($id);
            if ($previo instanceof EventoCalendarioExternoTenant && $previo->huella === $huella) {
                continue;
            }
            try {
                $eventoId = $proveedor->guardarEvento($acceso, $destino, $previo?->evento_id, $evento);
            } catch (RequestException $e) {
                // Lo borraron allá: se vuelve a crear.
                if ($previo === null || ! in_array($e->response->status(), [404, 410], true)) {
                    throw $e;
                }
                $eventoId = $proveedor->guardarEvento($acceso, $destino, null, $evento);
            }
            EventoCalendarioExternoTenant::query()->updateOrCreate(
                ['calendario_externo_id' => $calendario->getKey(), 'sesion_id' => $id],
                ['evento_id' => $eventoId, 'huella' => $huella],
            );
        }

        // Lo publicado que ya no le toca: cancelada, pasada a otra persona o movida fuera
        // del periodo. Lo que solo quedó atrás se queda en su calendario como historial.
        $sobran = $publicados->reject(fn (EventoCalendarioExternoTenant $e): bool => $sesiones->has($e->sesion_id));
        if ($sobran->isEmpty()) {
            return;
        }
        $vigentes = SesionTenant::query()->whereIn('id', $sobran->pluck('sesion_id'))->get()->keyBy('id');
        foreach ($sobran as $publicado) {
            $sesion = $vigentes->get($publicado->sesion_id);
            $yaPaso = $sesion instanceof SesionTenant && $sesion->estado === EstadoSesionTenant::Programada
                && (int) $sesion->instructor_id === $calendario->usuario_id && $sesion->inicia_en->lt($desde);
            if (! $yaPaso) {
                $proveedor->borrarEvento($acceso, $destino, $publicado->evento_id);
            }
            $publicado->delete();
        }
    }

    private function traerOcupado(CalendarioExternoTenant $calendario, string $acceso): void
    {
        $ahora = CarbonImmutable::now();
        $ocupado = $this->proveedor($calendario->proveedor)->ocupado($acceso, $ahora, $ahora->addDays($this->dias()));
        $motivo = $calendario->proveedor === 'google' ? 'Ocupado en Google Calendar' : 'Ocupado en Outlook';
        $zona = app(FechasNegocioTenant::class)->zona();

        $nuevos = [];
        foreach ($ocupado as $rango) {
            if ($rango['hasta']->gt($rango['desde'])) {
                $nuevos[$rango['desde']->getTimestamp().'-'.$rango['hasta']->getTimestamp()] = $rango;
            }
        }

        DB::connection('tenant')->transaction(function () use ($calendario, $nuevos, $motivo, $zona, $ahora): void {
            // Los que ya pasaron no se tocan (ya no estorban); el resto se reemplaza.
            $actuales = BloqueoAgendaTenant::query()
                ->where('calendario_externo_id', $calendario->getKey())
                ->where('hasta', '>', $ahora)
                ->get();
            foreach ($actuales as $bloqueo) {
                $clave = $bloqueo->desde->getTimestamp().'-'.$bloqueo->hasta->getTimestamp();
                if (isset($nuevos[$clave])) {
                    unset($nuevos[$clave]);

                    continue;
                }
                $bloqueo->forceDelete();
            }
            foreach ($nuevos as $rango) {
                BloqueoAgendaTenant::query()->create([
                    'instructor_id' => $calendario->usuario_id,
                    'calendario_externo_id' => $calendario->getKey(),
                    'desde' => $rango['desde'],
                    'hasta' => $rango['hasta'],
                    'todo_el_dia' => false,
                    'zona_horaria' => $zona,
                    'motivo' => $motivo,
                ]);
            }
        });
    }

    private function dias(): int
    {
        return max(1, (int) config('agendauno.calendarios.dias', 60));
    }
}
