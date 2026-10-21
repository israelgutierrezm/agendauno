<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Aviso push al equipo cuando alguien escribe a la bandeja (ADR 0200, ajuste del
| 2026-10-21): le llega a quien tiene `mensajes.atender`. Llega activo: un mensaje
| pide respuesta y Meta solo deja contestar en 24 horas. Sale una vez por
| conversación hasta que alguien la lee (no uno por cada mensaje de una ráfaga). Dice
| el canal y quién escribió, nunca su número ni lo que escribió.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $ahora = now();
        DB::connection('tenant')->table('plantillas_mensaje')->insertOrIgnore([
            'ulid' => strtolower((string) Str::ulid()),
            'clave' => 'bandeja.mensaje_recibido',
            'canal' => 'push',
            'destinatario' => 'equipo',
            'activo' => true,
            'asunto' => 'Mensaje nuevo por {{canal}}',
            'cuerpo' => '{{contacto}} te escribió. Contéstale en Mensajes.',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
        DB::connection('tenant')->table('plantillas_mensaje')->where('clave', 'bandeja.mensaje_recibido')->delete();
    }
};
