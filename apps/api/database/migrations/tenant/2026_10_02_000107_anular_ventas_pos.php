<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Anular una venta de mostrador registrada por error (ADR 0089): cuándo, quién y por
| qué. Una venta anulada no cuenta en el corte; lo vendido regresa al inventario.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->timestamp('anulada_en')->nullable();
            $tabla->unsignedBigInteger('anulada_por')->nullable();
            $tabla->string('motivo_anulacion', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->dropColumn(['anulada_en', 'anulada_por', 'motivo_anulacion']);
        });
    }
};
