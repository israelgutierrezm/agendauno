<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Quién movió el dinero: `pagos.registrado_por` (quién registró el cobro: en caja, o
| el propio alumno al pagar en línea; vacío = pago automático o cliente sin cuenta)
| y cuándo y quién canceló una compra. Base del corte por fecha y por usuario.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('pagos', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('registrado_por')->nullable();
            $tabla->index(['registrado_por', 'created_at']);
        });

        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->timestamp('cancelada_en')->nullable();
            $tabla->unsignedBigInteger('cancelada_por')->nullable();
            $tabla->index('cancelada_en');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->dropIndex(['cancelada_en']);
            $tabla->dropColumn(['cancelada_en', 'cancelada_por']);
        });

        Schema::connection('tenant')->table('pagos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['registrado_por', 'created_at']);
            $tabla->dropColumn('registrado_por');
        });
    }
};
