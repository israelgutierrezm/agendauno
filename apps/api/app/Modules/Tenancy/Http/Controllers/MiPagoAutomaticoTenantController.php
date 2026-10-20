<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\DomiciliacionesTenant;
use App\Modules\Tenancy\Application\FamiliasTenant;
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
 *
 * Un responsable ve también las membresías de las personas a su cargo con la
 * autorización vigente (`para`) y las paga con su tarjeta (ADR 0129). Es siempre la
 * cuenta del titular: no actúa por un participante (`X-Participante`).
 *
 * Seguro (ADR 0134): una suscripción que se quitó y la pasarela aún no confirma dice
 * `cancelando`; el monto es el que autorizó (su precio contratado) y, si el del plan
 * cambió, `precio_nuevo_minor` y la opción de autorizarlo.
 */
class MiPagoAutomaticoTenantController
{
    // A Pagos: ahí se ve la tarjeta y se recarga lo pendiente al volver.
    private const RETORNO = '/mi-cuenta/pagos';

    public function __construct(
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly DomiciliacionesTenant $domiciliaciones,
        private readonly FamiliasTenant $familias,
    ) {}

    public function mostrar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $proveedor = $this->domiciliaciones->proveedor();
        $tarjeta = $this->domiciliaciones->tarjetaCompartida($persona);

        $membresias = AcuerdoTenant::query()
            ->whereIn('persona_id', $this->pagables($persona))
            ->whereNotNull('proxima_cobro_en')
            ->where('estado', '!=', EstadoAcuerdo::Cancelado->value)
            ->with(['producto', 'domiciliacion', 'persona'])
            ->orderByRaw('persona_id = ? desc', [$persona->getKey()])
            ->orderBy('proxima_cobro_en')
            ->get();
        // Suscripciones creadas que el alumno aún no autoriza en la pasarela.
        $porAutorizar = DomiciliacionTenant::query()
            ->whereIn('acuerdo_id', $membresias->modelKeys())
            ->where('estado', DomiciliacionTenant::PENDIENTE)
            ->pluck('acuerdo_id')
            ->all();
        // Las que se quitaron y la pasarela aún no confirma (ADR 0134).
        $cancelando = DomiciliacionTenant::query()
            ->whereIn('acuerdo_id', $membresias->modelKeys())
            ->where('estado', DomiciliacionTenant::CANCELANDO)
            ->pluck('acuerdo_id')
            ->all();
        $domiciliaciones = $this->domiciliaciones;

        return response()->json(['data' => [
            'disponible' => $proveedor !== null,
            'tarjeta' => $tarjeta instanceof DomiciliacionTenant ? $tarjeta->tarjeta() : null,
            'membresias' => $membresias->map(static fn (AcuerdoTenant $a): array => [
                ...self::precio($a, $domiciliaciones),
                'id' => $a->ulid,
                // De una persona a su cargo: se paga con la tarjeta del responsable.
                'para' => (int) $a->persona_id !== (int) $persona->getKey() && $a->persona instanceof PersonaTenant
                    ? ['id' => $a->persona->ulid, 'nombre' => $a->persona->nombreCompleto()]
                    : null,
                'pendiente' => in_array($a->getKey(), $porAutorizar, false),
                'cancelando' => in_array($a->getKey(), $cancelando, false),
                'tarjeta' => $a->domiciliacion?->marca !== null ? $a->domiciliacion->tarjeta() : null,
                'producto' => $a->producto?->nombre,
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

        $resultado = $this->domiciliaciones->activar($acuerdo, self::RETORNO, $datos, $this->persona($request));

        return response()->json(['data' => [
            'estado' => $resultado['estado'],
            'checkout' => isset($resultado['url']) ? ['tipo' => 'redirect', 'url' => $resultado['url']] : null,
            'formulario' => $resultado['formulario'] ?? null,
        ]]);
    }

    /**
     * Autoriza el precio nuevo del plan para su pago automático (ADR 0134): con
     * tarjeta domiciliada, al momento; con suscripción, se autoriza otra en la pasarela
     * (o, con OpenPay, se captura la tarjeta) y la anterior se cancela al quedar activa.
     */
    public function autorizarPrecio(Request $request): JsonResponse
    {
        $acuerdo = $this->acuerdo($request);
        $datos = $request->validate([
            'token_id' => ['sometimes', 'string', 'max:255'],
            'device_session_id' => ['sometimes', 'string', 'max:255'],
        ]);
        $usuario = $request->attributes->get('usuario_tenant');

        $resultado = $this->domiciliaciones->autorizarNuevoPrecio(
            $acuerdo, self::RETORNO, $datos, $this->persona($request), $usuario instanceof Usuario ? $usuario : null,
        );

        return response()->json(['data' => [
            'estado' => $resultado['estado'],
            'checkout' => isset($resultado['url']) ? ['tipo' => 'redirect', 'url' => $resultado['url']] : null,
            'formulario' => $resultado['formulario'] ?? null,
        ]]);
    }

    /**
     * Lo que se cobra de la membresía: con pago automático, el precio que autorizó (y
     * el nuevo, si el del plan cambió); sin él, el del plan.
     *
     * @return array{monto_minor: int|null, moneda: string|null, precio_nuevo_minor: int|null}
     */
    private static function precio(AcuerdoTenant $acuerdo, DomiciliacionesTenant $domiciliaciones): array
    {
        $contratado = $domiciliaciones->precioContratado($acuerdo);

        return [
            'monto_minor' => $contratado['monto_minor'] ?? $acuerdo->producto?->precio_minor,
            'moneda' => $contratado['moneda'] ?? $acuerdo->producto?->moneda,
            'precio_nuevo_minor' => $domiciliaciones->nuevoPrecio($acuerdo),
        ];
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
     * Una membresía del propio alumno o de alguien a su cargo con la autorización
     * vigente (la de otro no existe para él).
     */
    private function acuerdo(Request $request): AcuerdoTenant
    {
        return AcuerdoTenant::query()
            ->where('ulid', (string) $request->route('acuerdo'))
            ->whereIn('persona_id', $this->pagables($this->persona($request)))
            ->firstOrFail();
    }

    /**
     * Las personas cuyas membresías paga: él y las que tiene a su cargo con la
     * autorización vigente.
     *
     * @return list<int>
     */
    private function pagables(PersonaTenant $persona): array
    {
        return [(int) $persona->getKey(), ...$this->familias->participantesAutorizados($persona)->modelKeys()];
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
