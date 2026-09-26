<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Pagos que llegan tarde o dos veces (fase 1, punto 1.2):
| - `reservas.motivo_cancelacion`: por qué se canceló (p. ej. `vencio_pago`: su
|   apartado venció sin pago). Solo una reserva que venció se puede reconfirmar si
|   su pago llega después y el horario sigue libre.
| - Avisos: al cliente cuyo pago llegó cuando su horario ya no estaba, y al equipo que
|   ve la facturación (pago tardío y cobro doble), activos y editables.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * @var list<array{clave: string, canal: string, destinatario: string, asunto: string, cuerpo: string}>
     */
    private const PLANTILLAS = [
        [
            'clave' => 'pago.tardio', 'canal' => 'email', 'destinatario' => 'persona',
            'asunto' => 'Recibimos tu pago, pero tu horario ya no estaba disponible',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nRecibimos tu pago de {{monto}} por {{actividad}} del {{fecha}} a las {{hora}}, pero llegó después de que venció tu apartado y ese horario ya lo ocupó alguien más.\n\nEl negocio te contactará para devolverte el dinero o agendar otro horario.\n\n{{negocio}}",
        ],
        [
            'clave' => 'pago.tardio', 'canal' => 'email', 'destinatario' => 'equipo',
            'asunto' => 'Pago tardío: {{persona_nombre}}',
            'cuerpo' => "Llegó el pago de {{persona_nombre}} ({{monto}}) por {{actividad}} del {{fecha}} a las {{hora}} cuando su apartado ya había vencido y el horario ya no estaba disponible.\n\nDevuélvelo o reagenda con el cliente en Cobranza → Por conciliar.\n\n{{negocio}}",
        ],
        [
            'clave' => 'pago.tardio', 'canal' => 'push', 'destinatario' => 'equipo',
            'asunto' => 'Pago tardío: {{persona_nombre}}',
            'cuerpo' => '{{monto}} de un apartado vencido. Devuélvelo o reagenda en Cobranza.',
        ],
        [
            'clave' => 'pago.duplicado', 'canal' => 'push', 'destinatario' => 'equipo',
            'asunto' => 'Cobro doble: {{persona_nombre}}',
            'cuerpo' => '{{monto}} se cobró dos veces. Devuélvelo en Cobranza → Por conciliar.',
        ],
    ];

    public function up(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->string('motivo_cancelacion', 40)->nullable();
        });

        $ahora = now();
        foreach (self::PLANTILLAS as $plantilla) {
            DB::connection('tenant')->table('plantillas_mensaje')->insertOrIgnore([
                ...$plantilla,
                'ulid' => strtolower((string) Str::ulid()),
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('tenant')->table('plantillas_mensaje')->whereIn('clave', ['pago.tardio', 'pago.duplicado'])->delete();

        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropColumn('motivo_cancelacion');
        });
    }
};
