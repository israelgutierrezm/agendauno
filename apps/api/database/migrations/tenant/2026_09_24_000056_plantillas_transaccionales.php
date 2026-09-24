<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Correos transaccionales por defecto: confirmación de reserva o cita, recibo de pago
| y bienvenida al crear su cuenta. Activos y editables en Comunicación → Automáticos;
| no pisa una plantilla que el negocio ya tenga para ese evento.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    private const PLANTILLAS = [
        'reserva.confirmada' => [
            'asunto' => 'Reserva confirmada: {{actividad}} el {{fecha}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nTu lugar está confirmado.\n\n{{actividad}}\n{{fecha}} a las {{hora}}\n{{sucursal}}\n\nSi no puedes asistir, avísanos para liberar tu lugar.\n\n{{negocio}}",
        ],
        'orden.pagada' => [
            'asunto' => 'Recibo de tu pago en {{negocio}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nRecibimos tu pago. Este es el detalle:\n\n{{detalle}}\n\nTotal: {{total}}\nPagado con: {{metodo}}\nFecha: {{fecha}}\nFolio: {{folio}}\n\nGracias.\n{{negocio}}",
        ],
        'cuenta.creada' => [
            'asunto' => 'Te damos la bienvenida a {{negocio}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nTu cuenta está lista. Desde ahí puedes reservar, ver tus créditos y tus compras:\n{{enlace}}\n\n{{negocio}}",
        ],
    ];

    public function up(): void
    {
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
            ->where('canal', 'email')
            ->whereIn('clave', array_keys(self::PLANTILLAS))
            ->delete();
    }
};
