<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Embudo de la plataforma (plan de mejoras C02 y C08):
| - `estudios.publicado_en`: la primera vez que el negocio publicó su página.
| - `estudios.primera_operacion_en`: su primera operación real (la primera reserva o el
|   primer pago de un cliente que cuenta; ver RegistrarHitosNegocios).
| - `estudios.es_demo`: los negocios de demostración no cuentan en el embudo.
| - `visitas_landing`: cuántas visitas tuvo cada landing por día (sin datos personales).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->timestamp('publicado_en')->nullable()->after('publicado');
            $tabla->timestamp('primera_operacion_en')->nullable()->after('publicado_en');
            $tabla->boolean('es_demo')->default(false)->after('primera_operacion_en');
        });
        Schema::create('visitas_landing', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->date('fecha');
            $tabla->string('producto', 20);
            $tabla->unsignedInteger('visitas')->default(0);
            $tabla->timestamps();
            $tabla->unique(['fecha', 'producto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitas_landing');
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn(['publicado_en', 'primera_operacion_en', 'es_demo']);
        });
    }
};
