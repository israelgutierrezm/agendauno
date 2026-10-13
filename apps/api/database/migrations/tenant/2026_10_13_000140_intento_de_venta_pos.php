<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Mostrador: cada cobro lleva la clave de su intento. Si la respuesta se pierde y el
| mostrador lo reintenta, se devuelve la misma venta en vez de registrar (y descontar
| del inventario) otra.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->string('clave_intento', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->dropUnique(['clave_intento']);
            $tabla->dropColumn('clave_intento');
        });
    }
};
