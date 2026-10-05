<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El día del mes al que vuelve el cobro de un acuerdo (y, por aniversario, sus ciclos
 * de créditos): el de su inicio; en un plan de calendario, el 1. Con él, un plan que
 * empezó el 31 vuelve al 31 después de febrero en vez de quedarse en el 28. Una pausa
 * lo corre con las fechas.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $tabla): void {
            $tabla->unsignedTinyInteger('dia_ancla')->nullable()->after('proxima_cobro_en');
        });

        // Los que ya existen: el día de su próximo cobro (respeta las pausas que lo
        // corrieron), salvo un aniversario del 29 al 31, que el cálculo anterior pudo
        // desbordar: ese vuelve al día de su inicio. Sin próximo cobro: el de su inicio
        // (de calendario, el 1).
        $calendario = DB::connection('tenant')->table('productos_comerciales')
            ->where('politica_reset', 'calendario')->pluck('id')->flip();
        DB::connection('tenant')->table('acuerdos')->whereNull('dia_ancla')->orderBy('id')
            ->chunkById(500, function ($filas) use ($calendario): void {
                foreach ($filas as $fila) {
                    $esCalendario = $calendario->has($fila->producto_comercial_id);
                    $diaInicio = (int) substr((string) $fila->fecha_inicio, 8, 2);
                    $diaCobro = $fila->proxima_cobro_en !== null ? (int) substr((string) $fila->proxima_cobro_en, 8, 2) : null;
                    $dia = $diaCobro !== null && ($esCalendario || $diaInicio <= 28)
                        ? $diaCobro
                        : ($esCalendario ? 1 : $diaInicio);
                    DB::connection('tenant')->table('acuerdos')->where('id', $fila->id)->update(['dia_ancla' => $dia]);
                }
            });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $tabla): void {
            $tabla->dropColumn('dia_ancla');
        });
    }
};
