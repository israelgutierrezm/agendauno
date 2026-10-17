<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Cache;

/**
 * Los datos públicos de un negocio (los de su escaparate con su sitio publicado) para lo
 * que piden los buscadores sin JavaScript: el contenido indexable de su página y sus
 * datos estructurados (ADR 0155). Se guardan unos minutos por negocio: nginx los pide
 * en cada página servida y no conviene abrir su base cada vez. Unos minutos de retraso
 * para un buscador no importan; la página (la web) siempre lee lo de ese momento.
 */
final class DatosPublicosNegocio
{
    /** Cuánto se guardan (segundos). */
    public const SEGUNDOS = 600;

    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly EscaparateTenant $escaparate,
        private readonly SitioWebTenant $sitio,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function de(Estudio $estudio): array
    {
        /** @var array<string, mixed> */
        return Cache::remember(
            'pagina-publica:'.$estudio->getKey(),
            self::SEGUNDOS,
            fn (): array => $this->gestor->ejecutarEn($estudio, fn (): array => $this->escaparate->datos(
                $estudio,
                $this->sitio->paraPublico($this->sitio->publicado($estudio->modalidad())),
            )),
        );
    }
}
