<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Check-ins de Wellhub y TotalPass que se pueden verificar (plan de mejoras M10): una
 * visita que la plataforma no pudo verificar (sin conexión, error de su lado o
 * credenciales rechazadas) queda «no_verificable», sin referencia del proveedor y sin
 * contar como aprobada; se reintenta con el mismo código (su huella, nunca el código)
 * o quien tiene permiso la registra como «excepcion» con su motivo y lo que comprobó.
 * Cada registro guarda quién lo hizo y quién autorizó la excepción.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('checkins', function (Blueprint $tabla): void {
            // La referencia del proveedor solo existe cuando él la aprobó.
            $tabla->string('referencia_externa')->nullable()->change();
            $tabla->string('codigo_huella', 64)->nullable();
            $tabla->string('motivo', 300)->nullable();
            $tabla->unsignedInteger('intentos')->default(1);
            $tabla->unsignedBigInteger('registrado_por_id')->nullable();
            $tabla->unsignedBigInteger('autorizado_por_id')->nullable();
            $tabla->timestamp('autorizado_en')->nullable();
            $tabla->string('motivo_excepcion', 500)->nullable();
            $tabla->text('evidencia')->nullable();

            $tabla->index(['sesion_id', 'proveedor', 'codigo_huella']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('checkins', function (Blueprint $tabla): void {
            $tabla->dropIndex(['sesion_id', 'proveedor', 'codigo_huella']);
            $tabla->dropColumn([
                'codigo_huella', 'motivo', 'intentos', 'registrado_por_id', 'autorizado_por_id',
                'autorizado_en', 'motivo_excepcion', 'evidencia',
            ]);
        });
    }
};
