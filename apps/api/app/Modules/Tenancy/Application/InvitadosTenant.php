<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\InvitadoSesionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\Exceptions\CupoLleno;
use App\Modules\Tenancy\Reservas\Exceptions\FueraDeVentana;
use App\Modules\Tenancy\Reservas\Exceptions\PaseDeInvitadoNoDisponible;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pases de invitado (ADR 0182): un plan puede incluir N invitados al mes. Quien lo
 * tiene aparta un lugar en una clase para alguien que no es cliente del negocio (solo
 * su nombre); el invitado ocupa cupo, no consume créditos y gasta un pase del mes de la
 * clase. Cancelar antes de que empiece devuelve el pase y el lugar.
 *
 * Con el candado de la sesión (cupo) y del derecho (pases del mes): dos invitaciones al
 * mismo tiempo no gastan el mismo pase ni el mismo lugar.
 */
class InvitadosTenant
{
    public function __construct(
        private readonly ResolverDerechoTenant $resolver,
        private readonly CupoDeSesionTenant $cupos,
        private readonly VentanaDeReservaTenant $ventana,
        private readonly RegistrarEventoTenant $eventos,
    ) {}

    /**
     * Los planes de la persona con pases de invitado, y cuántos le quedan en el mes del
     * momento dado (por omisión, este mes).
     *
     * @return list<array{derecho: DerechoTenant, por_mes: int, usados: int, quedan: int}>
     */
    public function pases(PersonaTenant $persona, ?CarbonImmutable $mes = null, ?SesionTenant $sesion = null): array
    {
        $derechos = $sesion !== null
            ? $this->resolver->candidatosPara($persona, $sesion)
            : $this->resolver->vigentesDe($persona);
        $salida = [];
        foreach ($derechos as $derecho) {
            $porMes = (int) ($derecho->invitados_por_mes ?? 0);
            if ($porMes <= 0) {
                continue;
            }
            $usados = $this->usadosEnElMes($derecho, $mes ?? CarbonImmutable::now(), $sesion);
            $salida[] = ['derecho' => $derecho, 'por_mes' => $porMes, 'usados' => $usados, 'quedan' => max(0, $porMes - $usados)];
        }

        return $salida;
    }

    /** Cuántos pases le quedan para esa clase (0 si su plan no trae o ya los usó). */
    public function quedanPara(PersonaTenant $persona, SesionTenant $sesion): int
    {
        return array_sum(array_column($this->pases($persona, CarbonImmutable::instance($sesion->inicia_en), $sesion), 'quedan'));
    }

    public function invitar(SesionTenant $sesion, PersonaTenant $anfitrion, string $nombre): InvitadoSesionTenant
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '');
        // La misma ventana que para reservar (con el acceso anticipado de su plan).
        $this->ventana->exigirParaClase($sesion, $anfitrion);

        return DB::connection('tenant')->transaction(function () use ($sesion, $anfitrion, $nombre): InvitadoSesionTenant {
            $bloqueada = SesionTenant::query()->whereKey($sesion->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueada->estado !== EstadoSesionTenant::Programada || $bloqueada->inicia_en->isPast()) {
                throw new SesionNoReservable('Esa clase ya no admite invitados.');
            }
            if (InvitadoSesionTenant::query()->where('sesion_id', $bloqueada->getKey())->where('anfitrion_id', $anfitrion->getKey())
                ->whereIn('estado', InvitadoSesionTenant::OCUPAN_LUGAR)->exists()) {
                throw new PaseDeInvitadoNoDisponible('Ya tienes un invitado en esa clase.');
            }

            $mes = CarbonImmutable::instance($bloqueada->inicia_en);
            $con = collect($this->pases($anfitrion, $mes, $bloqueada))->first(static fn (array $p): bool => $p['quedan'] > 0);
            if ($con === null) {
                throw new PaseDeInvitadoNoDisponible('Tu plan no tiene pases de invitado disponibles para ese mes.');
            }
            // Con el derecho bloqueado se vuelven a contar los pases del mes.
            $derecho = DerechoTenant::query()->whereKey($con['derecho']->getKey())->lockForUpdate()->firstOrFail();
            if ($this->usadosEnElMes($derecho, $mes, $bloqueada) >= (int) $derecho->invitados_por_mes) {
                throw new PaseDeInvitadoNoDisponible('Tu plan no tiene pases de invitado disponibles para ese mes.');
            }
            if ($bloqueada->capacidad !== null && (int) $this->cupos->disponibles($bloqueada) <= 0) {
                throw new CupoLleno('La clase está llena.');
            }

            $invitado = InvitadoSesionTenant::query()->create([
                'sesion_id' => $bloqueada->getKey(),
                'anfitrion_id' => $anfitrion->getKey(),
                'derecho_id' => $derecho->getKey(),
                'nombre' => $nombre,
                'estado' => InvitadoSesionTenant::RESERVADO,
            ]);
            // Sin el nombre del invitado: el evento solo dice quién invitó y a qué clase.
            $this->eventos->registrar('invitado.registrado', 'invitado', (string) $invitado->ulid, [
                'sesion_id' => $bloqueada->ulid,
                'anfitrion_id' => $anfitrion->ulid,
            ]);

            return $invitado;
        });
    }

    /** Cancela la invitación: libera el lugar y, antes de que empiece la clase, el pase. */
    public function cancelar(InvitadoSesionTenant $invitado): InvitadoSesionTenant
    {
        return DB::connection('tenant')->transaction(function () use ($invitado): InvitadoSesionTenant {
            $bloqueado = InvitadoSesionTenant::query()->whereKey($invitado->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado !== InvitadoSesionTenant::RESERVADO) {
                return $bloqueado;
            }
            $sesion = SesionTenant::query()->whereKey($bloqueado->sesion_id)->lockForUpdate()->firstOrFail();
            if ($sesion->inicia_en->isPast()) {
                throw new FueraDeVentana('La clase ya empezó: la invitación ya no se cancela.');
            }
            $bloqueado->update(['estado' => InvitadoSesionTenant::CANCELADO, 'cancelado_en' => now()]);

            return $bloqueado;
        });
    }

    /** El negocio marca si el invitado llegó. */
    public function marcar(InvitadoSesionTenant $invitado, bool $asistio, ?Usuario $actor = null): InvitadoSesionTenant
    {
        if ($invitado->estado === InvitadoSesionTenant::CANCELADO) {
            throw new SesionNoReservable('Esa invitación se canceló.');
        }
        $invitado->update([
            'estado' => $asistio ? InvitadoSesionTenant::ASISTIO : InvitadoSesionTenant::NO_ASISTIO,
            'asistencia_en' => now(),
        ]);
        app(RegistrarAuditoria::class)->registrar($actor, 'invitado.asistencia', 'invitado', (string) $invitado->ulid,
            null, ['estado' => $invitado->estado]);

        return $invitado;
    }

    /**
     * Los pases que ese plan ya gastó en el mes de `$mes` (por la fecha de la clase, en
     * la zona de la sede).
     */
    private function usadosEnElMes(DerechoTenant $derecho, CarbonImmutable $mes, ?SesionTenant $sesion = null): int
    {
        $zona = (string) ($sesion?->zona_horaria ?: 'UTC');
        $inicio = $mes->setTimezone($zona)->startOfMonth();

        return InvitadoSesionTenant::query()
            ->where('derecho_id', $derecho->getKey())
            ->whereIn('estado', InvitadoSesionTenant::GASTAN_PASE)
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>=', $inicio->utc())->where('inicia_en', '<', $inicio->addMonthNoOverflow()->utc()))
            ->count();
    }
}
