<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\TipoSesionTenant;

/**
 * La modalidad del negocio en contexto: solo clases o solo citas, nunca ambas
 * (ADR 0104). El dominio pregunta aquí, no al giro ni a la forma de una oferta.
 */
class ModalidadNegocioTenant
{
    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function modalidad(): ModalidadServicio
    {
        $estudio = $this->gestor->actual();

        return $estudio instanceof Estudio ? $estudio->modalidad() : ModalidadServicio::Clases;
    }

    public function esCitas(): bool
    {
        return $this->modalidad() === ModalidadServicio::Citas;
    }

    public function esClases(): bool
    {
        return $this->modalidad() === ModalidadServicio::Clases;
    }

    /** El tipo de toda sesión del negocio. */
    public function tipoSesion(): TipoSesionTenant
    {
        return $this->modalidad()->tipoSesion();
    }

    /**
     * @return array{clases: bool, citas: bool}
     */
    public function capacidades(): array
    {
        return $this->modalidad()->capacidades();
    }
}
