<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Un servicio o clase que se retira del catálogo (ADR 0154): ya no se agenda, no se
| vende ni aparece en línea, pero su historial (citas, clases, reservas, ventas)
| sigue apuntando a él. Vacío = se ofrece.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->timestamp('archivado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->dropColumn('archivado_en');
        });
    }
};
