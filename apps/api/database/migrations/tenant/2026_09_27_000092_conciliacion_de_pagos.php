<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Conciliación de pagos cuyo aviso (webhook) no llegó: se vuelve a preguntar a la
| pasarela por los intentos pendientes y por los que se cerraron de este lado sin que
| la pasarela lo confirmara (pueden haberse cobrado después).
| - revisado_en: la última vez que se le preguntó (para espaciar las consultas).
| - cerrado_sin_confirmar: se cerró de nuestro lado; hasta que la pasarela diga que
|   ya no se puede pagar, se sigue preguntando.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('pagos', function (Blueprint $tabla): void {
            $tabla->timestamp('revisado_en')->nullable()->after('aprobado_en');
            $tabla->boolean('cerrado_sin_confirmar')->default(false)->after('revisado_en');
            $tabla->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('pagos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['estado', 'created_at']);
            $tabla->dropColumn(['revisado_en', 'cerrado_sin_confirmar']);
        });
    }
};
