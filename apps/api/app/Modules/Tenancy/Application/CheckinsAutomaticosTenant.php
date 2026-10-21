<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Integraciones\ClienteTotalPass;
use App\Modules\Tenancy\Integraciones\RegistroDeIntegracionesTenant;
use App\Modules\Tenancy\Integraciones\ResultadoCheckin;
use App\Modules\Tenancy\Integraciones\ValidadorWellhub;
use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\VisitantePlataformaTenant;
use App\Modules\Tenancy\ProveedorPartner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * El check-in automático de Wellhub y TotalPass (ADR 0148): la persona hace check-in en
 * la app de su plataforma, la plataforma avisa y la visita se valida sin que recepción
 * capture nada.
 *
 * - Wellhub manda el aviso `checkin` (firmado) con el Wellhub ID, nombre, correo y
 *   teléfono; se valida con su Access Control API, igual que a mano (la misma
 *   referencia: una visita por día).
 * - TotalPass manda el aviso de check-in con un token; confirmarlo lo valida.
 *
 * El aviso se guarda al llegar y se procesa aparte ({@see AvisosPlataformaTenant}):
 * Wellhub espera respuesta en un segundo. La persona queda como visitante de la
 * plataforma (nunca como persona del negocio) y la visita, en la clase de ese momento si
 * se sabe cuál; si no, sin clase hasta que recepción la asigne. Si hizo check-in a la
 * clase que reservó desde la app de Wellhub (ADR 0149), la visita va a esa clase.
 *
 * Si la plataforma no responde, se reintenta mientras el check-in siga vigente; después
 * la visita queda «sin verificar» (nunca aprobada) y la plataforma recibe una alerta.
 * Una visita rechazada no se guarda.
 */
class CheckinsAutomaticosTenant
{
    /** Intentos de validar un aviso antes de darlo por no verificable. */
    private const MAX_INTENTOS = AvisosPlataformaTenant::MAX_INTENTOS;

    /** Cuánto dura el check-in en la app de Wellhub. */
    private const MINUTOS_WELLHUB = 20;

    /** Cuánto se intenta confirmar uno de TotalPass si su aviso no dice cuándo vence. */
    private const MINUTOS_TOTALPASS = 30;

