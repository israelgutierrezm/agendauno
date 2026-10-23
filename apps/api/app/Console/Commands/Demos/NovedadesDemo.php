<?php

declare(strict_types=1);

namespace App\Console\Commands\Demos;

use App\Modules\Tenancy\Application\CajasTenant;
use App\Modules\Tenancy\Application\KioscosTenant;
use App\Modules\Tenancy\Calendarios\CalendariosExternosTenant;
use App\Modules\Tenancy\Models\CalendarioExternoTenant;
use App\Modules\Tenancy\Models\ConversacionTenant;
use App\Modules\Tenancy\Models\DatosFiscalesPersonaTenant;
use App\Modules\Tenancy\Models\MensajeConversacionTenant;
use App\Modules\Tenancy\Models\MovimientoCajaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\TurnoCajaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\WidgetTenant;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lo más nuevo de la plataforma, con datos en cada demo (octubre de 2026): la caja de
 * cada sede, la bandeja de WhatsApp e Instagram, calendarios conectados (uno que hay que
 * volver a conectar), el kiosco de llegada, los widgets para su web y los datos para
 * facturarle a algunos clientes. Se siembra al final de la historia, con su reloj y sin
 * avisos reales; lo que llamaría a un servicio externo queda sin llaves.
 *
 * @phpstan-require-extends DemoBase
 */
trait NovedadesDemo
{
    /**
     * Widgets para la web del negocio (ADR 0141).
     *
     * @param  list<array{0: string, 1: string, 2: string|null}>  $widgets  [tipo, nombre, acento]
     */
    protected function widgetsEnSuWeb(array $widgets): void
    {
        foreach ($widgets as [$tipo, $nombre, $acento]) {
            WidgetTenant::query()->create([
                'tipo' => $tipo, 'nombre' => $nombre, 'filtros' => [], 'tema' => 'auto',
                'acento' => $acento, 'dominios' => [], 'activo' => true,
            ]);
        }
        $this->sumar('widgets', count($widgets));
    }

    /**
     * La caja de cada sede (ADR 0175): ayer se abrió con su fondo, se sacó efectivo y se
     * contó a ciegas (en la primera sede faltaron $50 y se cerró con su motivo); hoy
     * está abierta.
     *
     * @param  list<SucursalTenant>  $sedes
     * @param  list<Usuario>  $cajeros  quien la opera en cada sede (en el mismo orden)
     */
    protected function cajasDeLasSedes(array $sedes, array $cajeros): void
    {
        $cajas = app(CajasTenant::class);
        foreach ($sedes as $i => $sede) {
            $quien = $cajeros[$i] ?? $cajeros[0];
            try {
                $this->en($this->hoy->subDay()->setTime(9, 0));
                $ayer = $cajas->abrir($sede, 150000, 'Fondo de siempre.', $quien);
                $this->en($this->hoy->subDay()->setTime(13, 40));
                $cajas->moverEfectivo($ayer, MovimientoCajaTenant::RETIRO, 32000, 'Garrafones de agua y café para la sala de espera.', $quien);
                $this->en($this->hoy->subDay()->setTime(20, 30));
                $contado = $cajas->esperado($ayer);
                if ($i === 0) {
                    $contado['efectivo'] -= 5000;
                }
                $turno = $cajas->contar($ayer, $contado, $quien);
                if ($turno->estado === TurnoCajaTenant::CONTADA) {
                    $this->en($this->hoy->subDay()->setTime(20, 40));
                    $cajas->cerrarConMotivo($turno, 'Faltaron $50: un cambio mal dado en la tarde.', $quien);
                }
                $this->en($this->ahora->subMinutes(90));
                $cajas->abrir($sede, 150000, null, $quien);
                $this->sumar('turnos de caja', 2);
            } catch (Throwable) {
                continue;
            }
        }
    }

