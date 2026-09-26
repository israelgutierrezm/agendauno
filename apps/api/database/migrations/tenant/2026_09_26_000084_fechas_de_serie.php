<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Cambios sobre clases recurrentes (fase 2, punto 2.5).
|
| - `fecha_serie`: a qué fecha de su serie pertenece una sesión (día local). La
|   generación reconoce una fecha ya generada por ella y no por su hora exacta, así una
|   sesión que se movió (de hora o de día) no se vuelve a crear en su lugar original.
| - `editada_en`: la sesión se cambió a mano (solo esa); un cambio a la serie la respeta.
|
| Lo anterior: la fecha de serie es el día local en que empieza cada sesión.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('sesiones', function (Blueprint $tabla): void {
            $tabla->date('fecha_serie')->nullable();
            $tabla->timestamp('editada_en')->nullable();
            $tabla->index(['serie_id', 'fecha_serie']);
        });

        DB::connection('tenant')->table('sesiones')
            ->whereNotNull('serie_id')
            ->orderBy('id')
            ->select(['id', 'inicia_en', 'zona_horaria'])
            ->chunkById(500, function ($sesiones): void {
                foreach ($sesiones as $s) {
                    $zona = is_string($s->zona_horaria) && $s->zona_horaria !== '' ? $s->zona_horaria : 'UTC';
                    DB::connection('tenant')->table('sesiones')->where('id', $s->id)->update([
                        'fecha_serie' => CarbonImmutable::parse((string) $s->inicia_en, 'UTC')->setTimezone($zona)->toDateString(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sesiones', function (Blueprint $tabla): void {
            $tabla->dropIndex(['serie_id', 'fecha_serie']);
            $tabla->dropColumn(['fecha_serie', 'editada_en']);
        });
    }
};
