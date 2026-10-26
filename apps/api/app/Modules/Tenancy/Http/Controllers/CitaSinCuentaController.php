<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EnlaceDeCitaTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Reservas\QuienCancela;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La cita desde el enlace de sus avisos, sin cuenta (ADR 0221): verla y cancelarla con
 * las mismas reglas que desde la cuenta (hasta cuándo, qué pasa con lo pagado). Cambiar
 * el horario va en {@see ReprogramarCitaSinCuentaController}. Un enlace que no es de la
 * cita responde 404, como una cita que no existe.
 */
class CitaSinCuentaController
{
    public function __construct(
        private readonly EnlaceDeCitaTenant $enlaces,
        private readonly ReservasTenant $reservas,
    ) {}

    public function mostrar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($request, self::reserva($request, $this->enlaces))]);
    }

    public function cancelar(Request $request): JsonResponse
    {
        $reserva = self::reserva($request, $this->enlaces);
        $this->reservas->cancelar($reserva, QuienCancela::Cliente);

        return response()->json(['data' => $this->presentar($request, $reserva->refresh())]);
    }

    /** La cita del enlace (con su sesión y su servicio), o 404. */
    public static function reserva(Request $request, EnlaceDeCitaTenant $enlaces): ReservaTenant
    {
        $ulid = (string) $request->route('reserva');
        $reserva = $enlaces->valida($ulid, (string) $request->input('f', ''))
            ? ReservaTenant::query()->where('ulid', $ulid)->with('sesion.oferta')->first()
            : null;
        abort_unless($reserva instanceof ReservaTenant && $reserva->sesion?->esCita() === true, 404,
            'Este enlace no es válido. Revisa el correo de tu cita o comunícate con el negocio.');

        return $reserva;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Request $request, ReservaTenant $reserva): array
    {
        $sesion = $reserva->sesion;
        abort_unless($sesion instanceof SesionTenant, 404);
        $sesion->loadMissing(['sucursal', 'instructor']);
        $zona = (string) ($sesion->zona_horaria ?: $sesion->sucursal?->zona_horaria ?: 'America/Mexico_City');
        $estudio = $request->attributes->get('estudio');

        return [
            'id' => $reserva->ulid,
            'estado' => $reserva->estado->value,
            'negocio' => $estudio instanceof Estudio ? (string) $estudio->nombre : null,
            'servicio' => (string) $sesion->oferta?->nombre,
            'con' => $sesion->instructor?->nombreCorto(),
            'sucursal' => $sesion->sucursal?->nombre,
            'direccion' => $sesion->sucursal?->direccion,
            'inicia_en' => $sesion->inicia_en->toIso8601String(),
            'termina_en' => $sesion->termina_en->toIso8601String(),
            'inicia_local' => CarbonImmutable::instance($sesion->inicia_en)->setTimezone($zona)->format('Y-m-d\TH:i'),
            'zona_horaria' => $zona,
            // Qué pasa si la cancela ahora: lo mismo que se le cobra o devuelve al hacerlo.
            'cancelar' => $this->reservas->efectoDeCancelar($reserva, QuienCancela::Cliente)->toArray(),
        ];
    }
}
