<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\FuncionNoIncluida;
use App\Modules\Tenancy\Models\Estudio;

/**
 * Qué funciones tiene un negocio de citas según su nivel (ADR 0107). Lo decide el
 * servidor; la web y la app solo ocultan lo que aquí se niega.
 *
 * - Individual: agenda y citas, app, recordatorios, página con URL propia, agendar
 *   sin cuenta, pagar al agendar y cobro en caja, clientes, reseñas, reportes básicos.
 * - Premium suma: equipo, varias sucursales, cabinas y recursos, paquetes y
 *   membresías, mostrador e inventario, comisiones, promociones, documentos.
 * - Pro suma: facturación (timbres), cobro automático de membresías, venta en línea
 *   de paquetes, formularios, lealtad, mensajes masivos y WhatsApp, integraciones y
 *   API, roles propios, reportes avanzados.
 *
 * Los negocios de clases, los de cuota fija y los que aún tienen una tarifa anterior
 * tienen todas. En la prueba gratis, las de Pro.
 */
class FuncionesPlan
{
    /** El nivel mínimo de cada función que no está en todos. */
    public const NIVEL_MINIMO = [
        'equipo' => 'premium',
        'sucursales' => 'premium',
        'recursos' => 'premium',
        'paquetes' => 'premium',
        'inventario' => 'premium',
        'comisiones' => 'premium',
        'promociones' => 'premium',
        'documentos' => 'premium',
        'facturacion' => 'pro',
        'cobro_automatico' => 'pro',
        'venta_en_linea' => 'pro',
        'formularios' => 'pro',
        'lealtad' => 'pro',
        'mensajes' => 'pro',
        'integraciones' => 'pro',
        'roles_propios' => 'pro',
        'reportes_avanzados' => 'pro',
    ];

    private const ORDEN = ['individual' => 1, 'premium' => 2, 'pro' => 3];

    public function __construct(private readonly PlanCitasSaas $planes) {}

    /**
     * El nivel con que se deciden sus funciones; null si las tiene todas.
     */
    public function nivel(Estudio $estudio): ?string
    {
        if (! $this->planes->aplica($estudio)) {
            return null;
        }
        if ($this->planes->enPrueba($estudio)) {
            return 'pro';
        }
        $nivel = (string) $estudio->plan_nivel;

        // Recién terminada la prueba y antes de su primer cobro: aún con las de Pro.
        return isset(self::ORDEN[$nivel]) ? $nivel : 'pro';
    }

    public function tiene(Estudio $estudio, string $funcion): bool
    {
        $nivel = $this->nivel($estudio);
        $minimo = self::NIVEL_MINIMO[$funcion] ?? null;

        return $nivel === null || $minimo === null || self::ORDEN[$nivel] >= self::ORDEN[$minimo];
    }

    /**
     * Las funciones que NO tiene (para que la web y la app las oculten).
     *
     * @return list<string>
     */
    public function faltantes(Estudio $estudio): array
    {
        return array_values(array_filter(array_keys(self::NIVEL_MINIMO), fn (string $f): bool => ! $this->tiene($estudio, $f)));
    }

    /**
     * @throws FuncionNoIncluida
     */
    public function exigir(Estudio $estudio, string $funcion): void
    {
        if (! $this->tiene($estudio, $funcion)) {
            throw new FuncionNoIncluida($funcion, self::NIVEL_MINIMO[$funcion] ?? 'pro');
        }
    }
}
