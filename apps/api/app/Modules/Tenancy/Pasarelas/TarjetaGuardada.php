<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

/**
 * Tarjeta que el cliente autorizó en la pasarela para pagos automáticos: la
 * referencia del método en la pasarela y lo necesario para mostrarla. Nunca el
 * número completo.
 */
final readonly class TarjetaGuardada
{
    public function __construct(
        public string $metodo,
        public ?string $marca = null,
        public ?string $ultimos4 = null,
        public ?int $expiraMes = null,
        public ?int $expiraAnio = null,
    ) {}

    /**
     * @return array{metodo_externo: string, marca: string|null, ultimos4: string|null, expira_mes: int|null, expira_anio: int|null}
     */
    public function atributos(): array
    {
        return [
            'metodo_externo' => $this->metodo,
            'marca' => $this->marca,
            'ultimos4' => $this->ultimos4,
            'expira_mes' => $this->expiraMes,
            'expira_anio' => $this->expiraAnio,
        ];
    }
}
