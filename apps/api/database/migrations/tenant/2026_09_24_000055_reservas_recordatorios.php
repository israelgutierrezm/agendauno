<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Recordatorios de clases y citas: cuándo se emitió el aviso de 24 h y el de 2 h de
| cada reserva (uno por aviso), y los correos por defecto para ambos, activos y
| editables en Comunicación → Automáticos.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    private const PLANTILLAS = [
        'reserva.recordatorio_24h' => [
            'asunto' => 'Recordatorio: {{actividad}} el {{fecha}} a las {{hora}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nTe recordamos tu {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}}.\n\nSi no puedes asistir, avísanos para liberar tu lugar.\n\n{{negocio}}",
        ],
        'reserva.recordatorio_2h' => [
            'asunto' => 'Hoy a las {{hora}}: {{actividad}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nTe esperamos hoy a las {{hora}} en {{sucursal}} para tu {{actividad}}.\n\n{{negocio}}",
        ],
    ];

    public function up(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->timestamp('recordatorio_24h_en')->nullable();
            $tabla->timestamp('recordatorio_2h_en')->nullable();
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

        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropColumn(['recordatorio_24h_en', 'recordatorio_2h_en']);
        });
    }
};
