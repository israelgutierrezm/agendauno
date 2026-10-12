<?php

declare(strict_types=1);

namespace App\Console\Commands\Demos;

use App\Modules\Tenancy\Application\CaptacionTenant;
use App\Modules\Tenancy\Application\FormulariosCaptacionTenant;
use App\Modules\Tenancy\Application\IncidenciasCobroTenant;
use App\Modules\Tenancy\Application\ProspectosTenant;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Captacion\EstadoProspecto;
use App\Modules\Tenancy\Captacion\MotivoCierreProspecto;
use App\Modules\Tenancy\Models\EntregaWebhookTenant;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\WebhookSalienteTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Lo que un negocio en marcha también tiene y la historia diaria no crea: su aviso de
 * privacidad, prospectos en cada etapa (uno ya convertido con lo que le interesó),
 * cobros por conciliar y un webhook pausado con sus entregas. Así cada
 * apartado del panel y de «Mi cuenta» tiene datos (bloque 32). Con los servicios del
 * dominio y el reloj en el momento de cada cosa, como {@see ApartadosDemo}.
 *
 * @phpstan-require-extends DemoBase
 */
trait CompletarDemo
{
    /**
     * El aviso de privacidad del negocio (la plantilla de la web, con su nombre, su
     * producto y sus palabras) y quiénes ya lo aceptaron en su cuenta o en mostrador.
     */
    protected function avisoDePrivacidad(string $servicios, string $planes, string $domicilio, string $correo, int $porcientoAcepta): void
    {
        $plantilla = base_path('../web/src/marketing/legales/aviso-negocio.txt');
        $producto = $this->estudio->producto();
        $texto = File::exists($plantilla)
            ? (string) File::get($plantilla)
            : "AVISO DE PRIVACIDAD DE {negocio}\n\n{negocio}, con domicilio en [DOMICILIO DEL NEGOCIO], trata tus datos para administrar tus {servicios}, tus {planes} y tus pagos. Escríbenos a [CORREO DE CONTACTO] para ejercer tus derechos.";
        $texto = strtr($texto, [
            '{negocio}' => (string) $this->estudio->nombre,
            '{servicios}' => $servicios,
            '{planes}' => $planes,
            '[DOMICILIO DEL NEGOCIO]' => $domicilio,
            '[CORREO DE CONTACTO]' => $correo,
            'AgendaUno (agendauno.mx)' => "{$producto->nombre()} ({$producto->dominio()})",
            'AgendaUno' => $producto->nombre(),
        ]);

        $this->en($this->inicio->subDays(20)->setTime(12, 0));
        $waivers = app(WaiversTenant::class);
        $aviso = $waivers->publicar(WaiversTenant::AVISO_PRIVACIDAD, 'Aviso de privacidad', $texto);
        foreach ($this->idsDeClientes() as $id) {
            if (! $this->prob($porcientoAcepta)) {
                continue;
            }
            $persona = PersonaTenant::query()->find($id);
            if (! $persona instanceof PersonaTenant) {
                continue;
            }
            $this->en($this->despuesDelAlta($persona, 0, 20));
            $waivers->aceptar($persona, $aviso, null);
            $this->sumar('avisos de privacidad aceptados');
        }
    }

    /**
     * Prospectos en cada etapa. Unos llegan por el formulario de la página (con lo que
     * les interesa y de dónde vienen) y otros los captura el equipo; cada uno con su
     * historia: responsable, notas, seguimiento, aprobado o descartado con motivo.
     *
     * @param  list<array{nombre: string, email: string, telefono: string|null, dias: int, fuente: string|null, interes: array<string, string>, estado: string, nota?: string, motivo?: MotivoCierreProspecto}>  $prospectos  días = hace cuántos llegó; `interes` con ulids (sesion, producto, actividad); `estado` = nuevo | en_seguimiento | aprobado | descartado
     * @param  list<Usuario>  $equipo  quienes les dan seguimiento
     */
    protected function prospectos(array $prospectos, array $equipo): void
    {
        $servicio = app(ProspectosTenant::class);
        $formulario = app(FormulariosCaptacionTenant::class)->predeterminado();
        foreach ($prospectos as $n => $p) {
            $llega = $this->hoy->subDays($p['dias'])->setTime($this->azar(9, 21), $this->azar(0, 59));
            $quien = $equipo[$n % count($equipo)];
            $this->en($llega);
            try {
                $prospecto = $p['fuente'] !== null
                    ? app(CaptacionTenant::class)->recibir($formulario, [
                        'nombre' => $p['nombre'], 'email' => $p['email'], 'telefono' => $p['telefono'],
                        'interes' => $p['interes'], 'fuente' => $p['fuente'],
                    ])
                    : $servicio->crearManual(['nombre' => $p['nombre'], 'email' => $p['email'], 'telefono' => $p['telefono']], $quien);
            } catch (ValidationException|RuntimeException) {
                continue;
            }
            $this->sumar('prospectos');
            if ($p['estado'] === 'nuevo') {
                continue;
            }

            // El equipo lo toma al poco rato y le da seguimiento.
            $this->en($this->despuesDeLlegar($llega, 35));
            $servicio->asignar($prospecto, $quien, $quien);
            $servicio->cambiarEstado($prospecto, EstadoProspecto::EnSeguimiento, $quien);
            if (isset($p['nota'])) {
                $this->en($this->despuesDeLlegar($llega, 50));
                $servicio->anotar($prospecto, $p['nota'], $quien);
            }
            if ($p['estado'] === 'en_seguimiento') {
                $servicio->programarSeguimiento($prospecto, $this->hoy->addDays($this->azar(0, 3))->setTime(11, 0), $quien);
            } elseif ($p['estado'] === 'aprobado') {
                $this->en($this->despuesDeLlegar($llega, 60 * 24));
                $servicio->cambiarEstado($prospecto, EstadoProspecto::Aprobado, $quien, null, 'Ya vino a conocer el lugar.');
            } elseif ($p['estado'] === 'descartado') {
                $this->en($this->despuesDeLlegar($llega, 60 * 48));
                $servicio->cambiarEstado($prospecto, EstadoProspecto::Descartado, $quien, $p['motivo'] ?? MotivoCierreProspecto::SinRespuesta);
            }
        }
    }