    /**
     * Conversaciones de WhatsApp e Instagram en la bandeja (ADR 0200), una ya ligada a
     * su cliente y otra por contestar.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: PersonaTenant|null, 4: list<array{0: string, 1: string, 2: int}>}>  $conversaciones  [canal, contacto, nombre, persona, mensajes [dirección, texto, minutos atrás]]
     */
    protected function bandejaDeMensajes(array $conversaciones, Usuario $responde): void
    {
        foreach ($conversaciones as [$canal, $contacto, $nombre, $persona, $mensajes]) {
            $conversacion = ConversacionTenant::query()->create([
                'canal' => $canal, 'contacto_id' => $contacto, 'contacto_nombre' => $nombre,
                'persona_id' => $persona?->getKey(), 'estado' => ConversacionTenant::ABIERTA, 'no_leidos' => 0,
            ]);
            $noLeidos = 0;
            $ultimo = null;
            $entrante = null;
            foreach ($mensajes as [$direccion, $texto, $minutos]) {
                $en = $this->ahora->subMinutes($minutos);
                MensajeConversacionTenant::query()->create([
                    'conversacion_id' => $conversacion->getKey(), 'direccion' => $direccion, 'tipo' => 'texto', 'texto' => $texto,
                    'id_externo' => 'demo-'.Str::lower((string) Str::ulid()),
                    'estado' => $direccion === MensajeConversacionTenant::SALIENTE ? 'enviado' : 'recibido',
                    'enviado_por' => $direccion === MensajeConversacionTenant::SALIENTE ? $responde->getKey() : null,
                    'enviado_en' => $en,
                ]);
                $noLeidos = $direccion === MensajeConversacionTenant::ENTRANTE ? $noLeidos + 1 : 0;
                $ultimo = [$texto, $en];
                if ($direccion === MensajeConversacionTenant::ENTRANTE) {
                    $entrante = $en;
                }
            }
            $conversacion->forceFill([
                'no_leidos' => $noLeidos, 'ultimo_texto' => $ultimo[0] ?? null,
                'ultimo_mensaje_en' => $ultimo[1] ?? null, 'ultimo_entrante_en' => $entrante,
            ])->save();
        }
        $this->sumar('conversaciones', count($conversaciones));
    }

    /**
     * Calendarios de quien atiende (ADR 0199): uno conectado y otro que hay que volver a
     * conectar. Sin llaves reales: no se sincroniza con nadie.
     */
    protected function calendariosConectados(Usuario $conectado, Usuario $porReconectar): void
    {
        CalendarioExternoTenant::query()->create([
            'usuario_id' => $conectado->getKey(), 'proveedor' => 'google', 'cuenta' => $conectado->email,
            'token_refresco' => 'demo-sin-token', 'calendario_id' => 'primary',
            'estado' => CalendariosExternosTenant::ACTIVO, 'sincronizado_en' => $this->ahora->subMinutes(12),
        ]);
        CalendarioExternoTenant::query()->create([
            'usuario_id' => $porReconectar->getKey(), 'proveedor' => 'microsoft', 'cuenta' => $porReconectar->email,
            'token_refresco' => 'demo-sin-token', 'calendario_id' => null,
            'estado' => CalendariosExternosTenant::RECONECTAR,
            'ultimo_error' => 'Outlook retiró el permiso: vuelve a conectar tu calendario.',
            'sincronizado_en' => $this->ahora->subDays(3),
        ]);
        $this->sumar('calendarios conectados', 2);
    }

    /** La tableta de llegada de una sede (ADR 0183), que ya se usó hoy. */
    protected function kioscoDeLlegada(string $nombre, ?SucursalTenant $sede, Usuario $crea): void
    {
        $kiosco = app(KioscosTenant::class)->crear($nombre, $sede?->getKey() !== null ? (int) $sede->getKey() : null, $crea)['kiosco'];
        $kiosco->forceFill(['ultimo_uso_en' => $this->ahora->subMinutes(25)])->save();
        $this->sumar('kioscos', 1);
    }

    /**
     * Datos para facturar de algunos clientes (sus pagos de citas, en automático si el
     * negocio lo enciende).
     *
     * @param  list<PersonaTenant>  $personas
     */
    protected function datosParaFacturar(array $personas, bool $automatica): void
    {
        foreach ($personas as $i => $persona) {
            DatosFiscalesPersonaTenant::query()->updateOrCreate(['persona_id' => $persona->getKey()], [
                'rfc' => $this->rfcDe($persona),
                'nombre' => mb_strtoupper(trim($persona->nombreCompleto())),
                'codigo_postal' => ['06700', '03100', '06600'][$i % 3],
                'regimen_fiscal' => ['612', '626', '605'][$i % 3],
                'uso_cfdi' => 'G03',
                'email' => $persona->email,
                'factura_automatica' => $automatica && $i % 2 === 0,
            ]);
        }
        $this->sumar('clientes con datos para facturar', count($personas));
    }
}
