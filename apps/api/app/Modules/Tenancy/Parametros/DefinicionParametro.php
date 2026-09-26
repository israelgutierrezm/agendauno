<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Parametros;

/**
 * Un parámetro de negocio configurable: su clave, cómo se presenta y entre qué valores
 * puede estar. `defecto` es solo el valor inicial: la plataforma (superadmin) fija el
 * suyo y cada negocio (administrador) puede ajustar el propio, salvo los que son solo
 * de plataforma (`porNegocio = false`). Los sí/no se guardan como 1/0.
 */
final readonly class DefinicionParametro
{
    public const ENTERO = 'entero';

    public const SI_NO = 'si_no';

    public function __construct(
        public string $clave,
        public string $grupo,
        public string $etiqueta,
        public string $ayuda,
        public string $tipo,
        public int $defecto,
        public int $minimo = 0,
        public int $maximo = 1,
        public string $unidad = '',
        public bool $porNegocio = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'clave' => $this->clave,
            'grupo' => $this->grupo,
            'etiqueta' => $this->etiqueta,
            'ayuda' => $this->ayuda,
            'tipo' => $this->tipo,
            'minimo' => $this->minimo,
            'maximo' => $this->maximo,
            'unidad' => $this->unidad,
            'por_negocio' => $this->porNegocio,
        ];
    }
}
