<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cancelar, sustituir y notas de crédito (ADR 0180). Una factura es de ingreso (`I`) o
| una nota de crédito (`E`, egreso) relacionada con la que corrige (`01`); la que
| sustituye a otra se relaciona con ella (`04`). La cancelación lleva su estado ante el
| SAT (en espera del cliente, aceptada, rechazada o vencida). Se guardan el régimen del
| receptor, la forma de pago y los conceptos con que se timbró: la nota de crédito y la
| sustituta los necesitan.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('facturas', function (Blueprint $tabla): void {
            $tabla->string('tipo', 1)->default('I');
            $tabla->foreignId('relacionada_id')->nullable()->constrained('facturas')->nullOnDelete();
            $tabla->string('relacion', 2)->nullable();
            $tabla->string('receptor_regimen', 4)->nullable();
            $tabla->string('forma_pago', 2)->nullable();
            $tabla->json('conceptos')->nullable();
            $tabla->string('cancelacion_estado', 20)->nullable()->index();
            $tabla->string('cancelacion_motivo', 2)->nullable();
            $tabla->string('cancelacion_detalle', 500)->nullable();
            $tabla->timestamp('cancelacion_solicitada_en')->nullable();
            $tabla->timestamp('cancelada_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('facturas', function (Blueprint $tabla): void {
            $tabla->dropIndex(['cancelacion_estado']);
            $tabla->dropConstrainedForeignId('relacionada_id');
            $tabla->dropColumn([
                'tipo', 'relacion', 'receptor_regimen', 'forma_pago', 'conceptos', 'cancelacion_estado',
                'cancelacion_motivo', 'cancelacion_detalle', 'cancelacion_solicitada_en', 'cancelada_en',
            ]);
        });
    }
};
