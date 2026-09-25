<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Avisos al profesional de sus citas: cada mensaje automático dice a quién va
| (`destinatario`: la persona del evento o el profesional de la cita), y el mensaje
| guarda el usuario que lo recibe (`usuario_id`) además de la persona de quien trata
| (así la baja de datos de un cliente también borra los avisos que lo mencionan).
| Por defecto el profesional recibe por push "Nueva cita" y "Cita cancelada".
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    private const PLANTILLAS = [
        'reserva.confirmada' => [
            'asunto' => 'Nueva cita: {{persona_nombre}}',
            'cuerpo' => '{{actividad}} · {{fecha}} a las {{hora}} en {{sucursal}}',
        ],
        'reserva.cancelada' => [
            'asunto' => 'Cita cancelada: {{persona_nombre}}',
            'cuerpo' => '{{actividad}} · {{fecha}} a las {{hora}}. Ese horario quedó libre.',
        ],
    ];

    public function up(): void
    {
        Schema::connection('tenant')->table('plantillas_mensaje', function (Blueprint $tabla): void {
            $tabla->string('destinatario', 20)->default('persona');
        });
        Schema::connection('tenant')->table('plantillas_mensaje', function (Blueprint $tabla): void {
            $tabla->dropUnique(['clave', 'canal']);
            $tabla->unique(['clave', 'canal', 'destinatario']);
        });

        Schema::connection('tenant')->table('mensajes', function (Blueprint $tabla): void {
            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
        });

        $ahora = now();
        foreach (self::PLANTILLAS as $clave => $texto) {
            DB::connection('tenant')->table('plantillas_mensaje')->insertOrIgnore([
                'ulid' => strtolower((string) Str::ulid()),
                'clave' => $clave,
                'canal' => 'push',
                'destinatario' => 'profesional',
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
        DB::connection('tenant')->table('plantillas_mensaje')->where('destinatario', '!=', 'persona')->delete();

        Schema::connection('tenant')->table('mensajes', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('usuario_id');
        });
        Schema::connection('tenant')->table('plantillas_mensaje', function (Blueprint $tabla): void {
            $tabla->dropUnique(['clave', 'canal', 'destinatario']);
            $tabla->unique(['clave', 'canal']);
        });
        Schema::connection('tenant')->table('plantillas_mensaje', function (Blueprint $tabla): void {
            $tabla->dropColumn('destinatario');
        });
    }
};
