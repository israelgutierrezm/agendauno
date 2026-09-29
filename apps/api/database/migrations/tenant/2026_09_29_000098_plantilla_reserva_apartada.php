<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Correo al apartar una cita que se paga en línea para confirmarla (ADR 0065): qué se
| apartó, dónde, cuánto y hasta qué hora; si no se paga, el lugar se libera. La
| confirmación sale al pagar (`reserva.confirmada`). Activo y editable en
| Comunicación → Automáticos; no pisa uno que el negocio ya tenga.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $ahora = now();
        DB::connection('tenant')->table('plantillas_mensaje')->insertOrIgnore([
            'ulid' => strtolower((string) Str::ulid()),
            'clave' => 'reserva.apartada',
            'canal' => 'email',
            'destinatario' => 'persona',
            'asunto' => 'Apartamos tu lugar: {{actividad}} el {{fecha}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nApartamos tu lugar para {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}}.\n\nPara confirmarlo, completa el pago de {{total}} antes de las {{vence}}. Si no se paga, el lugar se libera.\n\n{{negocio}}",
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
        DB::connection('tenant')->table('plantillas_mensaje')
            ->where('clave', 'reserva.apartada')
            ->where('canal', 'email')
            ->where('destinatario', 'persona')
            ->delete();
    }
};