    public function __construct(
        private readonly RegistroDeIntegracionesTenant $registro,
        private readonly ValidadorWellhub $wellhub,
        private readonly ClienteTotalPass $totalpass,
        private readonly VisitantesPlataformaTenant $visitantes,
        private readonly LugaresPlataformaTenant $lugares,
        private readonly AlertasPlataforma $alertas,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /** Procesa un aviso de check-in (lo llama la bandeja con la integración activa). */
    public function procesar(EventoPlataformaTenant $evento, ProveedorPartner $proveedor): void
    {
        match ($proveedor) {
            ProveedorPartner::Wellhub => $this->wellhub($evento),
            ProveedorPartner::TotalPass => $this->totalPass($evento),
            // Sin avisos de Fitpass (ADR 0197).
            ProveedorPartner::Fitpass => null,
        };
    }

    private function wellhub(EventoPlataformaTenant $evento): void
    {
        $datos = $evento->payload;
        $credenciales = $this->registro->credenciales(ProveedorPartner::Wellhub->value);
        $gimnasio = (string) Arr::get($datos, 'event_data.gym.id', '');
        if ($gimnasio !== '' && ($credenciales['gym_id'] ?? '') !== '' && $gimnasio !== (string) $credenciales['gym_id']) {
            $evento->terminar(EventoPlataformaTenant::DESCARTADO, 'El aviso es de otro gimnasio de Wellhub.');

            return;
        }
        $wellhubId = (string) preg_replace('/\D/', '', (string) Arr::get($datos, 'event_data.user.unique_token', ''));
        $cuando = self::momento(Arr::get($datos, 'event_data.timestamp'), $evento);
        $visitante = $this->visitantes->registrar(ProveedorPartner::Wellhub->value, $wellhubId, [
            'nombre' => trim(Arr::get($datos, 'event_data.user.first_name', '').' '.Arr::get($datos, 'event_data.user.last_name', '')),
            'email' => Arr::get($datos, 'event_data.user.email'),
            'telefono' => Arr::get($datos, 'event_data.user.phone_number') ?? Arr::get($datos, 'event_data.user.phone-number'),
            'producto' => Arr::get($datos, 'event_data.gym.product.description') ?? Arr::get($datos, 'event_data.product.description'),
        ], $cuando);

        // Check-in a la clase que reservó desde la app (ADR 0149): va a esa clase.
        $sesion = null;
        $limite = $cuando->addMinutes(self::MINUTOS_WELLHUB);
        if ($evento->tipo === AvisosPlataformaTenant::CHECKIN_RESERVA) {
            $sesion = LugarPlataformaTenant::query()
                ->where('referencia_externa', ReservasPlataformaTenant::referencia(ProveedorPartner::Wellhub, (string) Arr::get($datos, 'event_data.booking.booking_number')))
                ->first()?->sesion;
            $vence = Arr::get($datos, 'event_data.expires_at');
            if (is_numeric($vence)) {
                $limite = self::momento($vence, $evento);
            }
        }

        $resultado = $this->wellhub->validar($wellhubId, $credenciales);
        $this->asentar($evento, ProveedorPartner::Wellhub, $resultado, $visitante, $cuando, $limite, $sesion);
    }

    private function totalPass(EventoPlataformaTenant $evento): void
    {
        $datos = $evento->payload;
        $token = (string) self::tokenTotalPass($datos);
        $cuando = self::momento(Arr::get($datos, 'check_in.started_at'), $evento);
        $vence = Arr::get($datos, 'check_in.expires_at');
        $limite = is_string($vence) && strtotime($vence) !== false
            ? CarbonImmutable::parse($vence)
            : $cuando->addMinutes(self::MINUTOS_TOTALPASS);

        $usuario = (array) Arr::get($datos, 'user', []);
        $codigo = trim((string) ($usuario['code'] ?? ''));
        $visitante = $codigo === '' ? null : $this->visitantes->registrar(ProveedorPartner::TotalPass->value, $codigo, [
            'nombre' => $usuario['name'] ?? trim(($usuario['first_name'] ?? '').' '.($usuario['last_name'] ?? '')),
            'email' => $usuario['email'] ?? null,
            'telefono' => $usuario['phone'] ?? ($usuario['phone_number'] ?? null),
            'producto' => Arr::get($datos, 'check_in.plan_code'),
        ], $cuando);

        $llave = $this->registro->credenciales(ProveedorPartner::TotalPass->value)['place_api_key'] ?? '';
        $resultado = $this->totalpass->confirmar($llave, $token, 'totalpass:checkin:'.substr(hash('sha256', $token), 0, 40));
        $this->asentar($evento, ProveedorPartner::TotalPass, $resultado, $visitante, $cuando, $limite);
    }

    /**
     * Lo que dijo la plataforma: aprobada (se registra la visita), rechazada (no se
     * guarda) o no verificable (se reintenta mientras el check-in siga vigente; después
     * queda «sin verificar»).
     */
    private function asentar(
        EventoPlataformaTenant $evento,
        ProveedorPartner $proveedor,
        ResultadoCheckin $resultado,
        ?VisitantePlataformaTenant $visitante,
        CarbonImmutable $cuando,
        CarbonInterface $limite,
        ?SesionTenant $sesion = null,
    ): void {
        if ($resultado->valido) {
            $previo = CheckinTenant::query()->where('referencia_externa', $resultado->referencia)->first();
            if ($previo instanceof CheckinTenant) {
                // Recepción ya la registró a mano: es la misma visita.
                if ($previo->visitante_id === null && $visitante instanceof VisitantePlataformaTenant) {
                    $previo->visitante_id = (int) $visitante->getKey();
                    $previo->save();
                }
            } else {
                $this->registrarVisita($proveedor, CheckinTenant::VALIDADO, $resultado->referencia, $visitante, $cuando, null, $sesion);
            }
            $evento->terminar(EventoPlataformaTenant::PROCESADO, 'Visita validada.');

            return;
        }

        if ($resultado->verificable) {
            $evento->terminar(EventoPlataformaTenant::DESCARTADO, $resultado->motivo ?? 'La plataforma no validó la visita.');

            return;
        }

        $evento->intentos++;
        $evento->resultado = Str::limit((string) $resultado->motivo, 290);
        if ($evento->intentos < self::MAX_INTENTOS && now()->lessThan($limite)) {
            $evento->save();

            return;
        }
        $this->registrarVisita($proveedor, CheckinTenant::NO_VERIFICABLE, null, $visitante, $cuando, $resultado->motivo, $sesion);
        $this->alertas->registrar(
            'bienestar_no_verificable',
            ($this->gestor->actual()->slug ?? '').':'.$proveedor->value,
            $proveedor->nombre().' no pudo verificar una visita automática: '.$resultado->motivo,
        );
        $evento->terminar(EventoPlataformaTenant::PROCESADO, 'Sin verificar: '.$resultado->motivo);
    }

    private function registrarVisita(
        ProveedorPartner $proveedor,
        string $estado,
        ?string $referencia,
        ?VisitantePlataformaTenant $visitante,
        CarbonImmutable $cuando,
        ?string $motivo,
        ?SesionTenant $sesion,
    ): void {
        $sesion ??= $this->visitantes->sesionDeVisita($visitante, $cuando);
        try {
            $checkin = CheckinTenant::query()->create([
                'sesion_id' => $sesion?->getKey(),
                'proveedor' => $proveedor->value,
                'referencia_externa' => $referencia,
                'nombre_usuario' => $visitante instanceof VisitantePlataformaTenant ? ($visitante->nombre ?? $visitante->id_externo) : null,
                'visitante_id' => $visitante?->getKey(),
                'origen' => CheckinTenant::ORIGEN_AUTOMATICO,
                'estado' => $estado,
                'motivo' => $motivo !== null ? Str::limit($motivo, 290) : null,
                'registrado_en' => $cuando,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Recepción la registró a mano en este momento: es la misma visita.
            return;
        }
        $this->lugares->ocuparPorVisita($checkin);
    }

    /**
     * El token de confirmación del aviso de TotalPass: viene al final de la dirección de
     * confirmación (`…/webhook_confirmations/{TOKEN}`) o suelto.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function tokenTotalPass(array $datos): ?string
    {
        foreach (['endpoint', 'confirmation_url', 'check_in.endpoint', 'check_in.confirmation_url'] as $campo) {
            $url = Arr::get($datos, $campo);
            if (is_string($url) && preg_match('#webhook_confirmations/([A-Za-z0-9_\-]+)#', $url, $m) === 1) {
                return $m[1];
            }
        }
        $suelto = Arr::get($datos, 'token');

        return is_string($suelto) && $suelto !== '' ? $suelto : null;
    }

    /** El momento del check-in (segundos o milisegundos, o fecha); si no viene, cuando llegó el aviso. */
    private static function momento(mixed $valor, EventoPlataformaTenant $evento): CarbonImmutable
    {
        if (is_numeric($valor)) {
            $segundos = (int) $valor > 100_000_000_000 ? intdiv((int) $valor, 1000) : (int) $valor;

            return CarbonImmutable::createFromTimestamp($segundos);
        }
        if (is_string($valor) && strtotime($valor) !== false) {
            return CarbonImmutable::parse($valor);
        }

        return CarbonImmutable::instance($evento->recibido_en);
    }
}
