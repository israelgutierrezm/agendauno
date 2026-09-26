<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Parámetros del negocio (ADR 0042): el valor que el administrador ajustó para un
| límite o dato configurable (ventanas de pago, recordatorios, gracias de cobro…).
| Sin fila, aplica el de la plataforma.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('parametros_negocio', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 80)->unique();
            $tabla->integer('valor');
            $tabla->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('parametros_negocio');
    }
};
