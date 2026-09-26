<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\ClientePasarelaTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Pasarelas\Stripe\ClienteStripe;

/**
 * Cobro en linea con Stripe usando la `secret_key` del estudio: abre una sesión de
 * Checkout (página de pago de Stripe: tarjeta con 3D Secure u OXXO) y devuelve
 * `pendiente` con la URL; el webhook firmado confirma o rechaza. Sin llave no cobra
 * (la pasarela no está lista).
 *
 * Pago automático: la tarjeta se autoriza en Checkout (modo `setup`, o al pagar una
 * compra con `domiciliar`) ligada al cliente de la persona en la cuenta del negocio;
 * cada renovación se cobra sin el cliente presente (`off_session`).
 */
class PasarelaStripeTenant implements PasarelaCancelable, PasarelaDomiciliable, PasarelaReembolsable, PasarelaTenant
{
    public function nombre(): string
    {
        return 'stripe';
    }

    public function cobrar(PagoTenant $pago, array $llaves): ResultadoPago
    {
        $api = self::api($llaves);

        $orden = $pago->orden()->with(['lineas.producto', 'persona'])->first();
        $retorno = RetornoPago::urls($pago->retorno);

        // Compra con pago automático: la tarjeta queda autorizada para las renovaciones.
        $persona = $orden?->persona;
        $domiciliar = $orden?->domiciliar === true && $persona instanceof PersonaTenant;

        $sesion = $api->crearSesionCheckout(
            $pago->monto_minor,
            $pago->moneda,
            ConceptoDeCobro::de($orden),
            $retorno['exito'],
            $retorno['cancelado'],
            $pago->metodo?->value,
            $persona?->email,
            ['pago' => (string) $pago->ulid],
            $domiciliar ? self::cliente($persona, $api) : null,
            $domiciliar,
        );

        return ResultadoPago::pendiente($sesion['id'], [
            'tipo' => 'redirect',
            'url' => $sesion['url'],
        ]);
    }

    public function cancelar(PagoTenant $pago, array $llaves): bool
    {
        $referencia = (string) $pago->referencia_externa;
        $secretKey = $llaves['secret_key'] ?? '';
        if ($secretKey === '') {
            return true;
        }

        return match (true) {
            str_starts_with($referencia, 'cs_') => (new ClienteStripe($secretKey))->expirarSesion($referencia) !== 'complete',
            // Cargo automático: se anula si el banco aún no lo cobra.
            str_starts_with($referencia, 'pi_') => (new ClienteStripe($secretKey))->anularIntent($referencia),
            default => true, // no hay nada abierto que pagar
        };
    }

    /**
     * Devuelve dinero del cobro en Stripe. La referencia del pago es la sesión de
     * Checkout (cs_…) o el PaymentIntent (pi_…: cargos automáticos y cobros
     * anteriores a Checkout).
     */
    public function reembolsar(PagoTenant $pago, int $montoMinor, array $llaves, string $idempotencia): ResultadoPago
    {
        $cliente = self::api($llaves);
        $referencia = (string) $pago->referencia_externa;
        $intent = str_starts_with($referencia, 'cs_') ? $cliente->paymentIntentDeSesion($referencia) : $referencia;
        if (! is_string($intent) || ! str_starts_with($intent, 'pi_')) {
            throw new PasarelaNoDisponible('No se encontró el cobro en Stripe.');
        }

        $reembolso = $cliente->crearReembolso(
            $intent,
            $montoMinor,
            $idempotencia,
            ['pago' => (string) $pago->ulid, 'reembolso' => $idempotencia],
        );

        return match ($reembolso['status']) {
            'succeeded' => ResultadoPago::aprobado($reembolso['id']),
            'pending', 'requires_action' => ResultadoPago::pendiente($reembolso['id']),
            default => ResultadoPago::rechazado('Stripe rechazó la devolución ('.$reembolso['status'].').'),
        };
    }

