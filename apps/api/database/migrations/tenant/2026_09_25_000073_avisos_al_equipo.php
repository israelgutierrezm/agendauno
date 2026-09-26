<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Avisos al equipo del negocio (destinatario `equipo`: a quien tiene el permiso con el
| que se atiende lo que pasó). Llegan activos los que piden acción: una solicitud de
| baja de datos (tiene plazo legal) y una reseña nueva. Los demás llegan apagados,
| listos para activarse en Comunicación → Automáticos.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * @var list<array{clave: string, canal: string, activo: bool, asunto: string, cuerpo: string}>
     */
    private const PLANTILLAS = [
        [
            'clave' => 'privacidad.baja_solicitada', 'canal' => 'email', 'activo' => true,
            'asunto' => 'Solicitud de baja de datos: {{persona_nombre}}',
            'cuerpo' => "{{persona_nombre}} pidió la baja de sus datos personales.\n\nLa ley te da 20 días hábiles para responder. Atiéndela o recházala con motivo en Privacidad:\n{{enlace_panel}}\n\n{{negocio}}",
        ],
        [
            'clave' => 'privacidad.baja_solicitada', 'canal' => 'push', 'activo' => true,
            'asunto' => 'Solicitud de baja de datos',
            'cuerpo' => '{{persona_nombre}} pidió la baja de sus datos. Atiéndela en Privacidad.',
        ],
        [
            'clave' => 'resena.creada', 'canal' => 'push', 'activo' => true,
            'asunto' => 'Nueva reseña: {{calificacion}} de 5',
            'cuerpo' => '{{persona_nombre}} calificó {{actividad}}. {{comentario}}',
        ],
        [
            'clave' => 'orden.pagada', 'canal' => 'push', 'activo' => false,
            'asunto' => 'Venta: {{total}}',
            'cuerpo' => '{{persona_nombre}} pagó con {{metodo}}.',
        ],
        [
            'clave' => 'cobro.fallido', 'canal' => 'push', 'activo' => false,
            'asunto' => 'Cobro fallido: {{persona_nombre}}',
            'cuerpo' => '{{producto}}. {{motivo}}',
        ],
        [
            'clave' => 'membresia.suspendida', 'canal' => 'push', 'activo' => false,
            'asunto' => 'Membresía suspendida: {{persona_nombre}}',
            'cuerpo' => '{{producto}} quedó suspendida por falta de pago.',
        ],
        [
            'clave' => 'cuenta.creada', 'canal' => 'push', 'activo' => false,
            'asunto' => 'Cliente nuevo: {{persona_nombre}}',
            'cuerpo' => 'Se registró desde la página del negocio.',
        ],
    ];

    public function up(): void
    {
        $ahora = now();
        foreach (self::PLANTILLAS as $plantilla) {
            DB::connection('tenant')->table('plantillas_mensaje')->insertOrIgnore([
                ...$plantilla,
                'ulid' => strtolower((string) Str::ulid()),
                'destinatario' => 'equipo',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('tenant')->table('plantillas_mensaje')->where('destinatario', 'equipo')->delete();
    }
};
