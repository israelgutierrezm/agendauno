<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaPlataforma;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;

/**
 * Resuelve la pasarela de LA PLATAFORMA por proveedor y lee su configuracion (activa
 * + llaves) desde el control plane. Sirve para cobrar la renta del SaaS al dueño.
 * El dueño siempre paga en línea; hoy solo con Stripe (activa y con llave secreta).
 * OpenPay y Mercado Pago aún no tienen integración completa: no se pueden usar.
 * Espejo, a nivel plataforma, de {@see RegistroDePasarelasTenant}.
 */
class RegistroDePasarelasPlataforma
{
    /**
     * Orden de preferencia al elegir automaticamente una pasarela activa.
     */
    private const PRIORIDAD = ['stripe', 'mercadopago', 'openpay'];

    public function __construct(private readonly PasarelaStripePlataforma $stripe) {}

    public function resolver(string $proveedor): PasarelaPlataforma
    {
        return match ($proveedor) {
            'stripe' => $this->stripe,
            default => throw new PasarelaNoDisponible('Esa pasarela aún no está disponible.'),
        };
    }

    /**
     * ¿El proveedor puede cobrar? Solo si esta activo en la configuracion global.
     */
    public function activa(string $proveedor): bool
    {
        if (! in_array($proveedor, ProveedorPasarela::implementadas(), true)) {
            return false;
        }

        $config = ConfiguracionPasarelaPlataforma::query()
            ->where('proveedor', $proveedor)
            ->where('activa', true)
            ->first();

        return $config instanceof ConfiguracionPasarelaPlataforma
            && ($proveedor !== 'stripe' || ($config->llaves()['secret_key'] ?? '') !== '');
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
