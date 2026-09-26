<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Nueva condición para altas, sin reescribir precios ni pruebas ya iniciadas.
        DB::transaction(function (): void {
            $ahora = now();
            foreach (['clases', 'citas'] as $modalidad) {
                $vigente = DB::table('tarifas_saas')
                    ->where('modalidad', $modalidad)->where('vigente_desde', '<=', $ahora)
                    ->orderByDesc('version')->lockForUpdate()->first();
                if ($vigente === null) {
                    continue;
                }
                $definicion = json_decode((string) $vigente->definicion, true, 512, JSON_THROW_ON_ERROR);
                if (($definicion['dias_prueba'] ?? null) === 30) {
                    continue;
                }
                // No ocultar por prioridad de versión una tarifa futura programada.
                if (DB::table('tarifas_saas')->where('modalidad', $modalidad)->where('vigente_desde', '>', $ahora)->exists()) {
                    throw new RuntimeException('Hay tarifas futuras: actualizar su prueba gratuita desde la plataforma antes de aplicar esta migración.');
                }
                $definicion['dias_prueba'] = 30;
                DB::table('tarifas_saas')->insert([
                    'ulid' => (string) Str::ulid(),
                    'modalidad' => $modalidad,
                    'version' => ((int) DB::table('tarifas_saas')->where('modalidad', $modalidad)->max('version')) + 1,
                    'definicion' => json_encode($definicion, JSON_THROW_ON_ERROR),
                    'vigente_desde' => $ahora,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Conservar el historial: para revertir la condición se publica otra tarifa.
    }
};
