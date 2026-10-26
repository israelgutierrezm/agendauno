<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La factura de la renta (ADR 0214): con qué forma de pago del SAT se timbró y, si se
| canceló (una renta devuelta o mal facturada), cómo va esa cancelación, con qué motivo
| del SAT y cuándo quedó.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas_plataforma', function (Blueprint $t): void {
            $t->string('forma_pago', 2)->nullable();
            $t->string('cancelacion_estado', 20)->nullable();
            $t->string('cancelacion_motivo', 2)->nullable();
            $t->timestamp('cancelada_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('facturas_plataforma', function (Blueprint $t): void {
            $t->dropColumn(['forma_pago', 'cancelacion_estado', 'cancelacion_motivo', 'cancelada_en']);
        });
    }
};
