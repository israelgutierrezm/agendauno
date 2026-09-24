<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\DomiciliacionNoPermitida;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pasarelas\OpenPay\ClienteOpenPay;
use Illuminate\Support\Carbon;

/**
 * Cobro en línea con OpenPay usando las llaves del negocio. Tarjeta: el cliente la
 * captura en la página de OpenPay (con 3D Secure si su banco lo pide). OXXO: cargo
 * en tienda con referencia, código de barras y recibo. Ambos quedan `pendiente`; la
 * notificación (webhook con usuario y contraseña) hace releer el cargo y confirma.
 *
 * OpenPay no tiene cómo cancelar un cargo pendiente: al reintentar, el intento
 * anterior se cierra de este lado y, si aun así se paga, se confirma si la compra
 * sigue pendiente ({@see ConfirmarPagoTenant::aprobar}).
 *
 * Pago automático: la única vía que OpenPay documenta es su API de suscripciones.
 * La tarjeta se captura con OpenPay.js en el navegador (el número nunca pasa por
 * aquí) y se guarda en un cliente de OpenPay propio de la membresía; un plan mensual
 * por su precio y la suscripción con el primer cobro en la próxima renovación.
 * OpenPay cobra solo y aquí se concilian los cargos de ese cliente.
 */
class PasarelaOpenPayTenant implements PasarelaCancelable, PasarelaConSuscripcion, PasarelaReembolsable, PasarelaTenant
{
    /**
     * Días para pagar en tienda.
     */
    private const DIAS_PARA_PAGAR = 3;

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function nombre(): string
    {
        return 'openpay';
    }

    public function cobrar(PagoTenant $pago, array $llaves): ResultadoPago
    {
        $api = $this->api($llaves);
        $orden = $pago->orden()->with(['lineas.producto', 'persona'])->first();
        $cliente = $this->cliente($orden?->persona);
        $concepto = ConceptoDeCobro::de($orden);

        if ($pago->metodo === MetodoPago::Oxxo) {
            $vence = Carbon::now()->addDays(self::DIAS_PARA_PAGAR);
            $cargo = $api->crearCargoEnTienda($pago->monto_minor, $pago->moneda, $concepto, (string) $pago->ulid, $cliente, $vence);

            return ResultadoPago::pendiente($cargo['id'], [
                'tipo' => 'voucher',
                'referencia' => $cargo['referencia'],
                'codigo_barras' => $cargo['codigo_barras'],
                'recibo' => $api->reciboDeTienda($cargo['referencia']),
                'vence' => $vence->toIso8601String(),
            ]);
        }

        $cargo = $api->crearCargoConPagina(
            $pago->monto_minor,
            $pago->moneda,
            $concepto,
            (string) $pago->ulid,
            $cliente,
            RetornoPago::urls($pago->retorno)['exito'],
        );

        return ResultadoPago::pendiente($cargo['id'], ['tipo' => 'redirect', 'url' => $cargo['url']]);
    }

    /**
     * OpenPay no cancela cargos pendientes: si ya se cobró no se abre otro intento;
     * si no, el anterior se cierra de este lado.
     */
    public function cancelar(PagoTenant $pago, array $llaves): bool
    {
        $referencia = (string) $pago->referencia_externa;
        if ($referencia === '' || ($llaves['private_key'] ?? '') === '') {
            return true;
        }

        return ($this->api($llaves)->cargo($referencia)['status'] ?? '') !== 'completed';
    }

    /**
     * Devuelve dinero de un cargo con tarjeta. OpenPay no devuelve pagos en tienda:
     * se devuelven por fuera y se registran como devolución manual.
     */
    public function reembolsar(PagoTenant $pago, int $montoMinor, array $llaves): ResultadoPago
    {
        if ($pago->metodo === MetodoPago::Oxxo) {
            throw new PasarelaNoDisponible('OpenPay no devuelve pagos hechos en tienda.');
        }

        $devolucion = $this->api($llaves)->reembolsar((string) $pago->referencia_externa, $montoMinor, 'Devolución de '.$pago->ulid);

        return match ($devolucion['status']) {
            'completed' => ResultadoPago::aprobado($devolucion['id']),
            'in_progress', 'charge_pending' => ResultadoPago::pendiente($devolucion['id']),
            default => ResultadoPago::rechazado('OpenPay rechazó la devolución ('.$devolucion['status'].').'),
        };
    }

