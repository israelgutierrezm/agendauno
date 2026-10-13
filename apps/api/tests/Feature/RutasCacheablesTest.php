<?php

declare(strict_types=1);

use Illuminate\Routing\Route as Ruta;
use Illuminate\Support\Facades\Route;

/*
| Producción arranca con `route:cache` (infra/produccion/api-entrypoint.sh): si dos
| rutas comparten nombre, el caché no se arma y la API, la cola y el programador no
| levantan. Cada nombre es único y las rutas se compilan como en el caché.
*/

it('cada nombre de ruta es único', function (): void {
    $repetidos = collect(Route::getRoutes()->getRoutes())
        ->map(static fn (Ruta $ruta): ?string => $ruta->getName())
        ->filter()
        ->countBy()
        ->filter(static fn (int $veces): bool => $veces > 1)
        ->keys()
        ->all();

    expect($repetidos)->toBe([]);
});

it('las rutas se compilan como en route:cache', function (): void {
    $compiladas = Route::getRoutes()->toCompiledRouteCollection(app('router'), app());

    expect($compiladas->getRoutes())->not->toBeEmpty();
});
