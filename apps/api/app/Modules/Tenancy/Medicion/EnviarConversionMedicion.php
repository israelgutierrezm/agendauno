<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

use Carbon\CarbonImmutable;

/**
 * Manda una conversión a un destino (ADR 0143), el mismo camino para el SaaS y para
 * cada negocio. Antes de mandarla vuelve a revisar todo, porque pudo cambiar desde que
 * se creó:
 *
 * - fuera de producción no sale nada a un destino real (salvo que se encienda a
 *   propósito para un entorno de pruebas);
 * - GA4 necesita la autorización de analítica y el `client_id` de su navegador;
 * - Meta necesita la de publicidad y, si pasó en la web, el agente de su navegador;
 * - pasada la ventana del proveedor, ya no se manda.
 *
 * Lo que no se manda queda «omitido» con su motivo; nunca se inventa un dato.
 */
class EnviarConversionMedicion
{
    public function __construct(
        private readonly Ga4MeasurementProtocol $ga4,
        private readonly MetaConversionesApi $meta,
    ) {}

    public function enviar(
        ProveedorMedicion $proveedor,
        string $identificador,
        #[\SensitiveParameter] string $secreto,
        ?string $codigoPrueba,
        ConversionMedicion $conversion,
        ?ContextoConversion $contexto,
        CarbonImmutable $ahora,
    ): ResultadoEnvio {
        $motivo = self::motivoParaOmitir($proveedor, $conversion, $contexto, $ahora);
        if ($motivo !== null || $contexto === null) {
            return ResultadoEnvio::omitido($motivo ?? 'sin_contexto');
        }

        return $proveedor === ProveedorMedicion::Ga4
            ? $this->ga4->enviar($identificador, $secreto, $conversion, $contexto)
            : $this->meta->enviar($identificador, $secreto, $codigoPrueba, $conversion, $contexto);
    }

    public static function motivoParaOmitir(ProveedorMedicion $proveedor, ConversionMedicion $c, ?ContextoConversion $ctx, CarbonImmutable $ahora): ?string
    {
        if (! app()->environment('production') && ! config('agendauno.medicion.enviar_fuera_de_produccion')) {
            return 'entorno_de_pruebas';
        }
        if ($c->nombrePara($proveedor) === null) {
            return 'no_aplica';
        }
        if ($c->ocurridoEn->lessThan($ahora->subHours($proveedor->horasMaximas()))) {
            return 'fuera_de_ventana';
        }
        if ($ctx === null) {
            return 'sin_contexto';
        }

        return match ($proveedor) {
            ProveedorMedicion::Ga4 => ! $ctx->analitica ? 'preferencias' : ($ctx->gaClientId === null ? 'sin_identificador' : null),
            ProveedorMedicion::Meta => ! $ctx->publicidad ? 'preferencias' : (self::sinDatosParaMeta($c, $ctx) ? 'sin_identificador' : null),
        };
    }

    private static function sinDatosParaMeta(ConversionMedicion $c, ContextoConversion $ctx): bool
    {
        // En la web, Meta pide el agente del navegador; fuera de ella, al menos un
        // identificador con que relacionarlo.
        if ($c->accion === 'website') {
            return $ctx->agente === null;
        }

        return $ctx->visitante === null && $ctx->fbp === null && $ctx->fbc === null && $ctx->ip === null;
    }
}
