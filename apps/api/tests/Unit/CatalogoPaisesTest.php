<?php

declare(strict_types=1);

use App\Modules\Tenancy\CatalogoPaises;

/*
| El catálogo de países (ADR 0103): todos los de ISO 3166-1 alfa-2 con su lada, sin
| depender de la extensión intl.
*/

it('trae la lada de cada país, solo con dígitos', function (string $pais, string $lada): void {
    expect(CatalogoPaises::existe($pais))->toBeTrue()
        ->and(CatalogoPaises::lada($pais))->toBe($lada);
})->with([
    'México' => ['MX', '52'],
    'Estados Unidos' => ['US', '1'],
    'Canadá' => ['CA', '1'],
    'Colombia' => ['CO', '57'],
    'Argentina' => ['AR', '54'],
    'Chile' => ['CL', '56'],
    'España' => ['ES', '34'],
    'Brasil' => ['BR', '55'],
    'Perú' => ['PE', '51'],
    'República Dominicana' => ['DO', '1'],
    'Costa Rica' => ['CR', '506'],
]);

it('acepta el código sin importar mayúsculas ni espacios y rechaza lo que no es un país', function (): void {
    expect(CatalogoPaises::existe(' co '))->toBeTrue()
        ->and(CatalogoPaises::lada('es'))->toBe('34')
        ->and(CatalogoPaises::codigo(' mx'))->toBe('MX')
        ->and(CatalogoPaises::existe('ZZ'))->toBeFalse()
        ->and(CatalogoPaises::existe('MEX'))->toBeFalse()
        ->and(CatalogoPaises::existe(null))->toBeFalse()
        ->and(CatalogoPaises::lada('ZZ'))->toBeNull();
});

it('la lista está completa, sin repetir, y cada lada es de 1 a 4 dígitos', function (): void {
    $lista = CatalogoPaises::lista();
    $codigos = array_column($lista, 'codigo');

    // Los 249 de ISO 3166-1 más Kosovo (XK).
    expect($lista)->toHaveCount(250)
        ->and($codigos)->toBe(array_values(array_unique($codigos)))
        ->and($codigos)->toBe(CatalogoPaises::codigos())
        ->and($lista[0])->toBe(['codigo' => 'AD', 'lada' => '376']);
    foreach ($lista as $pais) {
        expect($pais['codigo'])->toMatch('/^[A-Z]{2}$/')
            ->and($pais['lada'])->toMatch('/^[1-9]\d{0,3}$/');
    }
});

it('reconoce una lada de la lista', function (): void {
    expect(CatalogoPaises::esLada('593'))->toBeTrue()
        ->and(CatalogoPaises::esLada('1'))->toBeTrue()
        ->and(CatalogoPaises::esLada('5255'))->toBeFalse()
        ->and(CatalogoPaises::esLada(''))->toBeFalse();
});