    /**
     * Sin la tarjeta tokenizada, devuelve lo que necesita el formulario de OpenPay.js;
     * con ella, la guarda y suscribe la membresía.
     */
    public function suscribir(DomiciliacionTenant $domiciliacion, AcuerdoTenant $acuerdo, ?string $retorno, array $datos, array $llaves): array
    {
        $token = $datos['token_id'] ?? '';
        $dispositivo = $datos['device_session_id'] ?? '';
        if ($token === '' || $dispositivo === '') {
            return ['estado' => 'formulario', 'formulario' => [
                'proveedor' => 'openpay',
                'merchant_id' => (string) ($llaves['merchant_id'] ?? ''),
                'public_key' => (string) ($llaves['public_key'] ?? ''),
                'sandbox' => $this->sandbox(),
            ]];
        }

        $acuerdo->loadMissing(['producto', 'persona']);
        $producto = $acuerdo->producto ?? throw new DomiciliacionNoPermitida('La membresía no tiene producto.');
        $api = $this->api($llaves);

        $cliente = (string) $domiciliacion->cliente_externo;
        if ($cliente === '') {
            $cliente = $api->crearCliente($this->cliente($acuerdo->persona), (string) $domiciliacion->ulid);
            $domiciliacion->update(['cliente_externo' => $cliente]);
        }
        $tarjeta = $api->guardarTarjeta($cliente, $token, $dispositivo);
        $plan = $api->crearPlanMensual(
            ((string) $producto->nombre).' · '.$acuerdo->ulid,
            (int) $producto->precio_minor,
            (string) $producto->moneda,
        );

        // Sin cobro hasta la víspera de la renovación; OpenPay cobra al día siguiente.
        $proxima = Carbon::parse($acuerdo->proxima_cobro_en ?? Carbon::now());
        $ultimoDiaSinCobro = $proxima->isFuture() ? $proxima->copy()->subDay() : Carbon::yesterday();
        $suscripcion = $api->crearSuscripcion($cliente, $plan, $tarjeta['id'], $ultimoDiaSinCobro);

        $domiciliacion->update([
            'suscripcion_externa' => $suscripcion,
            'metodo_externo' => $tarjeta['id'],
            'marca' => $tarjeta['marca'],
            'ultimos4' => $tarjeta['ultimos4'],
            'expira_mes' => $tarjeta['expira_mes'],
            'expira_anio' => $tarjeta['expira_anio'],
        ]);

        return ['estado' => 'activa'];
    }

    public function consultarSuscripcion(DomiciliacionTenant $domiciliacion, array $llaves): array
    {
        $suscripcion = $this->api($llaves)->suscripcion((string) $domiciliacion->cliente_externo, (string) $domiciliacion->suscripcion_externa);

        return [
            // past_due: OpenPay sigue reintentando; unpaid/cancelled: ya no cobra.
            'estado' => match ((string) ($suscripcion['status'] ?? '')) {
                'trial', 'active', 'past_due' => 'activa',
                'unpaid', 'cancelled' => 'cancelada',
                default => 'pendiente',
            },
            'tarjeta' => null,
        ];
    }

    public function cobrosDeSuscripcion(DomiciliacionTenant $domiciliacion, array $llaves): array
    {
        $cobros = [];
        foreach ($this->api($llaves)->cargosDeCliente((string) $domiciliacion->cliente_externo) as $cargo) {
            if (($cargo['transaction_type'] ?? 'charge') !== 'charge') {
                continue;
            }
            $estado = (string) ($cargo['status'] ?? '');
            if (! in_array($estado, ['completed', 'failed'], true)) {
                continue;
            }
            $cobros[] = new CobroDeSuscripcion(
                (string) ($cargo['id'] ?? ''),
                $estado === 'completed',
                MontoDecimal::aCentavos((float) ($cargo['amount'] ?? 0)),
                $estado === 'failed' ? 'El banco rechazó el cargo.' : null,
            );
        }

        return $cobros;
    }

    public function cancelarSuscripcion(DomiciliacionTenant $domiciliacion, array $llaves): void
    {
        if ((string) $domiciliacion->cliente_externo !== '' && (string) $domiciliacion->suscripcion_externa !== '') {
            $this->api($llaves)->cancelarSuscripcion((string) $domiciliacion->cliente_externo, (string) $domiciliacion->suscripcion_externa);
        }
    }

    /**
     * Quién paga, como lo pide OpenPay. Sin correo de la persona se usa el del
     * negocio (OpenPay lo exige y no envía correos: `send_email` apagado).
     *
     * @return array{name: string, last_name?: string, email: string, phone_number?: string}
     */
    private function cliente(?PersonaTenant $persona): array
    {
        $cliente = [
            'name' => $persona instanceof PersonaTenant ? (string) $persona->nombre : 'Cliente',
            'email' => (string) ($persona?->email ?: $this->gestor->actual()?->contacto_email),
        ];
        $apellidos = trim(((string) $persona?->primer_apellido).' '.((string) $persona?->segundo_apellido));
        if ($apellidos !== '') {
            $cliente['last_name'] = $apellidos;
        }
        $telefono = preg_replace('/\D+/', '', (string) $persona?->celular) ?? '';
        if (strlen($telefono) >= 10) {
            $cliente['phone_number'] = substr($telefono, -10);
        }

        return $cliente;
    }

    /**
     * @param  array<string, string>  $llaves
     */
    private function api(array $llaves): ClienteOpenPay
    {
        $merchant = $llaves['merchant_id'] ?? '';
        $privada = $llaves['private_key'] ?? '';
        if ($merchant === '' || $privada === '') {
            throw new PasarelaNoDisponible('OpenPay no tiene llaves configuradas.');
        }

        return new ClienteOpenPay($merchant, $privada, $this->sandbox(), (string) (request()->ip() ?? '127.0.0.1'));
    }

    private function sandbox(): bool
    {
        return ConfiguracionPasarelaTenant::query()->where('proveedor', 'openpay')->value('modo') !== 'live';
    }
}
