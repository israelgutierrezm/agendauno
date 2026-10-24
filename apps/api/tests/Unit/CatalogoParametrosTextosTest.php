<?php

declare(strict_types=1);

use App\Modules\Tenancy\Parametros\CatalogoParametros;

/*
| Los textos de los parámetros que ve un negocio usan las palabras base del panel
| (ADR 0203): «alumno» y «clase», que el panel lee con los términos del negocio (socio,
| cliente, paciente…; cita, sesión…). «Cliente» no se adapta, «él» no concuerda con un
| término femenino (socia, alumna) y «clase o cita» no se lee en un negocio que es solo
| de clases o solo de citas.
*/

it('los parámetros que ve un negocio usan «alumno» y «clase», sin «cliente», «él» ni «clase o cita»', function (): void {
    $mal = [];
    foreach (CatalogoParametros::todos() as $definicion) {
        if (! $definicion->porNegocio) {
            continue;
        }
        foreach (['grupo' => $definicion->grupo, 'etiqueta' => $definicion->etiqueta, 'ayuda' => $definicion->ayuda, 'unidad' => $definicion->unidad] as $campo => $texto) {
            if (preg_match('/(?<!\p{L})(clientes?|él|clases? o citas?)(?!\p{L})/iu', $texto) === 1) {
                $mal[] = "{$definicion->clave} ({$campo}): {$texto}";
            }
        }
    }

    expect($mal)->toBe([]);
});
