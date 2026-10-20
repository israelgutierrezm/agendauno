<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Las cuotas que se exentaron (revisión de finanzas, 2026-10-20). Exentar quita la
| línea de la orden, y con ella la referencia que impedía generar otra vez la misma
| anualidad: aquí queda la referencia, y quien genera cuotas la respeta.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('cuotas_exentadas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('referencia', 120)->unique();
            $tabla->foreignId('orden_id')->nullable()->constrained('ordenes')->nullOnDelete();
            $tabla->string('motivo', 500)->nullable();
            $tabla->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cuotas_exentadas');
    }
};
