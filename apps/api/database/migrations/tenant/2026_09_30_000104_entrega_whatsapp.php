<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Estados de entrega de WhatsApp (ADR 0074): cuándo le llegó y cuándo lo leyó la
| persona, según el aviso de Meta. Se ven en la bandeja de salida del negocio.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('mensajes', function (Blueprint $tabla): void {
            $tabla->timestamp('entregado_en')->nullable();
            $tabla->timestamp('leido_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('mensajes', function (Blueprint $tabla): void {
            $tabla->dropColumn(['entregado_en', 'leido_en']);
        });
    }
};
