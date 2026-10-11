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
 */
class RegistrarCheckin
{
    public function __construct(
        private readonly RegistroDeIntegracionesTenant $registro,
        private readonly AlertasPlataforma $alertas,
        private readonly FechasNegocioTenant $fechas,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function ejecutar(SesionTenant $sesion, string $proveedor, string $codigo, ?Usuario $actor = null): CheckinTenant
    {
        $validador = $this->registro->resolver($proveedor);

        if ($validador === null || ! $this->registro->activa($proveedor)) {
            throw new IntegracionNoDisponible('La integracion no esta activa en este estudio.');
        }

        $resultado = $validador->validar($codigo, $this->registro->credenciales($proveedor));

        if (! $resultado->valido && $resultado->verificable) {
            throw new CheckinInvalido($resultado->motivo ?? 'Codigo de check-in no valido.');
        }

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
                    'estado' => CheckinTenant::NO_VERIFICABLE,
                    'motivo' => $motivo,
                    'registrado_por_id' => $actor?->getKey(),
                    'registrado_en' => now(),
                ]);
            }

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
                'motivo' => null,
                'intentos' => $pendiente->intentos + 1,
            ])->save();

            return $pendiente;
        }

        try {
            return CheckinTenant::query()->create([
                'sesion_id' => $sesion->getKey(),
                'proveedor' => $proveedor,
                'referencia_externa' => $resultado->referencia,
                'codigo_huella' => self::huella($proveedor, $codigo),
                'nombre_usuario' => $resultado->usuario,
                'estado' => CheckinTenant::VALIDADO,
                'registrado_por_id' => $actor?->getKey(),
                'registrado_en' => now(),
            ]);
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

        $otra = $previo->sesion;
        $hora = $otra instanceof SesionTenant
            ? $otra->inicia_en->setTimezone($otra->zona_horaria ?: $this->fechas->zona())->format('H:i')
            : null;

        throw new CheckinInvalido(self::nombre($previo->proveedor).' cuenta una visita por día y esta ya se registró hoy'
            .($hora !== null ? " en la clase de las {$hora}." : ' en otra clase.'));
    }
}
