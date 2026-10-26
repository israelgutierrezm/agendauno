<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Los avisos de una cita (confirmación, recordatorio de un día antes y cambio de
| horario) llevan el enlace para cancelarla o cambiarla sin cuenta (ADR 0221). Solo
| cambia el texto por omisión de citas: si el negocio ya lo editó, se respeta (puede
| agregar {{enlace_cita}} en Comunicación → Automáticos).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    private const VIEJO = 'Si no puedes asistir, avísanos para liberar tu horario.';

    private const NUEVO = "Si no puedes asistir, cancélala o cámbiala aquí:\n{{enlace_cita}}";

    /** @var array<string, string> */
    private const TEXTOS = [
        'reserva.confirmada' => "Hola {{persona_nombre}}:\n\nTu cita está confirmada.\n\n{{actividad}}\n{{fecha}} a las {{hora}}\n{{sucursal}}\n\n%s\n\n{{negocio}}",
        'reserva.recordatorio_24h' => "Hola {{persona_nombre}}:\n\nTe recordamos tu cita de {{actividad}} el {{fecha}} a las {{hora}} en {{sucursal}}.\n\n%s\n\n{{negocio}}",
        'reserva.reprogramada' => "Hola {{persona_nombre}}:\n\nTu cita de {{actividad}} cambió de horario.\n\nAntes: {{antes_fecha}} a las {{antes_hora}}\nAhora: {{fecha}} a las {{hora}} en {{sucursal}}\n\n%s\n\n{{negocio}}",
    ];

    public function up(): void
    {
        $this->cambiar(self::VIEJO, self::NUEVO);
    }

    public function down(): void
    {
        $this->cambiar(self::NUEVO, self::VIEJO);
    }

    private function cambiar(string $de, string $a): void
    {
        foreach (self::TEXTOS as $clave => $texto) {
            DB::connection('tenant')->table('plantillas_mensaje')
                ->where('clave', $clave)
                ->where('canal', 'email')
                ->where('destinatario', 'persona')
                ->where('cuerpo', sprintf($texto, $de))
                ->update(['cuerpo' => sprintf($texto, $a), 'updated_at' => now()]);
        }
    }
};
