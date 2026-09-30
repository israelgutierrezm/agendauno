<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Suspensión automática por renta vencida (ADR 0073).
| `suspendido_por`: `renta` (automática; al pagar se reactiva sola y el dueño aún
| puede entrar a pagar) o `plataforma` (la hizo el superadministrador).
| `suspendido_en`: cuándo. `sin_suspension_hasta`: si el superadministrador lo
| reactivó a mano, hasta cuándo no se vuelve a suspender solo.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->string('suspendido_por', 20)->nullable();
            $tabla->timestamp('suspendido_en')->nullable();
            $tabla->date('sin_suspension_hasta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn(['suspendido_por', 'suspendido_en', 'sin_suspension_hasta']);
        });
    }
};
