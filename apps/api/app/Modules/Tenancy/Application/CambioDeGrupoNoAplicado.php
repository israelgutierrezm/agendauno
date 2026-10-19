<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use RuntimeException;

/**
 * Deshace un cambio de grupo dentro de su transacción (vista previa, o parcial sin
 * confirmar) y lleva lo que habría pasado ({@see BajasDeGrupoTenant::cambiar()}).
 */
final class CambioDeGrupoNoAplicado extends RuntimeException
{
    /**
     * @param  array{baja: array{canceladas: int, no_canceladas: list<array{inicia_en: string, motivo: string}>}, alta: array<string, mixed>, completo: bool, aplicado: bool}  $resultado
     */
    public function __construct(public readonly array $resultado)
    {
        parent::__construct('Cambio de grupo sin aplicar.');
    }
}
