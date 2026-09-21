<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): POLÍTICA DE RESERVA por oferta (citas). `entitlement`
 * (consume membresía/créditos, comportamiento actual, default) o `pago`
 * (pago-para-reservar: exige pago en línea por la sesión antes de confirmar). El precio
 * de la reserva de pago reutiliza `ofertas.precio_clase_minor` (ya existente). Default
 * `entitlement` para no cambiar el comportamiento de las ofertas ya creadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->string('politica_reserva')->default('entitlement');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->dropColumn('politica_reserva');
        });
    }
};
