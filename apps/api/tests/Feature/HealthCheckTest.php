<?php

declare(strict_types=1);

it('reporta estado saludable con verificaciones por dependencia', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.cache.status', 'ok')
        ->assertJsonStructure([
            'status',
            'app',
            'version',
            'time',
            'checks' => ['database', 'cache', 'redis'],
        ])
        // Es pública: no dice en qué entorno corre.
        ->assertJsonMissingPath('environment');
});
