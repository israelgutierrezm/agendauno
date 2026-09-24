<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Pago automático: el negocio invita al alumno a activarlo (correo con el enlace a su
| cuenta). Y el aviso de cobro pendiente ahora dice por qué no se pudo cobrar (p. ej.
| el banco rechazó el cargo automático): solo se actualiza si el negocio no lo editó.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    private const COBRO_ANTES = "Hola {{persona_nombre}}:\n\nNo pudimos completar el cobro de la renovación de tu {{producto}}. Entra a tu cuenta para pagarla y seguir reservando:\n{{enlace}}\n\n{{negocio}}";

    private const COBRO_AHORA = "Hola {{persona_nombre}}:\n\nNo pudimos completar el cobro de la renovación de tu {{producto}}. {{motivo}}\n\nEntra a tu cuenta para pagarla y seguir reservando:\n{{enlace}}\n\n{{negocio}}";

    public function up(): void
    {
        $ahora = now();
        DB::connection('tenant')->table('plantillas_mensaje')->insertOrIgnore([
            'ulid' => strtolower((string) Str::ulid()),
            'clave' => 'pago_automatico.solicitado',
            'canal' => 'email',
            'asunto' => 'Activa el pago automático de tu {{producto}}',
            'cuerpo' => "Hola {{persona_nombre}}:\n\nPara que no tengas que pagar cada mes, puedes activar el pago automático de tu {{producto}}: la renovación se cobra sola a tu tarjeta y te avisamos si algo falla. Lo activas (o lo quitas cuando quieras) desde tu cuenta:\n{{enlace}}\n\n{{negocio}}",
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        DB::connection('tenant')->table('plantillas_mensaje')
            ->where('clave', 'cobro.fallido')
            ->where('canal', 'email')
            ->where('cuerpo', self::COBRO_ANTES)
            ->update(['cuerpo' => self::COBRO_AHORA, 'updated_at' => $ahora]);
    }

    public function down(): void
    {
        DB::connection('tenant')->table('plantillas_mensaje')
            ->where('clave', 'pago_automatico.solicitado')
            ->where('canal', 'email')
            ->delete();

        DB::connection('tenant')->table('plantillas_mensaje')
            ->where('clave', 'cobro.fallido')
            ->where('canal', 'email')
            ->where('cuerpo', self::COBRO_AHORA)
            ->update(['cuerpo' => self::COBRO_ANTES]);
    }
};
