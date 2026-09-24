<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Pausas de una membresía o paquete (congelar): del día `desde` al `hasta` (último
| día en pausa). Al reanudar se guardan los días que se corrieron sus fechas
| (próximo cobro, ciclo y vencimiento). Historial: una fila por pausa.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('pausas_acuerdo', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('acuerdo_id')->constrained('acuerdos')->cascadeOnDelete();
            $tabla->date('desde');
            $tabla->date('hasta');
            $tabla->dateTime('reanudada_en')->nullable();
            $tabla->unsignedInteger('dias')->nullable();
            $tabla->string('motivo')->nullable();
            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamps();

            $tabla->index(['acuerdo_id', 'reanudada_en']);
            $tabla->index(['reanudada_en', 'hasta']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('pausas_acuerdo');
    }
};
