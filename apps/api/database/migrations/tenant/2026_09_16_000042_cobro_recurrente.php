<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobro recurrente (Etapa 2): una membresía recurrente lleva su próxima fecha de
 * cobro (`proxima_cobro_en`); el scheduler la cobra al vencer. La orden de renovación
 * apunta al acuerdo que renueva (`renueva_acuerdo_id`) para que el fulfillment NO
 * cree un acuerdo nuevo (el entitlement lo mantiene el motor de ciclos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $tabla): void {
            $tabla->date('proxima_cobro_en')->nullable()->after('fecha_inicio');
        });

        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->foreignId('renueva_acuerdo_id')->nullable()->after('persona_id')
                ->constrained('acuerdos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('renueva_acuerdo_id');
        });
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $tabla): void {
            $tabla->dropColumn('proxima_cobro_en');
        });
    }
};
