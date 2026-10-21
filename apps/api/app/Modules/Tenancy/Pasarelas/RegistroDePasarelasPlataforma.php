<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaPlataforma;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;

/**
 * Resuelve la pasarela de LA PLATAFORMA por proveedor y lee su configuracion (activa
 * + llaves) desde el control plane. Sirve para cobrar la renta del SaaS al dueño.
 * El dueño siempre paga en línea: con Stripe, Mercado Pago u OpenPay (ADR 0192), las
 * que estén activas y con sus llaves. La domiciliación sigue solo con Stripe.
 * Espejo, a nivel plataforma, de {@see RegistroDePasarelasTenant}.
 */
class RegistroDePasarelasPlataforma
{
    /**
     * Orden de preferencia al elegir automaticamente una pasarela activa.
     */
    private const PRIORIDAD = ['stripe', 'mercadopago', 'openpay'];

    /** Las llaves sin las que cada una no cobra. */
    private const LLAVES = [
        'stripe' => ['secret_key'],
        'mercadopago' => ['access_token'],
        'openpay' => ['merchant_id', 'private_key'],
    ];

    public function __construct(
        private readonly PasarelaStripePlataforma $stripe,
        private readonly PasarelaMercadoPagoPlataforma $mercadoPago,
        private readonly PasarelaOpenPayPlataforma $openPay,
    ) {}

    public function resolver(string $proveedor): PasarelaPlataforma
    {
        return match ($proveedor) {
            'stripe' => $this->stripe,
            'mercadopago' => $this->mercadoPago,
            'openpay' => $this->openPay,
            default => throw new PasarelaNoDisponible('Esa pasarela aún no está disponible.'),
        };
    }

    /**
     * Las llaves que le faltan para cobrar.
     *
     * @param  array<string, string>  $llaves
     * @return list<string>
     */
    public static function llavesFaltantes(string $proveedor, array $llaves): array
    {
        return array_values(array_filter(self::LLAVES[$proveedor] ?? [], static fn (string $l): bool => trim($llaves[$l] ?? '') === ''));
    }

    /**
     * Las que pueden cobrar ahora, en orden de preferencia.
     *
     * @return list<string>
     */
    public function activas(): array
    {
        return array_values(array_filter(self::PRIORIDAD, fn (string $p): bool => $this->activa($p)));
    }

    /**
     * ¿El proveedor puede cobrar? Solo si esta activo en la configuracion global.
     */
    public function activa(string $proveedor): bool
    {
        if (! in_array($proveedor, ProveedorPasarela::implementadasPlataforma(), true)) {
            return false;
        }

        $config = ConfiguracionPasarelaPlataforma::query()
            ->where('proveedor', $proveedor)
            ->where('activa', true)
            ->first();

        return $config instanceof ConfiguracionPasarelaPlataforma
            && self::llavesFaltantes($proveedor, $config->llaves()) === [];
    }

    /**
     * Llaves (descifradas) del proveedor en la plataforma, o vacio.
     *
     * @return array<string, string>
     */
    public function llaves(string $proveedor): array
    {
        $config = ConfiguracionPasarelaPlataforma::query()->where('proveedor', $proveedor)->first();

        return $config instanceof ConfiguracionPasarelaPlataforma ? $config->llaves() : [];
    }

    /**
     * Primera pasarela activa por orden de preferencia (para elegir automaticamente
     * cuando el dueño no especifica proveedor), o `null` si ninguna esta activa.
     */
    public function primeraActiva(): ?string
    {
        foreach (self::PRIORIDAD as $proveedor) {
            if ($this->activa($proveedor)) {
                return $proveedor;
            }
        }

        return null;
    }
}
