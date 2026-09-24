<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Modules\Tenancy\Models\PersonaTenant;

/**
 * Ya existe una persona dada de baja con ese teléfono. No se reactiva sola (los
 * números cambian de dueño): el negocio decide si la reactiva o si es otra persona.
 * `meta.persona` trae a quién se refiere.
 */
class PersonaDadaDeBaja extends TenancyException
{
    public function __construct(private readonly PersonaTenant $persona)
    {
        parent::__construct('Ya existe '.$persona->nombreCompleto().', dado de baja, con ese teléfono. ¿Lo reactivas o es otra persona?');
    }

    public function codigo(): string
    {
        return 'PERSON_DEACTIVATED_MATCH';
    }

    public function estadoHttp(): int
    {
        return 409;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return ['persona' => [
            'id' => (string) $this->persona->ulid,
            'nombre' => $this->persona->nombreCompleto(),
            'dado_de_baja_en' => $this->persona->deleted_at?->toIso8601String(),
        ]];
    }
}
