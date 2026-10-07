<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\VerificacionWhatsAppDueno;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use Illuminate\Support\Facades\Config;

/*
| El celular como lo pide WhatsApp con la lada del negocio (ADR 0103): sin «+» se
| completa con la del país del negocio, no con la de México.
*/

it('sin «+» antepone la lada del negocio; con «+» deja los dígitos tal cual', function (?string $celular, ?string $lada, ?string $esperado): void {
    expect(TelefonoWhatsApp::normalizar($celular, $lada))->toBe($esperado);
})->with([
    'Colombia, 10 dígitos' => ['300 123 4567', '57', '573001234567'],
    'España, 9 dígitos' => ['612 345 678', '34', '34612345678'],
    'Chile, 9 dígitos' => ['9 8765 4321', '56', '56987654321'],
    'Estados Unidos' => ['(305) 555-0100', '1', '13055550100'],
    'Costa Rica, 8 dígitos' => ['8888 1234', '506', '50688881234'],
    'ya trae la lada sin «+»' => ['57 300 123 4567', '57', '573001234567'],
    'con «+» de otro país' => ['+52 55 1234 5678', '57', '525512345678'],
    'con 00 de salida internacional' => ['0034 612 345 678', '57', '34612345678'],
    'sin el 0 de marcación nacional' => ['011 2345 6789', '54', '541123456789'],
    'lada con «+»' => ['300 123 4567', '+57', '573001234567'],
    'muy corto' => ['12345', '57', null],
    'muy largo' => ['1234567890123', '57', null],
    'México, 10 dígitos' => ['55 1234 5678', '52', '525512345678'],
    'México, con el 1 de antes' => ['+52 1 55 1234 5678', '52', '525512345678'],
    'México, un número que no es de 10 dígitos' => ['551234567', '52', null],
    'Italia conserva el 0 de su número' => ['06 1234 5678', '39', '390612345678'],
    'Brasil, zona 55 sin «+» (no es la lada)' => ['55 99123 4567', '55', '5555991234567'],
]);

it('«+<lada> <número>» limpia el número como uno nacional: sin el 0 ni la lada repetida', function (string $celular, string $esperado): void {
    // La lada del negocio no cuenta: el número ya trae la suya.
    expect(TelefonoWhatsApp::normalizar($celular, '52'))->toBe($esperado);
})->with([
    'Ecuador, con el 0 nacional' => ['+593 0991234567', '593991234567'],
    'Reino Unido, con (0)' => ['+44 (0) 7911 123456', '447911123456'],
    'Argentina, con el 0 nacional' => ['+54 011 2345 6789', '541123456789'],
    'México, la lada repetida' => ['+52 525512345678', '525512345678'],
    'México, la lada repetida y el 1 de antes' => ['+52 52 1 55 1234 5678', '525512345678'],
    'Estados Unidos, el 1 repetido' => ['+1 1 305 555 0100', '13055550100'],
    'Colombia, la lada repetida' => ['+57 57 300 123 4567', '573001234567'],
    'Italia conserva el 0' => ['+39 06 1234 5678', '390612345678'],
    'Brasil, zona 55 (no es la lada repetida)' => ['+55 55 99123 4567', '5555991234567'],
    'Brasil, la lada repetida' => ['+55 55 55 99123 4567', '5555991234567'],
    'con 00 escrito en el número' => ['+52 0034 612 345 678', '34612345678'],
    'el número trae su propio «+»' => ['+52 +57 300 123 4567', '573001234567'],
    'sin separar la lada: tal cual' => ['+5930991234567', '5930991234567'],
    'lo que no es una lada: tal cual' => ['+5255 1234 5678', '525512345678'],
]);

it('el código de verificación del dueño va al número limpio', function (): void {
    expect(VerificacionWhatsAppDueno::telefono('593', '099 123 4567'))->toBe('593991234567')
        ->and(VerificacionWhatsAppDueno::telefono('52', '52 55 1234 5678'))->toBe('525512345678')
        ->and(VerificacionWhatsAppDueno::telefono('52', '55 1234 5678'))->toBe('525512345678');
});

it('sin lada del negocio usa la de la plataforma como último respaldo', function (): void {
    Config::set('agendauno.whatsapp.lada', '57');
    expect(TelefonoWhatsApp::normalizar('300 123 4567'))->toBe('573001234567')
        ->and(TelefonoWhatsApp::normalizar('300 123 4567', ''))->toBe('573001234567')
        ->and(TelefonoWhatsApp::normalizar('612 345 678', '34'))->toBe('34612345678');
});
