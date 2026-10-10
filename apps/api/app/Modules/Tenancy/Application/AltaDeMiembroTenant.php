<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\PersonaDadaDeBaja;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;

/**
 * Da de alta una ficha (miembro por omisión) con las reglas de siempre: el correo es de
 * la persona para siempre (si estaba dada de baja, se reactiva con su historial); con el
 * celular no se reactiva sola (los números cambian de dueño): el negocio decide si se lo
 * quita a la ficha anterior. Lo usan el alta en Miembros y convertir un prospecto.
 */
class AltaDeMiembroTenant
{
    public function __construct(
        private readonly BajasTenant $bajas,
        private readonly WhatsAppTenant $whatsapp,
        private readonly CelularesTenant $celulares,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * @param  array{nombre: string, segundo_nombre?: string|null, primer_apellido?: string|null, segundo_apellido?: string|null, email?: string|null, celular?: string|null, fecha_nacimiento?: string|null, genero?: string|null, tipo?: string|null, es_facturable?: bool|null}  $datos
     * @return array{persona: PersonaTenant, reactivado: bool}
     *
     * @throws PersonaDadaDeBaja si el celular es de una ficha dada de baja y no se pidió liberarlo
     */
    public function alta(array $datos, ?Usuario $actor, ?int $sucursalId, bool $liberarCelular = false, bool $aceptaWhatsApp = false): array
    {
        $email = $datos['email'] ?? null;
        $conCorreo = is_string($email) && $email !== ''
            ? PersonaTenant::onlyTrashed()->where('email', $email)->first()
            : null;
        if ($conCorreo instanceof PersonaTenant) {
            $this->bajas->reactivarPersona($conCorreo, $actor, 'Se dio de alta de nuevo con su correo.');
            $conCorreo->update(array_filter([
                'nombre' => $datos['nombre'],
                'segundo_nombre' => $datos['segundo_nombre'] ?? null,
                'primer_apellido' => $datos['primer_apellido'] ?? null,
                'segundo_apellido' => $datos['segundo_apellido'] ?? null,
                'celular' => $datos['celular'] ?? null,
                'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
                'genero' => $datos['genero'] ?? null,
            ], static fn (mixed $v): bool => $v !== null && $v !== '') + ['activo' => true, 'archivado' => false]);
            if ($aceptaWhatsApp) {
                $this->whatsapp->registrarPorEquipo($conCorreo, true, $actor);
            }

            return ['persona' => $conCorreo->refresh(), 'reactivado' => true];
        }

        // Se compara como número: el «55 1234 5678» de antes es el «+52 5512345678» de hoy.
        $celular = $datos['celular'] ?? null;
        $conCelular = is_string($celular) && $celular !== ''
            ? $this->celulares->dadaDeBaja($celular)
            : null;
        if ($conCelular instanceof PersonaTenant) {
            if (! $liberarCelular) {
                throw new PersonaDadaDeBaja($conCelular);
            }
            $anterior = $conCelular->celular;
            $conCelular->forceFill(['celular' => null])->save();
            $this->auditoria->registrar($actor, 'miembro.celular_liberado', 'persona', (string) $conCelular->ulid, ['celular' => $anterior], ['celular' => null], 'El número es de otra persona.');
        }

        $persona = PersonaTenant::query()->create([
            'nombre' => $datos['nombre'],
            'segundo_nombre' => $datos['segundo_nombre'] ?? null,
            'primer_apellido' => $datos['primer_apellido'] ?? null,
            'segundo_apellido' => $datos['segundo_apellido'] ?? null,
            'email' => $email,
            'celular' => $celular,
            'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
            'genero' => $datos['genero'] ?? null,
            'tipo' => $datos['tipo'] ?? TipoPersonaTenant::Miembro->value,
            'activo' => true,
            'es_facturable' => $datos['es_facturable'] ?? true,
            'archivado' => false,
            'sucursal_id' => $sucursalId,
        ]);
        if ($aceptaWhatsApp) {
            $this->whatsapp->registrarPorEquipo($persona, true, $actor);
        }

        return ['persona' => $persona, 'reactivado' => false];
    }
}
