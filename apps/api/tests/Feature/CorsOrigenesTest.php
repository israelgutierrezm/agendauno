<?php

declare(strict_types=1);

/*
| CORS: la API acepta el front configurado (FRONTEND_REGISTRO_URL, varias separadas
| por coma) y la app de cada negocio en su subdominio; cualquier otro origen no.
*/

it('acepta los subdominios de los negocios y rechaza otros orígenes', function (): void {
    $dominio = (string) config('agendauno.dominio_base');
    $preflight = fn (string $origen) => $this->withHeaders([
        'Origin' => $origen,
        'Access-Control-Request-Method' => 'GET',
    ])->options('/api/v1/directorio');

    $preflight("https://demo.{$dominio}")->assertHeader('Access-Control-Allow-Origin', "https://demo.{$dominio}");
    $preflight("https://{$dominio}")->assertHeader('Access-Control-Allow-Origin', "https://{$dominio}");

    $preflight('https://otro-sitio.com')->assertHeaderMissing('Access-Control-Allow-Origin');
    $preflight("https://demo.{$dominio}.otro-sitio.com")->assertHeaderMissing('Access-Control-Allow-Origin');
    $preflight("http://demo.{$dominio}")->assertHeaderMissing('Access-Control-Allow-Origin');
});