    public function iniciarGuardado(PersonaTenant $persona, ?string $retorno, array $metadata, array $llaves): array
    {
        $api = self::api($llaves);
        $urls = RetornoPago::urls($retorno, 'tarjeta');

        $sesion = $api->crearSesionGuardado(self::cliente($persona, $api), $urls['exito'], $urls['cancelado'], $metadata);

        return ['tipo' => 'redirect', 'url' => $sesion['url']];
    }

    public function cobrarDomiciliado(PagoTenant $pago, DomiciliacionTenant $domiciliacion, string $idempotencia, array $llaves): ResultadoPago
    {
        $api = self::api($llaves);
        $cliente = ClientePasarelaTenant::query()
            ->where('persona_id', $domiciliacion->persona_id)
            ->where('proveedor', 'stripe')
            ->value('cliente_externo');
        if (! is_string($cliente) || $cliente === '' || (string) $domiciliacion->metodo_externo === '') {
            return ResultadoPago::rechazado('La tarjeta guardada ya no está disponible.');
        }

        $orden = $pago->orden()->with('lineas.producto')->first();
        $cargo = $api->cobrarGuardado(
            $pago->monto_minor,
            $pago->moneda,
            $cliente,
            (string) $domiciliacion->metodo_externo,
            ConceptoDeCobro::de($orden),
            $idempotencia,
            ['orden' => (string) $orden?->ulid, 'domiciliacion' => (string) $domiciliacion->ulid],
        );

        return match ($cargo['status']) {
            'succeeded' => ResultadoPago::aprobado($cargo['id']),
            // El banco lo sigue procesando: el webhook dirá cómo terminó.
            'processing' => ResultadoPago::pendiente($cargo['id'], ['tipo' => 'procesando']),
            default => new ResultadoPago(EstadoResultado::Rechazado, $cargo['id'] !== '' ? $cargo['id'] : null, self::motivoRechazo($cargo['codigo'])),
        };
    }

    public function olvidarTarjeta(string $metodoExterno, array $llaves): void
    {
        if ($metodoExterno !== '' && ($llaves['secret_key'] ?? '') !== '') {
            self::api($llaves)->desligarMetodo($metodoExterno);
        }
    }

    /**
     * El cliente de la persona en la cuenta Stripe del negocio; se crea la primera vez.
     */
    private static function cliente(PersonaTenant $persona, ClienteStripe $api): string
    {
        $existente = ClientePasarelaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('proveedor', 'stripe')
            ->value('cliente_externo');
        if (is_string($existente) && $existente !== '') {
            return $existente;
        }

        $id = $api->crearCliente(
            $persona->nombreCompleto(),
            $persona->email,
            'cliente_'.$persona->ulid,
            ['persona' => (string) $persona->ulid],
        );
        ClientePasarelaTenant::query()->updateOrCreate(
            ['persona_id' => $persona->getKey(), 'proveedor' => 'stripe'],
            ['cliente_externo' => $id],
        );

        return $id;
    }

    /**
     * @param  array<string, string>  $llaves
     */
    private static function api(array $llaves): ClienteStripe
    {
        $secretKey = $llaves['secret_key'] ?? '';
        if ($secretKey === '') {
            throw new PasarelaNoDisponible('Stripe no tiene llaves configuradas.');
        }

        return new ClienteStripe($secretKey);
    }

    /**
     * Por qué el banco no aceptó el cargo automático, en palabras del cliente.
     */
    private static function motivoRechazo(?string $codigo): string
    {
        return match ($codigo) {
            'authentication_required' => 'Tu banco pidió confirmar el pago; págalo desde tu cuenta.',
            'insufficient_funds' => 'La tarjeta no tiene fondos suficientes.',
            'expired_card' => 'La tarjeta venció.',
            'lost_card', 'stolen_card', 'pickup_card', 'restricted_card' => 'La tarjeta ya no es válida.',
            'resource_missing' => 'La tarjeta guardada ya no está disponible.',
            default => 'El banco rechazó el cargo.',
        };
    }
}
