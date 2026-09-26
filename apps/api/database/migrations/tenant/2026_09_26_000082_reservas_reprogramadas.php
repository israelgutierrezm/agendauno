<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Reprogramar (fase 2, punto 2.1): aviso de cambio de horario al cliente (correo y
| push) y al profesional de la cita (push), con el horario anterior y el nuevo.
| Llegan activos y se editan en Comunicación → Automáticos.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    /** @var list<array{canal: string, destinatario: string, asunto: string, cuerpo: string}> */
    private const PLANTILLAS = [
        [
            'canal' => 'email', 'destinatario' => 'persona',
            'asunto' => 'Cambio de horario: {{actividad}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nTu {{actividad}} cambió de horario.\n\nAntes: {{antes_fecha}} a las {{antes_hora}}\nAhora: {{fecha}} a las {{hora}} en {{sucursal}}\n\nSi no puedes asistir, avísanos para liberar tu lugar.\n\n{{negocio}}",
        ],
        [
            'canal' => 'push', 'destinatario' => 'persona',
            'asunto' => 'Cambio de horario: {{actividad}}',
            'cuerpo' => 'Ahora es el {{fecha}} a las {{hora}} (antes {{antes_fecha}} a las {{antes_hora}}).',
        ],
        [
            'canal' => 'push', 'destinatario' => 'profesional',
            'asunto' => 'Cita movida: {{persona_nombre}}',
            'cuerpo' => '{{actividad}} · ahora {{fecha}} a las {{hora}} (antes {{antes_fecha}} a las {{antes_hora}})',
        ],
    ];

    public function up(): void
    {
        $ahora = now();
        foreach (self::PLANTILLAS as $plantilla) {
            DB::connection('tenant')->table('plantillas_mensaje')->insertOrIgnore([
                'ulid' => strtolower((string) Str::ulid()),
                'clave' => 'reserva.reprogramada',
                'canal' => $plantilla['canal'],
                'destinatario' => $plantilla['destinatario'],
                'asunto' => $plantilla['asunto'],
                'cuerpo' => $plantilla['cuerpo'],
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('tenant')->table('plantillas_mensaje')->where('clave', 'reserva.reprogramada')->delete();
    }
};
