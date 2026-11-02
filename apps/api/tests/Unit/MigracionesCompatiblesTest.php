<?php

declare(strict_types=1);

/*
| La versión anterior debe seguir funcionando con el esquema nuevo: un despliegue que
| falla vuelve solo a ella sin revertir la base (ADR 0135, 0232). Una migración nueva que
| borra o renombra rompe esa regla, y solo se admite si lo dice a propósito, con la
| marca `// destructiva-revisada: <por qué es seguro>`. Las anteriores a esta guardia
| ya corrieron.
*/

it('una migración nueva no borra ni renombra sin revisarlo', function (): void {
    $desde = '2026_11_02';
    $peligrosas = '/->dropColumn\(|->renameColumn\(|Schema::(drop|dropIfExists|rename)\(|->dropForeign\(|->change\(\)/';
    $sinRevisar = [];

    foreach ([...glob(base_path('database/migrations/*.php')), ...glob(base_path('database/migrations/tenant/*.php'))] as $archivo) {
        if (strcmp(basename($archivo), $desde) < 0) {
            continue;
        }
        $codigo = (string) file_get_contents($archivo);
        // Lo que corre al subir; `down` borra por diseño.
        $subida = explode('function down', $codigo)[0];
        if (preg_match($peligrosas, $subida) === 1 && ! str_contains($codigo, '// destructiva-revisada:')) {
            $sinRevisar[] = basename($archivo);
        }
    }

    expect($sinRevisar)->toBe([]);
});
