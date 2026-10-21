<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\DominiosPropios;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\DominioEstudio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El dominio propio del sitio (ADR 0189): conectarlo, ver los registros que lleva su
 * DNS, revisarlos y quitarlo. Con el sitio avanzado de su plan («Plus»). Y, sin sesión,
 * de qué negocio es el dominio con que se abrió la página.
 */
class DominioPropioController
{
    public function __construct(
        private readonly DominiosPropios $dominios,
        private readonly FuncionesPlan $funciones,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function mostrar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        return response()->json(['data' => $this->presentar($estudio, $this->dominios->deEstudio($estudio))]);
    }

    public function conectar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $this->funciones->exigir($estudio, 'sitio_avanzado');
        $validado = $request->validate(['dominio' => ['required', 'string', 'max:300']]);

        $anterior = $this->dominios->deEstudio($estudio)?->dominio;
        $dominio = $this->dominios->conectar($estudio, (string) $validado['dominio']);
        if ($anterior !== $dominio->dominio) {
            $this->auditoria->registrar($this->actor($request), 'sitio.dominio_conectado', 'estudio', (string) $estudio->ulid, ['dominio' => $anterior], ['dominio' => $dominio->dominio]);
            $this->dominios->escribirTraefik();
        }

        return response()->json(['data' => $this->presentar($estudio, $dominio)]);
    }

    /** Revisa su DNS ahora (el dueño acaba de crear los registros). */
    public function revisar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $dominio = $this->dominios->deEstudio($estudio) ?? abort(404);
        $estaba = $dominio->activo();
        $this->dominios->revisar($dominio);
        if ($estaba !== $dominio->activo()) {
            $this->dominios->escribirTraefik();
        }

        return response()->json(['data' => $this->presentar($estudio, $dominio)]);
    }

    public function quitar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $quitado = $this->dominios->quitar($estudio);
        if ($quitado !== null) {
            $this->auditoria->registrar($this->actor($request), 'sitio.dominio_quitado', 'estudio', (string) $estudio->ulid, ['dominio' => $quitado], null);
            $this->dominios->escribirTraefik();
        }

        return response()->json(['data' => $this->presentar($estudio, null)]);
    }

    /**
     * Sin sesión: el negocio del dominio con que se abrió la página (la web lo pide al
     * cargar en un dominio que no es de la plataforma). 404 si no se sirve.
     */
    public function resolver(Request $request): JsonResponse
    {
        $estudio = $this->dominios->estudioDelHost($request->getHost()) ?? abort(404);

        return response()->json(['data' => [
            'slug' => $estudio->slug,
            'producto' => $estudio->producto()->value,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Estudio $estudio, ?DominioEstudio $dominio): array
    {
        $conPlan = $this->funciones->tiene($estudio, 'sitio_avanzado');

        return [
            'con_plan' => $conPlan,
            'destino' => $this->dominios->destino($estudio),
            'dominio' => $dominio === null ? null : [
                'dominio' => $dominio->dominio,
                // Activo: suyo y apuntando; solo se sirve con su plan.
                'estado' => $dominio->activo() ? ($conPlan ? 'activo' : 'sin_plan') : 'pendiente',
                'problema' => $dominio->problema,
                'propiedad_verificada' => $dominio->propiedad_verificada_en !== null,
                'revisado_en' => $dominio->revisado_en?->toIso8601String(),
                'registros' => $this->dominios->registros($estudio, $dominio),
            ],
        ];
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }
}
