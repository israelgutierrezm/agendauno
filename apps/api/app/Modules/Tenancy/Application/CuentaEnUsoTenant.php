<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\ParticipanteNoAutorizado;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TutelaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\Request;

/**
 * Por quién actúa la cuenta del alumno en `/mi/*` (B05, ADR 0123): por sí misma o, con
 * el encabezado `X-Participante: <ulid>`, por una persona a su cargo con la autorización
 * vigente. Es el único punto que lo decide: reservas, planes, pagos y documentos lo
 * consultan aquí. Si el encabezado no corresponde a nadie autorizado, responde 403
 * (`GUARDIAN_NOT_AUTHORIZED`), nunca cae en silencio a la cuenta propia.
 *
 * Lo que es de la cuenta misma (privacidad y exportación de datos, dispositivos, pago
 * automático, reseñas) no lo consulta: siempre es del titular.
 */
class CuentaEnUsoTenant
{
    public const ENCABEZADO = 'X-Participante';

    private const ATRIBUTO = 'cuenta_en_uso';

    public function __construct(
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly FamiliasTenant $familias,
    ) {}

    /** La persona por la que actúa la cuenta (la suya o la del participante). */
    public function persona(Request $request): ?PersonaTenant
    {
        return $this->resolver($request)['persona'];
    }

    /** La persona de la cuenta misma (quien paga y acepta por el participante). */
    public function titular(Request $request): ?PersonaTenant
    {
        return $this->resolver($request)['titular'];
    }

    /** El vínculo con el participante, si la cuenta actúa por uno. */
    public function tutela(Request $request): ?TutelaTenant
    {
        return $this->resolver($request)['tutela'];
    }

    /**
     * @return array{titular: PersonaTenant|null, persona: PersonaTenant|null, tutela: TutelaTenant|null}
     */
    private function resolver(Request $request): array
    {
        $guardado = $request->attributes->get(self::ATRIBUTO);
        if (is_array($guardado)) {
            /** @var array{titular: PersonaTenant|null, persona: PersonaTenant|null, tutela: TutelaTenant|null} $guardado */
            return $guardado;
        }

        $usuario = $request->attributes->get('usuario_tenant');
        $titular = $usuario instanceof Usuario ? $this->personas->buscar($usuario) : null;
        $participante = trim((string) $request->header(self::ENCABEZADO));
        $tutela = null;
        if ($participante !== '') {
            // Sin persona propia no hay a nombre de quién actuar por otro.
            if (! $titular instanceof PersonaTenant) {
                throw new ParticipanteNoAutorizado('No tienes autorización vigente para esa persona.');
            }
            $tutela = $this->familias->participanteAutorizado($titular, $participante);
        }

        $resuelto = [
            'titular' => $titular,
            'persona' => $tutela instanceof TutelaTenant ? $tutela->participante : $titular,
            'tutela' => $tutela,
        ];
        $request->attributes->set(self::ATRIBUTO, $resuelto);

        return $resuelto;
    }
}
