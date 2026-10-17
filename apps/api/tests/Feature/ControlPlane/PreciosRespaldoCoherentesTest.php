<?php

declare(strict_types=1);

/*
| Los precios de respaldo de la landing (apps/web/src/marketing/precios-respaldo.json,
| lo que dice su HTML pre-generado cuando no se leen los publicados) son los de la
| tarifa que siembran las migraciones. Si cambia la tarifa sembrada y no el respaldo
| (o al revés), esta prueba falla: el HTML, la tabla y la contratación no dirían lo
| mismo.
*/

/**
 * @return array<string, mixed>
 */
function preciosDeRespaldoDeLaLanding(): array
{
    $ruta = base_path('../web/src/marketing/precios-respaldo.json');
    expect(is_file($ruta))->toBeTrue("No está el respaldo de precios de la landing: {$ruta}");

    return (array) json_decode((string) file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);
}

it('el respaldo de la landing es la tarifa publicada que siembran las migraciones', function (): void {
    $respaldo = preciosDeRespaldoDeLaLanding();
    $publicados = $this->getJson('/api/v1/precios')->assertOk()->json('data');

    foreach (['moneda', 'iva_porcentaje', 'dias_prueba', 'bandas'] as $campo) {
        expect($publicados['clases'][$campo])->toEqual($respaldo['clases'][$campo], "clases.{$campo}");
    }
    foreach (['moneda', 'iva_porcentaje', 'dias_prueba', 'meses_anual', 'niveles'] as $campo) {
        expect($publicados['citas'][$campo])->toEqual($respaldo['citas'][$campo], "citas.{$campo}");
    }
    expect($publicados['timbres'])->toEqual($respaldo['timbres'])
        ->and($publicados['registro'])->toEqual($respaldo['registro']);
    // Desde cuándo rige: lo que la landing y la contratación muestran igual.
    expect($publicados['clases']['vigente_desde'])->toBeString()
        ->and($publicados['citas']['vigente_desde'])->toBeString();
});

it('la contratación muestra desde cuándo rige la misma tarifa que la landing', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $publicados = $this->getJson('/api/v1/precios')->assertOk()->json('data');

    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.tarifa.vigente_desde', $publicados['clases']['vigente_desde']);
});
