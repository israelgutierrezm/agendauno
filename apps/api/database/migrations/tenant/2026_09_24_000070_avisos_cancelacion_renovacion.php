<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Avisos que faltaban: al alumno cuando se cancela su reserva o la clase (o cita)
| completa, y el aviso de renovación próxima de su membresía (una vez por periodo:
| `acuerdos.aviso_renovacion_para` guarda la fecha de renovación ya avisada). Los
| correos por defecto llegan activos y editables en Comunicación → Automáticos.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    private const PLANTILLAS = [
        'reserva.cancelada' => [
            'asunto' => 'Cancelada: {{actividad}} del {{fecha}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nTu reserva de {{actividad}} del {{fecha}} a las {{hora}} en {{sucursal}} quedó cancelada. {{credito}}\n\n{{negocio}}",
        ],
        'reserva.sesion_cancelada' => [
            'asunto' => 'Se canceló {{actividad}} del {{fecha}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nLamentamos avisarte que se canceló {{actividad}} del {{fecha}} a las {{hora}} en {{sucursal}}. {{credito}}\n\nPuedes reservar otro horario desde tu cuenta:\n{{enlace}}\n\n{{negocio}}",
        ],
        'membresia.renovacion_proxima' => [
            'asunto' => 'Tu {{producto}} se renueva el {{fecha}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nTu {{producto}} se renueva el {{fecha}} por {{monto}}. {{como_pagar}}\n\nTu cuenta:\n{{enlace}}\n\n{{negocio}}",
        ],
    ];

    public function up(): void
    {
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $tabla): void {
            $tabla->date('aviso_renovacion_para')->nullable();
        });

        $ahora = now();
        foreach (self::PLANTILLAS as $clave => $texto) {
            DB::connection('tenant')->table('plantillas_mensaje')->insertOrIgnore([
                'ulid' => strtolower((string) Str::ulid()),
                'clave' => $clave,
                'canal' => 'email',
                'asunto' => $texto['asunto'],
                'cuerpo' => $texto['cuerpo'],
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('tenant')->table('plantillas_mensaje')
            ->whereIn('clave', array_keys(self::PLANTILLAS))
            ->delete();

        Schema::connection('tenant')->table('acuerdos', function (Blueprint $tabla): void {
            $tabla->dropColumn('aviso_renovacion_para');
        });
    }
};
