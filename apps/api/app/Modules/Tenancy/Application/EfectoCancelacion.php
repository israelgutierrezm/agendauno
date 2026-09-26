<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use Carbon\CarbonInterface;

/**
 * Qué pasará (o pasó) con el crédito al cancelar una reserva (fase 1, punto 1.4). La
 * misma decisión alimenta la vista previa ("Se devolverá 1 crédito") y la cancelación
 * real, así lo que se muestra antes de confirmar es exactamente lo que ocurre.
 */
final readonly class EfectoCancelacion
{
    public const DEVUELVE = 'devuelve';

    public const COBRA = 'cobra';

    public const NINGUNO = 'ninguno';

    public function __construct(
        public bool $cancelable,
        public string $credito,
        public int $unidades,
        public ?bool $aTiempo,
        public ?CarbonInterface $limite,
        public string $mensaje,
    ) {}

    public static function noCancelable(string $mensaje): self
    {
        return new self(false, self::NINGUNO, 0, null, null, $mensaje);
    }

    /**
     * "1 crédito", "2 créditos", "0.5 créditos" (1000 unidades = 1 crédito).
     */
    public static function creditos(int $unidades): string
    {
        $numero = rtrim(rtrim(number_format($unidades / 1000, 3, '.', ''), '0'), '.');

        return $numero.($unidades === 1000 ? ' crédito' : ' créditos');
    }

    /**
     * @return array{cancelable: bool, credito: string, unidades: int, a_tiempo: bool|null, limite: string|null, mensaje: string}
     */
    public function toArray(): array
    {
        return [
            'cancelable' => $this->cancelable,
            'credito' => $this->credito,
            'unidades' => $this->unidades,
            'a_tiempo' => $this->aTiempo,
            'limite' => $this->limite?->toIso8601String(),
            'mensaje' => $this->mensaje,
        ];
    }
}
