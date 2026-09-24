<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Aviso por correo cuando no se pudo cobrar la renovación de una membresía (o falta
| que el cliente complete el pago): con el enlace a su cuenta para pagarla. Activo y
| editable en Comunicación → Automáticos; no pisa uno que el negocio ya tenga.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $ahora = now();
        DB::connection('tenant')->table('plantillas_mensaje')->insertOrIgnore([
            'ulid' => strtolower((string) Str::ulid()),
            'clave' => 'cobro.fallido',
            'canal' => 'email',
            'asunto' => 'Tu pago de {{producto}} está pendiente',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nNo pudimos completar el cobro de la renovación de tu {{producto}}. Entra a tu cuenta para pagarla y seguir reservando:\n{{enlace}}\n\n{{negocio}}",
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
        DB::connection('tenant')->table('plantillas_mensaje')
            ->where('clave', 'cobro.fallido')
            ->where('canal', 'email')
            ->delete();
    }
};
