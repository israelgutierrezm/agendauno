<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Por dónde llegó una solicitud de un formulario: la página del negocio, su widget, un
| enlace o QR, o un formulario incrustado en otro sitio. Solo las de la página y el
| widget pasan por una visita medida, así que solo esas se comparan con las visitas en
| el embudo. Vacío = anterior a esto (o registrada por el equipo).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('prospectos', function (Blueprint $tabla): void {
            $tabla->string('canal', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('prospectos', function (Blueprint $tabla): void {
            $tabla->dropColumn('canal');
        });
    }
};
