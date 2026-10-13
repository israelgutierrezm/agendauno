<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\CheckinInvalido;
use App\Modules\Tenancy\Exceptions\CheckinNoVerificable;
use App\Modules\Tenancy\Exceptions\IntegracionNoDisponible;
use App\Modules\Tenancy\Integraciones\RegistroDeIntegracionesTenant;
use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ProveedorPartner;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Registra el check-in de un usuario de una plataforma de bienestar (Wellhub /
 * TotalPass) en una sesion: valida el codigo contra la API del proveedor con las
 * credenciales del estudio y guarda el acceso. NO consume creditos del estudio (la
 * plataforma cubre la clase).
 *
 * Tres desenlaces (M10): aprobado (se registra `validado`), rechazado (no se guarda
 * nada) o no verificable (el proveedor no respondió: queda `no_verificable`, nunca
 * aprobado, y la plataforma recibe una alerta). Reintentar con el mismo código en la
 * misma sesión retoma esa visita. Idempotente por `referencia_externa` dentro de la
 * sesión: la misma visita aprobada no se registra dos veces, ni en otra clase del día.
 *
 * Quien viene por la plataforma queda como su visitante (ADR 0148), nunca como persona
 * del negocio, y su visita ocupa un lugar en la clase. Una visita que llegó sola sin
 * clase (la persona hizo check-in en la app) se asigna a la clase donde se registra.
 */
class RegistrarCheckin
{
    public function __construct(
        private readonly RegistroDeIntegracionesTenant $registro,
        private readonly AlertasPlataforma $alertas,
        private readonly FechasNegocioTenant $fechas,
        private readonly GestorDeConexionTenant $gestor,
        private readonly VisitantesPlataformaTenant $visitantes,
        private readonly LugaresPlataformaTenant $lugares,
    ) {}

    public function ejecutar(SesionTenant $sesion, string $proveedor, string $codigo, ?Usuario $actor = null): CheckinTenant
    {
        $validador = $this->registro->resolver($proveedor);

        if ($validador === null || ! $this->registro->activa($proveedor)) {
            throw new IntegracionNoDisponible('La integración no está activa en este negocio.');
        }

        $resultado = $validador->validar($codigo, $this->registro->credenciales($proveedor));

        if (! $resultado->valido && $resultado->verificable) {
            throw new CheckinInvalido($resultado->motivo ?? 'Codigo de check-in no valido.');
        }
        // Wellhub da el Wellhub ID de quien viene; con el token del día de TotalPass no se
        // sabe quién es.
        $visitante = $proveedor === ProveedorPartner::Wellhub->value && $resultado->usuario !== null
            ? $this->visitantes->registrar($proveedor, $resultado->usuario, [], now())
            : null;

        // La misma visita intentada antes en esta sesión (sin verificar o como excepción).
        $pendiente = CheckinTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->where('proveedor', $proveedor)
            ->where('codigo_huella', self::huella($proveedor, $codigo))
            ->whereIn('estado', [CheckinTenant::NO_VERIFICABLE, CheckinTenant::EXCEPCION])
            ->first();

        if (! $resultado->valido) {
            $motivo = $resultado->motivo ?? 'La plataforma no pudo verificar la visita.';
            $this->alertas->registrar('bienestar_no_verificable', ($this->gestor->actual()->slug ?? '').':'.$proveedor, self::nombre($proveedor).' no pudo verificar una visita: '.$motivo);

            if ($pendiente instanceof CheckinTenant) {
                $pendiente->intentos++;
                if ($pendiente->estado === CheckinTenant::NO_VERIFICABLE) {
                    $pendiente->motivo = $motivo;
                }
                $pendiente->save();
            } else {
                $pendiente = CheckinTenant::query()->create([
                    'sesion_id' => $sesion->getKey(),
                    'proveedor' => $proveedor,
                    'codigo_huella' => self::huella($proveedor, $codigo),
                    'nombre_usuario' => $resultado->usuario,
                    'visitante_id' => $visitante?->getKey(),
                    'origen' => CheckinTenant::ORIGEN_MANUAL,
                    'estado' => CheckinTenant::NO_VERIFICABLE,
                    'motivo' => $motivo,
                    'registrado_por_id' => $actor?->getKey(),
                    'registrado_en' => now(),
                ]);
            }
            // La persona está en la clase aunque la plataforma no respondiera.
            $this->lugares->ocuparPorVisita($pendiente);

            throw new CheckinNoVerificable($motivo, (string) $pendiente->ulid);
        }

        // Ya aprobada: en esta sesión es la misma; en otra clase del día, no cuenta dos veces.
        $previo = CheckinTenant::query()->where('referencia_externa', $resultado->referencia)->first();
        if ($previo instanceof CheckinTenant) {
            return $this->mismaSesion($previo, $sesion);
        }

        if ($pendiente instanceof CheckinTenant) {
            $pendiente->fill([
                'estado' => CheckinTenant::VALIDADO,
                'referencia_externa' => $resultado->referencia,
                'nombre_usuario' => $resultado->usuario ?? $pendiente->nombre_usuario,
                'visitante_id' => $pendiente->visitante_id ?? $visitante?->getKey(),
                'motivo' => null,
                'intentos' => $pendiente->intentos + 1,
            ])->save();
            $this->lugares->ocuparPorVisita($pendiente);

            return $pendiente;
        }

        try {
            $checkin = CheckinTenant::query()->create([
                'sesion_id' => $sesion->getKey(),
                'proveedor' => $proveedor,
                'referencia_externa' => $resultado->referencia,
                'codigo_huella' => self::huella($proveedor, $codigo),
                'nombre_usuario' => $resultado->usuario,
                'visitante_id' => $visitante?->getKey(),
                'origen' => CheckinTenant::ORIGEN_MANUAL,
                'estado' => CheckinTenant::VALIDADO,
                'registrado_por_id' => $actor?->getKey(),
                'registrado_en' => now(),
            ]);
            $this->lugares->ocuparPorVisita($checkin);

            return $checkin;
        } catch (UniqueConstraintViolationException) {
            // Dos validaciones del mismo código a la vez: la otra ya la guardó.
            return $this->mismaSesion(CheckinTenant::query()->where('referencia_externa', $resultado->referencia)->firstOrFail(), $sesion);
        }
    }

    /** Huella del código (para reconocer un reintento sin guardar el código). */
    public static function huella(string $proveedor, string $codigo): string
    {
        return hash('sha256', $proveedor.':'.strtoupper((string) preg_replace('/[\s-]+/', '', $codigo)));
    }

    private static function nombre(string $proveedor): string
    {
        return ProveedorPartner::tryFrom($proveedor)?->nombre() ?? $proveedor;
    }

    private function mismaSesion(CheckinTenant $previo, SesionTenant $sesion): CheckinTenant
    {
        if ((int) $previo->sesion_id === (int) $sesion->getKey()) {
            return $previo;
        }
        // Llegó sola y sin clase (ADR 0148): es de la clase donde recepción la registra.
        if ($previo->sesion_id === null) {
            $previo->sesion_id = (int) $sesion->getKey();
            $previo->save();
            $this->lugares->ocuparPorVisita($previo);

            return $previo;
        }

        $otra = $previo->sesion;
        $hora = $otra instanceof SesionTenant
            ? $otra->inicia_en->setTimezone($otra->zona_horaria ?: $this->fechas->zona())->format('H:i')
            : null;

        throw new CheckinInvalido(self::nombre($previo->proveedor).' cuenta una visita por día y esta ya se registró hoy'
            .($hora !== null ? " en la clase de las {$hora}." : ' en otra clase.'));
    }
}
