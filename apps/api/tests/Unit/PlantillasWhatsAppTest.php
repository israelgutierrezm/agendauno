<?php

declare(strict_types=1);

use App\Modules\Tenancy\Comunicaciones\WhatsApp\PlantillasWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;

/*
| Plantillas y números de WhatsApp (ADR 0069): lo que Meta acepta.
*/

it('cada plantilla es válida para Meta: no abre ni cierra con un valor y no repite marcadores', function (): void {
    foreach (PlantillasWhatsApp::paraNegocios() as $plantilla) {
        expect($plantilla['nombre'])->toMatch('/^[a-z0-9_]+$/')
            ->and($plantilla['texto'])->not->toMatch('/^\s*\{\{/')
            ->and($plantilla['texto'])->not->toMatch('/\}\}[\s.]*$/');

        $original = PlantillasWhatsApp::para($plantilla['evento']);
        preg_match_all('/\{\{(\w+)\}\}/', (string) $original['texto'], $todos);
        expect($todos[1])->toBe(array_values(array_unique($todos[1])));
    }
});

it('los valores van en orden, sin saltos de línea y nunca vacíos', function (): void {
    $texto = 'Hola {{persona_nombre}}, tu {{actividad}} en {{sucursal}} te espera.';

    expect(PlantillasWhatsApp::textoParaMeta($texto))->toBe('Hola {{1}}, tu {{2}} en {{3}} te espera.')
        ->and(PlantillasWhatsApp::parametros($texto, ['persona_nombre' => "Vale\n", 'actividad' => "Corte  \t de cabello"]))
        ->toBe(['Vale', 'Corte de cabello', '-']);
});

it('deja el celular como lo pide WhatsApp: con lada y solo dígitos', function (?string $celular, ?string $esperado): void {
    expect(TelefonoWhatsApp::normalizar($celular))->toBe($esperado);
})->with([
    'sin lada' => ['55 1234 5678', '525512345678'],
    'con lada' => ['+52 5512345678', '525512345678'],
    'con el 1 de antes' => ['+52 1 55 1234 5678', '525512345678'],
    'otro país' => ['+1 555 123 4567', '15551234567'],
    'con 52 sin +' => ['525512345678', '525512345678'],
    'incompleto' => ['1234', null],
    'vacío' => [null, null],
]);
