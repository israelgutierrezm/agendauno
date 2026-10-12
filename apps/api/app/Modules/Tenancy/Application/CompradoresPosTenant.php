<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * A quién se le vende en el mostrador (ADR 0145): una persona del negocio (alumno,
 * instructor o alguien del equipo, también el propietario) o, sin ella, público en
 * general.
 *
 * Quien es del equipo y nunca tuvo ficha aparece por su cuenta (`usuario:<ulid>`); al
 * venderle se le crea su persona, no facturable ({@see PersonaDeUsuarioTenant}). Los
 * alumnos respetan el alcance por sucursal (R19), como el directorio.
 */
class CompradoresPosTenant
{
    private const LIMITE = 8;

    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly PersonaDeUsuarioTenant $personas,
    ) {}

    /**
     * Hasta 8 coincidencias por nombre, correo o celular.
     *
     * @return list<array{id: string, nombre: string, tipo: string, genero: string|null, email: string|null, celular: string|null}>
     */
    public function buscar(string $texto, ?Usuario $actor): array
    {
        $texto = trim($texto);
        if (mb_strlen($texto) < 2) {
            return [];
        }

        $personas = $this->personasVisibles($actor)
            ->buscar($texto)
            ->orderBy('nombre')->orderBy('primer_apellido')
            ->limit(self::LIMITE)
            ->get()
            ->map(fn (PersonaTenant $p): array => [
                'id' => 'persona:'.$p->ulid,
                'nombre' => $p->nombreCompleto(),
                'tipo' => $this->tipo($p),
                'genero' => $p->genero?->value,
                'email' => $p->email,
                'celular' => $p->celular,
            ]);

        $patron = '%'.$texto.'%';
        $equipo = Usuario::query()
            ->where('activo', true)
            ->whereNotIn('id', PersonaTenant::query()->whereNotNull('usuario_id')->select('usuario_id'))
            ->where(fn (Builder $q) => $q->where('name', 'like', $patron)->orWhere('email', 'like', $patron))
            ->orderBy('name')
            ->limit(self::LIMITE)
            ->get()
            ->filter(static fn (Usuario $u): bool => self::esDelEquipo($u))
            ->map(static fn (Usuario $u): array => [
                'id' => 'usuario:'.$u->ulid,
                'nombre' => (string) $u->name,
                'tipo' => $u->esProfesional() ? TipoPersonaTenant::Instructor->value : TipoPersonaTenant::Staff->value,
                'genero' => null,
                'email' => $u->email,
                'celular' => null,
            ]);

        return array_values($personas->concat($equipo)->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)->take(self::LIMITE)->values()->all());
    }

    /**
     * La persona del comprador elegido. Quien es del equipo sin ficha la estrena aquí.
     */
    public function resolver(string $id, ?Usuario $actor): PersonaTenant
    {
        [$clase, $ulid] = array_pad(explode(':', $id, 2), 2, '');

        if ($clase === 'persona') {
            $persona = $this->personasVisibles($actor)->where('ulid', $ulid)->first();
            if ($persona instanceof PersonaTenant) {
                return $persona;
            }
        }
        if ($clase === 'usuario') {
            $usuario = Usuario::query()->where('activo', true)->where('ulid', $ulid)->first();
            if ($usuario instanceof Usuario && self::esDelEquipo($usuario)) {
                return $this->personas->asegurar(
                    $usuario,
                    $usuario->esProfesional() ? TipoPersonaTenant::Instructor : TipoPersonaTenant::Staff,
                );
            }
        }

        throw ValidationException::withMessages(['comprador_id' => ['Elige a alguien del negocio o vende a público en general.']]);
    }

    /** Cómo se le muestra: alumno, instructor o alguien del equipo. */
    public function tipo(PersonaTenant $persona): string
    {
        return $persona->tipo->value;
    }

    /**
     * Las personas a las que quien vende puede venderle: las del equipo, todas; los
     * alumnos, los de sus sucursales si está acotado (R19).
     *
     * @return Builder<PersonaTenant>
     */
    private function personasVisibles(?Usuario $actor): Builder
    {
        $permitidas = $actor !== null ? $this->acceso->sucursalesPermitidas($actor) : null;

        return PersonaTenant::query()
            ->where('archivado', false)
            ->when($permitidas !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('tipo', '!=', TipoPersonaTenant::Miembro->value)
                ->orWhereIn('sucursal_id', $permitidas ?? [])));
    }

    /** Tiene algún rol además de alumno o cliente. */
    private static function esDelEquipo(Usuario $usuario): bool
    {
        return array_diff($usuario->rolesEfectivos(), ['miembro']) !== [];
    }
}
