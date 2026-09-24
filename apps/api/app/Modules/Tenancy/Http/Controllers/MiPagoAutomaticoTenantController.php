<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\DomiciliacionesTenant;
use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Pago automático" (autoservicio del alumno): sus membresías que se renuevan, cuáles
 * se cobran solas y con qué tarjeta. Activa o quita el cobro automático de cada una y
 * cambia la tarjeta (se autoriza en la página de la pasarela o, con OpenPay, se
 * tokeniza en el navegador: aquí nunca llegan datos de tarjeta).
 */
class MiPagoAutomaticoTenantController
{
    private const RETORNO = '/mi-cuenta';

    public function __construct(
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly DomiciliacionesTenant $domiciliaciones,
    ) {}

    public function mostrar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $proveedor = $this->domiciliaciones->proveedor();
        $tarjeta = $this->domiciliaciones->tarjetaCompartida($persona);

        $membresias = AcuerdoTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereNotNull('proxima_cobro_en')
            ->where('estado', '!=', EstadoAcuerdo::Cancelado->value)
            ->with(['producto', 'domiciliacion'])
            ->orderBy('proxima_cobro_en')
            ->get();
        // Suscripciones creadas que el alumno aún no autoriza en la pasarela.
        $porAutorizar = DomiciliacionTenant::query()
            ->whereIn('acuerdo_id', $membresias->modelKeys())
            ->where('estado', DomiciliacionTenant::PENDIENTE)
            ->pluck('acuerdo_id')
            ->all();

        return response()->json(['data' => [
            'disponible' => $proveedor !== null,
            'tarjeta' => $tarjeta instanceof DomiciliacionTenant ? $tarjeta->tarjeta() : null,
            'membresias' => $membresias->map(static fn (AcuerdoTenant $a): array => [
                'id' => $a->ulid,
                'pendiente' => in_array($a->getKey(), $porAutorizar, false),
                'tarjeta' => $a->domiciliacion?->marca !== null ? $a->domiciliacion->tarjeta() : null,
                'producto' => $a->producto?->nombre,
                'monto_minor' => $a->producto?->precio_minor,
                'moneda' => $a->producto?->moneda,
                'proxima_cobro_en' => $a->proxima_cobro_en?->toDateString(),
                'estado' => $a->estado->value,
                'automatico' => $a->domiciliacion instanceof DomiciliacionTenant,
                'error' => $a->domiciliacion?->ultimo_error,
            ])->all(),
        ]]);
    }

    /**
     * Activa el cobro automático de una membresía: al momento si ya hay tarjeta; si
     * no, devuelve la página de la pasarela para autorizarla, o (OpenPay) lo que
     * necesita su formulario de tarjeta; con la tarjeta ya tokenizada, suscribe.
     */
    public function activar(Request $request): JsonResponse
    {
        $acuerdo = $this->acuerdo($request);
        $datos = $request->validate([
            'token_id' => ['sometimes', 'string', 'max:255'],
            'device_session_id' => ['sometimes', 'string', 'max:255'],
        ]);

        $resultado = $this->domiciliaciones->activar($acuerdo, self::RETORNO, $datos);

        return response()->json(['data' => [
            'estado' => $resultado['estado'],
            'checkout' => isset($resultado['url']) ? ['tipo' => 'redirect', 'url' => $resultado['url']] : null,
            'formulario' => $resultado['formulario'] ?? null,
        ]]);
    }

    public function desactivar(Request $request): JsonResponse
    {
        $this->domiciliaciones->desactivar($this->acuerdo($request));

        return response()->json(['data' => ['estado' => 'manual']]);
    }

    /**
     * Página de la pasarela para autorizar otra tarjeta para todo lo domiciliado.
     */
    public function cambiarTarjeta(Request $request): JsonResponse
    {
        $resultado = $this->domiciliaciones->cambiarTarjeta($this->persona($request), self::RETORNO);

        return response()->json(['data' => ['checkout' => ['tipo' => 'redirect', 'url' => $resultado['url']]]]);
    }

    /**
     * Una membresía del propio alumno (la de otro no existe para él).
     */
    private function acuerdo(Request $request): AcuerdoTenant
    {
        return AcuerdoTenant::query()
            ->where('ulid', (string) $request->route('acuerdo'))
            ->where('persona_id', $this->persona($request)->getKey())
            ->firstOrFail();
    }

    private function persona(Request $request): PersonaTenant
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);
        $persona = $this->personas->buscar($usuario);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de alumno en este negocio.');

        return $persona;
    }
}
