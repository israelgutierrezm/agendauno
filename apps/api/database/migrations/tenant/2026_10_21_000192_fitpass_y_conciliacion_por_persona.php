<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fitpass sin API y la conciliación por persona (ADR 0202):
|
| - `lugares_plataforma.marcado_en_plataforma_en`: recepción ya marcó la asistencia (o
|   la inasistencia) en el panel de la plataforma. Fitpass la acepta hasta 48 horas
|   después de la clase; de eso depende su pago.
| - `tarifas_plataforma.paga_inasistencias`: según su contrato, la plataforma también
|   paga las reservas que no se cancelaron a tiempo (no llegó o canceló tarde).
| - `pagos_plataforma.lugar_id`: el pago de una de esas reservas.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('lugares_plataforma', function (Blueprint $t): void {
            $t->timestamp('marcado_en_plataforma_en')->nullable();
        });
        Schema::connection('tenant')->table('tarifas_plataforma', function (Blueprint $t): void {
            $t->boolean('paga_inasistencias')->default(false);
        });
        Schema::connection('tenant')->table('pagos_plataforma', function (Blueprint $t): void {
            $t->unsignedBigInteger('lugar_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('pagos_plataforma', function (Blueprint $t): void {
            $t->dropColumn('lugar_id');
        });
        Schema::connection('tenant')->table('tarifas_plataforma', function (Blueprint $t): void {
            $t->dropColumn('paga_inasistencias');
        });
        Schema::connection('tenant')->table('lugares_plataforma', function (Blueprint $t): void {
            $t->dropColumn('marcado_en_plataforma_en');
        });
    }
};
