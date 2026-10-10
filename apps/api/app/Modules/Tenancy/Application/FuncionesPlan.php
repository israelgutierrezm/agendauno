<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
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
 *
 * Qué nivel abre cada función lo fija el superadmin en la tarifa de citas
 * (`definicion.funciones`, versionada); lo que no fija toma el reparto de siempre
 * (`NIVEL_MINIMO`).
 */
class FuncionesPlan
{
    /** El nivel mínimo de cada función, por omisión (la tarifa puede cambiarlo). */
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

    public const ORDEN = ['individual' => 1, 'premium' => 2, 'pro' => 3];

    public function __construct(
        private readonly PlanCitasSaas $planes,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

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

    /**
     * El nivel que abre cada función: el de la tarifa de citas vigente o, si no lo
     * dice, el de siempre.
     *
     * @param  array<string, mixed>|null  $definicion
     * @return array<string, string>
     */
    public static function mapa(?array $definicion): array
    {
        $mapa = self::NIVEL_MINIMO;
        $tarifa = is_array($definicion['funciones'] ?? null) ? $definicion['funciones'] : [];
        foreach ($tarifa as $funcion => $nivel) {
            if (isset($mapa[$funcion]) && is_string($nivel) && isset(self::ORDEN[$nivel])) {
                $mapa[$funcion] = $nivel;
            }
        }

        return $mapa;
    }

    public function tiene(Estudio $estudio, string $funcion): bool
    {
        $nivel = $this->nivel($estudio);
        if ($nivel === null) {
            return true;
        }

        return self::incluye($nivel, self::mapa($this->planes->tarifa()?->definicion), $funcion);
    }

    /**
     * En segundo plano (relay del outbox, cobros programados): ¿el negocio conectado
     * tiene la función? Sin negocio conectado no se niega nada.
     */
    public function tieneElNegocioActual(string $funcion): bool
    {
        $estudio = $this->gestor->actual();

        return ! $estudio instanceof Estudio || $this->tiene($estudio, $funcion);
    }

    /**
     * Las funciones que NO tiene (para que la web y la app las oculten). El nivel y la
     * tarifa se leen una sola vez para todas.
     *
     * @return list<string>
     */
    public function faltantes(Estudio $estudio): array
    {
        $nivel = $this->nivel($estudio);
        if ($nivel === null) {
            return [];
        }
        $mapa = self::mapa($this->planes->tarifa()?->definicion);

        return array_values(array_filter(array_keys(self::NIVEL_MINIMO), static fn (string $f): bool => ! self::incluye($nivel, $mapa, $f)));
    }

    /**
     * @param  array<string, string>  $mapa  el nivel que abre cada función
     */
    private static function incluye(string $nivel, array $mapa, string $funcion): bool
    {
        $minimo = $mapa[$funcion] ?? null;

        return $minimo === null || self::ORDEN[$nivel] >= self::ORDEN[$minimo];
    }

    /**
     * @throws FuncionNoIncluida
     */
    public function exigir(Estudio $estudio, string $funcion): void
    {
        if (! $this->tiene($estudio, $funcion)) {
            throw new FuncionNoIncluida($funcion, self::mapa($this->planes->tarifa()?->definicion)[$funcion] ?? 'pro');
        }
    }
}
