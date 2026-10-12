<?php

declare(strict_types=1);

use App\Modules\Tenancy\Comunicaciones\PlantillasPorModalidad;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Database\Migrations\Migration;

/*
| Los avisos por omisión al cliente de un negocio de citas hablan de su cita, no de su
| lugar (ADR 0131). Solo cambia lo que el negocio no editó.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $estudio = app(GestorDeConexionTenant::class)->actual();
        if ($estudio instanceof Estudio && $estudio->modalidad() === ModalidadServicio::Citas) {
            PlantillasPorModalidad::adaptar(ModalidadServicio::Citas);
        }
    }

    public function down(): void
    {
        PlantillasPorModalidad::adaptar(ModalidadServicio::Clases);
    }
};
