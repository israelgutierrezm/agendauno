<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Por qué no se cobró una membresía, para quien administra: la clave del motivo
| (MotivoFalloCobro) y su detalle (el motivo del banco, o el texto que escribió el
| equipo al registrar el fallo a mano, que va sin clave). `ultimo_motivo` sigue siendo
| la frase que se le manda al alumno.
|
| Relleno: los procesos con una de las frases de siempre toman su clave; el rechazo del
| pago automático guarda como detalle el motivo del banco (lo que va después de «: »), y
| lo que escribió el equipo a mano queda como detalle, sin clave.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Las frases de siempre al alumno y su clave.
     *
     * @var array<string, string>
     */
    private const FRASES = [
        'No pudimos cobrar en línea. Puedes pagar desde tu cuenta o en el negocio.' => 'sin_cobro_en_linea',
        'Falta completar el pago en línea.' => 'pago_sin_completar',
        'El cobro fue rechazado.' => 'rechazado',
        'No hemos recibido el cobro automático de tu suscripción.' => 'suscripcion_sin_cobro',
    ];

    /** El rechazo del pago automático empieza así (con la tarjeta o sin ella). */
    private const RECHAZO_AUTOMATICO = 'No pudimos cobrar tu pago automático';

    /** Lo que se guardaba cuando el equipo registraba el fallo sin escribir nada. */
    private const MANUAL_SIN_TEXTO = 'Cobro rechazado';

    public function up(): void
    {
        Schema::connection('tenant')->table('procesos_dunning', function (Blueprint $t): void {
            $t->string('ultimo_motivo_clave', 40)->nullable();
            $t->string('ultimo_motivo_detalle', 255)->nullable();
        });

        foreach (self::FRASES as $frase => $clave) {
            DB::connection('tenant')->table('procesos_dunning')
                ->where('ultimo_motivo', $frase)
                ->update(['ultimo_motivo_clave' => $clave]);
        }

        DB::connection('tenant')->table('procesos_dunning')
            ->whereNull('ultimo_motivo_clave')
            ->where('ultimo_motivo', 'like', self::RECHAZO_AUTOMATICO.'%')
            ->orderBy('id')
            ->get(['id', 'ultimo_motivo'])
            ->each(function (object $fila): void {
                $motivo = (string) $fila->ultimo_motivo;
                $separador = mb_strpos($motivo, ': ');
                $detalle = $separador !== false ? trim(mb_substr($motivo, $separador + 2)) : '';

                DB::connection('tenant')->table('procesos_dunning')->where('id', $fila->id)->update([
                    'ultimo_motivo_clave' => 'tarjeta_rechazada',
                    'ultimo_motivo_detalle' => $detalle !== '' ? mb_substr($detalle, 0, 255) : null,
                ]);
            });

        DB::connection('tenant')->table('procesos_dunning')
            ->whereNull('ultimo_motivo_clave')
            ->whereNotNull('ultimo_motivo')
            ->where('ultimo_motivo', '!=', self::MANUAL_SIN_TEXTO)
            ->update(['ultimo_motivo_detalle' => DB::raw('ultimo_motivo')]);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('procesos_dunning', function (Blueprint $t): void {
            $t->dropColumn(['ultimo_motivo_clave', 'ultimo_motivo_detalle']);
        });
    }
};
