<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Tarifas de pago del equipo con versiones (ADR 0153): cambiar el esquema de alguien
| no recalcula lo que ya trabajó. Cada versión vale desde un día del negocio
| (`vigente_desde`; null = desde siempre) y cada sesión se paga con la de su día. Las
| que ya existían quedan como su primera versión, desde siempre.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('esquemas_pago_versiones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $tabla->string('tipo'); // por_clase | por_asistente | por_hora
            $tabla->bigInteger('monto_minor');
            $tabla->char('moneda', 3);
            $tabla->date('vigente_desde')->nullable();
            $tabla->timestamps();
            $tabla->unique(['usuario_id', 'vigente_desde']);
        });

        $ahora = now();
        foreach (DB::connection('tenant')->table('esquemas_pago')->get() as $esquema) {
            DB::connection('tenant')->table('esquemas_pago_versiones')->insert([
                'usuario_id' => $esquema->usuario_id,
                'tipo' => $esquema->tipo,
                'monto_minor' => $esquema->monto_minor,
                'moneda' => $esquema->moneda,
                'vigente_desde' => null,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('esquemas_pago_versiones');
    }
};
