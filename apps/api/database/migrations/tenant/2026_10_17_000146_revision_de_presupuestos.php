<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cada guardado de un presupuesto sube su revisión. Enviarlo o marcarlo aceptado
| puede decir qué revisión se confirmó: si alguien lo cambió después, no se envía ni
| se acepta otra versión de la que se vio.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('presupuestos', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('revision')->default(1);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('presupuestos', function (Blueprint $tabla): void {
            $tabla->dropColumn('revision');
        });
    }
};
