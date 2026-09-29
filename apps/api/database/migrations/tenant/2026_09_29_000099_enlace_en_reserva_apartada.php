<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| El correo de lugar apartado lleva el enlace para pagarlo después (la página de
| agendar con la orden). Solo cambia el texto inicial: si el negocio ya lo editó, se
| respeta (puede agregar {{enlace}} en Comunicación → Automáticos).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    private const ANTES = "Hola {{persona_nombre}}:\n\nApartamos tu lugar para {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}}.\n\nPara confirmarlo, completa el pago de {{total}} antes de las {{vence}}. Si no se paga, el lugar se libera.\n\n{{negocio}}";

    private const AHORA = "Hola {{persona_nombre}}:\n\nApartamos tu lugar para {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}}.\n\nPara confirmarlo, completa el pago de {{total}} antes de las {{vence}}. Si no se paga, el lugar se libera.\n\nPágalo aquí: {{enlace}}\n\n{{negocio}}";

    public function up(): void
    {
        $this->cambiar(self::ANTES, self::AHORA);
    }

    public function down(): void
    {
        $this->cambiar(self::AHORA, self::ANTES);
    }

    private function cambiar(string $de, string $a): void
    {
        DB::connection('tenant')->table('plantillas_mensaje')
            ->where('clave', 'reserva.apartada')
            ->where('canal', 'email')
            ->where('destinatario', 'persona')
            ->where('cuerpo', $de)
            ->update(['cuerpo' => $a, 'updated_at' => now()]);
    }
};
