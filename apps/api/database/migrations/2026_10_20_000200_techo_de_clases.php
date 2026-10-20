<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Techo de la tarifa de clases (decisión del dueño, 2026-10-20; ADR 0181): arriba de
| unos 300 alumnos activos los planes de precio fijo de la competencia salían más
| baratos. Una versión nueva con 301–500 a USD 149 y 501–1,000 a USD 199 (más de 1,000
| sigue en cotización, con el mismo techo). Las demás bandas no cambian; las versiones
| anteriores y sus cargos tampoco.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $anterior = DB::table('tarifas_saas')->where('modalidad', 'clases')
                ->orderByDesc('version')->lockForUpdate()->first();
            if ($anterior === null) {
                return;
            }
            $definicion = (array) json_decode((string) $anterior->definicion, true);
            $bandas = is_array($definicion['bandas'] ?? null) ? $definicion['bandas'] : [];
            $techo = static fn (mixed $hasta): ?int => match ($hasta === null ? null : (int) $hasta) {
                500 => 14900,
                1000, null => 19900,
                default => null,
            };
            $cambia = false;
            foreach ($bandas as $i => $banda) {
                $nuevo = $techo($banda['hasta'] ?? null);
                if ($nuevo !== null && (int) $banda['monto_minor'] > $nuevo) {
                    $bandas[$i]['monto_minor'] = $nuevo;
                    $cambia = true;
                }
            }
            // Ya publicada (o una tarifa sin esas bandas): nada que hacer.
            if (! $cambia) {
                return;
            }

            $ahora = now();
            DB::table('tarifas_saas')->insert([
                'ulid' => (string) Str::ulid(),
                'modalidad' => 'clases',
                'version' => (int) $anterior->version + 1,
                'definicion' => json_encode([...$definicion, 'bandas' => $bandas], JSON_THROW_ON_ERROR),
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
