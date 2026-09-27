<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $ahora = now();
            $vigente = DB::table('tarifas_saas')->where('modalidad', 'clases')
                ->where('vigente_desde', '<=', $ahora)->orderByDesc('version')->lockForUpdate()->first();
            if ($vigente === null) {
                throw new RuntimeException('Se requiere una tarifa de clases vigente antes de actualizar sus rangos.');
            }
            $definicion = json_decode((string) $vigente->definicion, true, 512, JSON_THROW_ON_ERROR);
            $bandas = [
                ['hasta' => 49, 'monto_minor' => 33900],
                ['hasta' => 99, 'monto_minor' => 63900],
                ['hasta' => 199, 'monto_minor' => 90900],
                ['hasta' => 300, 'monto_minor' => 178900],
                ['hasta' => 500, 'monto_minor' => 264900],
                ['hasta' => null, 'monto_minor' => 288900],
            ];
            if (($definicion['bandas'] ?? []) === $bandas) {
                return;
            }
            $anteriores = [
                ['hasta' => 40, 'monto_minor' => 33900],
                ['hasta' => 80, 'monto_minor' => 63900],
                ['hasta' => 120, 'monto_minor' => 90900],
                ['hasta' => 200, 'monto_minor' => 135900],
                ['hasta' => 300, 'monto_minor' => 178900],
                ['hasta' => 500, 'monto_minor' => 264900],
                ['hasta' => null, 'monto_minor' => 288900],
            ];
            if (($definicion['bandas'] ?? []) !== $anteriores) {
                throw new RuntimeException('La tarifa fue personalizada: conciliar sus rangos desde plataforma antes de migrar.');
            }
            if (DB::table('tarifas_saas')->where('modalidad', 'clases')->where('vigente_desde', '>', $ahora)->exists()) {
                throw new RuntimeException('Existen tarifas de clases futuras: conciliarlas antes de actualizar rangos.');
            }
            $definicion['bandas'] = $bandas;
            // El contacto >2,000 es comercial, no un bloqueo de operación. Una
            // propuesta aceptada usa el modo de cuota fija existente del negocio.
            DB::table('tarifas_saas')->insert([
                'ulid' => (string) Str::ulid(),
                'modalidad' => 'clases',
                'version' => ((int) DB::table('tarifas_saas')->where('modalidad', 'clases')->max('version')) + 1,
                'definicion' => json_encode($definicion, JSON_THROW_ON_ERROR),
                'vigente_desde' => $ahora,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        });
    }

    public function down(): void
    {
        // Las tarifas y los cargos históricos son inmutables; revertir publicando otra versión.
    }
};
