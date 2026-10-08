<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Tarifas en USD (ADR 0107): una versión nueva por modalidad (las anteriores, en
| pesos, se conservan y sus cargos no cambian). Montos en centavos de dólar, sin IVA.
|
| - Clases: por alumnos activos, ReservaClase Premium −15 % redondeado hacia abajo.
|   Más de 1,000: cotización (cuota fija pactada); hasta entonces rige el techo.
| - Citas: niveles por profesionales contratados. Individual (1) 9 USD; Premium y Pro
|   de 2 a 20 (AgendaPro Básico y Premium −15 % redondeado hacia abajo). Anual: 10
|   meses (2 de cortesía). Más de 20: cotización.
| - IVA 16 % en México; fuera de México 0 % (exportación de servicios).
*/
return new class extends Migration
{
    private const PREMIUM = [
        2 => 24, 3 => 28, 4 => 33, 5 => 37, 6 => 41, 7 => 45, 8 => 50, 9 => 54, 10 => 58, 11 => 62,
        12 => 67, 13 => 71, 14 => 75, 15 => 79, 16 => 84, 17 => 88, 18 => 92, 19 => 96, 20 => 101,
    ];

    private const PRO = [
        2 => 33, 3 => 50, 4 => 50, 5 => 50, 6 => 58, 7 => 67, 8 => 75, 9 => 84, 10 => 92, 11 => 101,
        12 => 109, 13 => 118, 14 => 126, 15 => 135, 16 => 143, 17 => 152, 18 => 160, 19 => 169, 20 => 177,
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->publicar('clases', fn (array $anterior): array => [
                'moneda' => 'USD',
                'dias_prueba' => (int) ($anterior['dias_prueba'] ?? 30),
                'iva_porcentaje' => 16,
                'iva_porcentaje_extranjero' => 0,
                'bandas' => [
                    ['hasta' => 40, 'monto_minor' => 2100],
                    ['hasta' => 60, 'monto_minor' => 3000],
                    ['hasta' => 80, 'monto_minor' => 3900],
                    ['hasta' => 100, 'monto_minor' => 4800],
                    ['hasta' => 150, 'monto_minor' => 6800],
                    ['hasta' => 200, 'monto_minor' => 8400],
                    ['hasta' => 300, 'monto_minor' => 11100],
                    ['hasta' => 500, 'monto_minor' => 16400],
                    ['hasta' => 1000, 'monto_minor' => 29700],
                    ['hasta' => null, 'monto_minor' => 29700],
                ],
            ]);

            $centavos = static fn (array $precios): array => array_combine(
                array_map('strval', array_keys($precios)),
                array_map(static fn (int $usd): int => $usd * 100, array_values($precios)),
            );
            $this->publicar('citas', fn (array $anterior): array => [
                'moneda' => 'USD',
                'dias_prueba' => (int) ($anterior['dias_prueba'] ?? 14),
                'iva_porcentaje' => 16,
                'iva_porcentaje_extranjero' => 0,
                'meses_anual' => 10,
                'niveles' => [
                    'individual' => ['1' => 900],
                    'premium' => $centavos(self::PREMIUM),
                    'pro' => $centavos(self::PRO),
                ],
            ]);
        });
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $definicion
     */
    private function publicar(string $modalidad, Closure $definicion): void
    {
        $ahora = now();
        $anterior = DB::table('tarifas_saas')->where('modalidad', $modalidad)
            ->orderByDesc('version')->lockForUpdate()->first();
        $previa = $anterior === null ? [] : (array) json_decode((string) $anterior->definicion, true);
        if (($previa['moneda'] ?? null) === 'USD') {
            return;
        }

        DB::table('tarifas_saas')->insert([
            'ulid' => (string) Str::ulid(),
            'modalidad' => $modalidad,
            'version' => ((int) ($anterior->version ?? 0)) + 1,
            'definicion' => json_encode($definicion($previa), JSON_THROW_ON_ERROR),
            'vigente_desde' => $ahora,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
        // Las tarifas y los cargos históricos son inmutables; revertir publicando otra versión.
    }
};
