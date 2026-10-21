<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Application\ReciboRentaPdf;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaPlataforma;
use App\Modules\Tenancy\Pasarelas\OpenPay\ClienteOpenPay;

/**
 * Cobro de la renta del SaaS con OpenPay usando las llaves de LA PLATAFORMA (ADR 0192):
 * un cargo con tarjeta en la página de OpenPay (3D Secure si el banco lo pide). OpenPay
 * no admite dos cargos con el mismo `order_id`: cada intento lleva el cargo y un sufijo
 * (`{cargo}-{intento}`). El aviso solo trae el id; el cargo se relee en la API antes
 * de confirmar.
 */
class PasarelaOpenPayPlataforma implements PasarelaPlataforma
{
    public function nombre(): string
    {
        return 'openpay';
    }

    public function cobrar(CargoRenta $cargo, array $llaves): ResultadoPago
    {
        $retorno = RetornoPago::urls($cargo->retorno ?? '/renta');
        $cargo->loadMissing('estudio');
        $estudio = $cargo->estudio;

        $intento = self::api($llaves)->crearCargoConPagina(
            $cargo->monto_minor,
            $cargo->moneda,
            ReciboRentaPdf::concepto($cargo),
            $cargo->ulid.'-'.now()->format('ymdHis'),
            [
                'name' => mb_substr((string) $estudio->nombre, 0, 100),
                'email' => (string) $estudio->contacto_email,
            ],
            $retorno['exito'],
        );

        return ResultadoPago::pendiente($intento['id'], [
            'tipo' => 'redirect',
            'url' => $intento['url'],
        ]);
    }

    /**
     * El cargo de OpenPay: su estado (`completed`, `in_progress`, `failed`…), su monto y
     * de qué cargo de renta es (el `order_id` sin el sufijo del intento).
     *
     * @param  array<string, string>  $llaves
     * @return array{estado: string, monto_minor: int, cargo_renta: string}
     */
    public function cargo(string $id, array $llaves): array
    {
        $cargo = self::api($llaves)->cargo($id);

        return [
            'estado' => (string) ($cargo['status'] ?? ''),
            'monto_minor' => MontoDecimal::aCentavos((float) ($cargo['amount'] ?? 0)),
            'cargo_renta' => (string) preg_replace('/-\d{12}$/', '', (string) ($cargo['order_id'] ?? '')),
        ];
    }

    /**
     * @param  array<string, string>  $llaves
     */
    public static function api(array $llaves): ClienteOpenPay
    {
        $comercio = $llaves['merchant_id'] ?? '';
        $llave = $llaves['private_key'] ?? '';
        if ($comercio === '' || $llave === '') {
            throw new PasarelaNoDisponible('OpenPay no tiene llaves configuradas.');
        }
        $modo = ConfiguracionPasarelaPlataforma::query()->where('proveedor', 'openpay')->value('modo');

        return new ClienteOpenPay($comercio, $llave, $modo !== 'live', (string) (request()->ip() ?? '127.0.0.1'));
    }
}
