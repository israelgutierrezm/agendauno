<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Notificaciones push: los dispositivos (token de FCM) donde cada usuario tiene la app
| con sesión en este negocio, y los avisos por defecto del canal `push` (título corto
| en `asunto`, una línea en `cuerpo`), activos y editables en Comunicación →
| Automáticos. Solo se envían si la plataforma tiene FCM configurado.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    private const PLANTILLAS = [
        'reserva.confirmada' => [
            'asunto' => 'Reserva confirmada',
            'cuerpo' => '{{actividad}} · {{fecha}} a las {{hora}}',
        ],
        'reserva.ofrecida' => [
            'asunto' => '¡Se liberó un lugar!',
            'cuerpo' => '{{actividad}} · {{fecha}} a las {{hora}}. Acéptalo antes de que se ofrezca a alguien más.',
        ],
        'reserva.recordatorio_2h' => [
            'asunto' => 'Hoy a las {{hora}}: {{actividad}}',
            'cuerpo' => 'Te esperamos en {{sucursal}}.',
        ],
        'reserva.sesion_cancelada' => [
            'asunto' => 'Se canceló {{actividad}}',
            'cuerpo' => '{{fecha}} a las {{hora}}. {{credito}}',
        ],
        'cobro.fallido' => [
            'asunto' => 'No pudimos cobrar tu {{producto}}',
            'cuerpo' => 'Entra a tu cuenta para pagarla y seguir reservando.',
        ],
        'membresia.renovacion_proxima' => [
            'asunto' => 'Tu {{producto}} se renueva el {{fecha}}',
            'cuerpo' => '{{monto}}. {{como_pagar}}',
        ],
    ];

    public function up(): void
    {
        Schema::connection('tenant')->create('dispositivos_push', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            // Un token es de un solo usuario: si otro inicia sesión en ese teléfono, pasa a él.
            $tabla->string('token', 512)->unique();
            $tabla->string('plataforma', 10); // android | ios
            $tabla->timestamp('registrado_en');
            $tabla->timestamps();

            $tabla->index('usuario_id');
        });

        $ahora = now();
        foreach (self::PLANTILLAS as $clave => $texto) {
            DB::connection('tenant')->table('plantillas_mensaje')->insertOrIgnore([
                'ulid' => strtolower((string) Str::ulid()),
                'clave' => $clave,
                'canal' => 'push',
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
        DB::connection('tenant')->table('plantillas_mensaje')->where('canal', 'push')->delete();
        DB::connection('tenant')->table('mensajes')->where('canal', 'push')->delete();

        Schema::connection('tenant')->dropIfExists('dispositivos_push');
    }
};