    /** Unos minutos después de que llegó, sin pasar de ahora. */
    private function despuesDeLlegar(CarbonImmutable $llega, int $minutos): CarbonImmutable
    {
        return $llega->addMinutes($minutos)->min($this->ahora->subMinutes(5));
    }

    /**
     * Alguien que fue prospecto y ya es cliente con cuenta: al entrar ve lo que le
     * interesó (`GET /mi/interes`, ADR 0137) mientras no lo tenga.
     *
     * @param  array{producto?: string, sesion?: string}  $interes  ulids
     */
    protected function prospectoConvertido(PersonaTenant $persona, array $interes, int $dias, Usuario $quien, string $nota): void
    {
        $servicio = app(ProspectosTenant::class);
        $llega = $this->hoy->subDays($dias)->setTime(19, 10);
        $this->en($llega);
        $prospecto = $servicio->crearManual([
            'nombre' => trim("{$persona->nombre} {$persona->primer_apellido}"), 'email' => $persona->email, 'telefono' => $persona->celular,
        ], $quien);
        $prospecto->update([
            'fuente' => 'instagram',
            'producto_id' => isset($interes['producto']) ? ProductoTenant::query()->where('ulid', $interes['producto'])->value('id') : null,
            'sesion_id' => isset($interes['sesion']) ? SesionTenant::query()->where('ulid', $interes['sesion'])->value('id') : null,
        ]);
        $this->en($llega->addMinutes(40));
        $servicio->anotar($prospecto, $nota, $quien);
        $this->en($llega->addDay()->setTime(12, 30));
        $servicio->convertir($prospecto, $this->estudio, $quien, $persona, false);
        $this->sumar('prospectos convertidos');
    }

    /**
     * Cobros por conciliar: un pago que llegó tarde y uno cobrado dos veces, sobre
     * pagos reales de la historia (sin resolver todavía).
     */
    protected function porConciliar(): void
    {
        $incidencias = app(IncidenciasCobroTenant::class);
        $pagos = PagoTenant::query()->where('estado', EstadoPago::Aprobado->value)
            ->whereNotNull('orden_id')->orderByDesc('id')->skip(3)->take(40)->get()->all();
        $casos = [
            [IncidenciaCobroTenant::PAGO_TARDIO, 'Llegó el pago cuando su apartado ya había vencido: revisa si se le devuelve o se le aplica.'],
            [IncidenciaCobroTenant::PAGO_DUPLICADO, 'La pasarela avisó un segundo cobro de la misma compra: devuélvelo.'],
        ];
        foreach ($casos as $n => [$tipo, $detalle]) {
            $pago = $pagos[$n * 7] ?? null;
            if (! $pago instanceof PagoTenant) {
                continue;
            }
            $this->en($this->ahora->subHours(30 - $n * 12));
            $incidencias->porPago($tipo, $pago, $detalle);
            $this->sumar('cobros por conciliar');
        }
    }

    /**
     * Un webhook a su sistema que el negocio pausó, con sus últimas entregas. Pausado
     * no envía nada: los eventos nuevos no le llegan.
     *
     * @param  list<string>  $eventos
     */
    protected function webhookPausado(string $url, array $eventos): void
    {
        $this->en($this->hoy->subDays(40)->setTime(10, 0));
        $webhook = WebhookSalienteTenant::query()->create([
            'url' => $url, 'secreto' => 'whsec_'.Str::random(40), 'eventos' => $eventos, 'activo' => false,
        ]);
        foreach ([38, 25, 11] as $n => $dias) {
            $cuando = $this->hoy->subDays($dias)->setTime(13, $n * 7);
            $this->en($cuando);
            EntregaWebhookTenant::query()->create([
                'webhook_id' => $webhook->getKey(), 'evento_ulid' => (string) Str::ulid(), 'evento_tipo' => $eventos[$n % count($eventos)],
                'payload' => ['demo' => true], 'estado' => 'entregado', 'http_status' => 200, 'intentos' => 1,
                'ultimo_error' => null, 'entregado_en' => $cuando,
            ]);
        }
        $this->sumar('webhooks (pausado)');
    }
}
