<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Contracargos (ADR 0168): el banco del cliente disputa un pago en línea. El pago guarda
| en qué va (abierto, ganado o perdido), con la referencia de la disputa en la pasarela,
| su monto, su motivo, hasta cuándo se puede responder y cuándo se abrió y se cerró.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('pagos', function (Blueprint $t): void {
            $t->string('contracargo_estado', 12)->nullable();
            $t->string('contracargo_referencia', 80)->nullable()->index();
            $t->unsignedBigInteger('contracargo_monto_minor')->nullable();
            $t->string('contracargo_motivo', 120)->nullable();
            $t->timestamp('contracargo_vence_en')->nullable();
            $t->timestamp('contracargo_en')->nullable();
            $t->timestamp('contracargo_cerrado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('pagos', function (Blueprint $t): void {
            $t->dropIndex(['contracargo_referencia']);
            $t->dropColumn([
                'contracargo_estado', 'contracargo_referencia', 'contracargo_monto_minor', 'contracargo_motivo',
                'contracargo_vence_en', 'contracargo_en', 'contracargo_cerrado_en',
            ]);
        });
    }
};
