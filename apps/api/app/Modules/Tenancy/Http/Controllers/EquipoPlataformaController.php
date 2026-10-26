<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Acceso\AdministradorPlataforma;
use App\Modules\Platform\Acceso\BitacoraPlataforma;
use App\Modules\Platform\Acceso\PerfilesPlataforma;
use App\Modules\Platform\Acceso\SesionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El equipo del superadmin (ADR 0132): los operadores (alta, nombre, contraseña,
 * desactivar), sus sesiones abiertas (cerrarlas) y la bitácora de lo que cada uno
 * cambió. Antes solo se daban de alta con un comando.
 *
 * Reglas: siempre queda al menos un operador activo y nadie se desactiva a sí mismo.
 * Desactivar a alguien o cambiarle la contraseña cierra sus sesiones (salvo la de
 * quien cambia la suya).
 */
class EquipoPlataformaController
{
    private const POR_PAGINA = 50;

    public function administradores(Request $request): JsonResponse
    {
        $yo = $this->yo($request);
        $abiertas = SesionPlataforma::query()->whereNull('cerrada_en')->where('expira_en', '>', now())
            ->selectRaw('administrador_id, count(*) as total')->groupBy('administrador_id')->pluck('total', 'administrador_id');

        return response()->json(['data' => AdministradorPlataforma::query()->orderBy('nombre')->get()
            ->map(static fn (AdministradorPlataforma $a): array => [
                'id' => $a->ulid,
                'nombre' => $a->nombre,
                'email' => $a->email,
                'activo' => $a->activo,
                'perfil' => $a->perfil ?: PerfilesPlataforma::TOTAL,
                'ultimo_acceso_en' => $a->ultimo_acceso_en?->toIso8601String(),
                'sesiones_abiertas' => (int) ($abiertas[$a->getKey()] ?? 0),
                'soy_yo' => $yo !== null && $yo->is($a),
            ])->all()]);
    }

    public function crear(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:200'],
            'perfil' => ['sometimes', Rule::in(PerfilesPlataforma::PERFILES)],
        ]);
        $email = mb_strtolower(trim($validado['email']));
        if (AdministradorPlataforma::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => ['Ya hay un operador con ese correo.']]);
        }

        $administrador = AdministradorPlataforma::query()->create([
            'nombre' => trim($validado['nombre']),
            'email' => $email,
            'password' => $validado['password'],
            'activo' => true,
            'perfil' => $validado['perfil'] ?? PerfilesPlataforma::TOTAL,
        ]);

        return response()->json(['data' => ['id' => $administrador->ulid]], 201);
    }

    public function actualizar(Request $request, string $administrador): JsonResponse
    {
        $modelo = AdministradorPlataforma::query()->where('ulid', $administrador)->firstOrFail();
        $validado = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:120'],
            'activo' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', 'min:12', 'max:200'],
            'perfil' => ['sometimes', Rule::in(PerfilesPlataforma::PERFILES)],
        ]);
        $yo = $this->yo($request);
        $esYo = $yo !== null && $yo->is($modelo);

        // Nadie se cambia su propio perfil, y siempre queda un operador total activo.
        if (array_key_exists('perfil', $validado) && $validado['perfil'] !== ($modelo->perfil ?: PerfilesPlataforma::TOTAL)) {
            if ($esYo) {
                throw ValidationException::withMessages(['perfil' => ['No puedes cambiar tu propio perfil.']]);
            }
            if ($validado['perfil'] !== PerfilesPlataforma::TOTAL && ! $this->quedaOtroTotal($modelo)) {
                throw ValidationException::withMessages(['perfil' => ['Debe quedar al menos un operador activo con perfil total.']]);
            }
        }

        if (array_key_exists('activo', $validado) && ! $validado['activo'] && $modelo->activo) {
            if ($esYo) {
                throw ValidationException::withMessages(['activo' => ['No puedes desactivarte a ti mismo.']]);
            }
            if (AdministradorPlataforma::query()->where('activo', true)->whereKeyNot($modelo->getKey())->doesntExist()) {
                throw ValidationException::withMessages(['activo' => ['Debe quedar al menos un operador activo.']]);
            }
            if (($modelo->perfil ?: PerfilesPlataforma::TOTAL) === PerfilesPlataforma::TOTAL && ! $this->quedaOtroTotal($modelo)) {
                throw ValidationException::withMessages(['activo' => ['Debe quedar al menos un operador activo con perfil total.']]);
            }
        }

        $modelo->update($validado);

        // Sin acceso o con otra contraseña, sus sesiones abiertas dejan de servir.
        if ((array_key_exists('activo', $validado) && ! $validado['activo']) || array_key_exists('password', $validado)) {
            SesionPlataforma::query()->where('administrador_id', $modelo->getKey())->whereNull('cerrada_en')
                ->when($esYo, fn (Builder $q) => $q->where('token_hash', '!=', (string) $request->attributes->get('sesion_plataforma_huella')))
                ->update(['cerrada_en' => now()]);
        }

        return response()->json(['data' => ['id' => $modelo->ulid, 'activo' => $modelo->activo, 'nombre' => $modelo->nombre, 'perfil' => $modelo->perfil]]);
    }

    /** ¿Hay otro operador activo con perfil total, además de este? */
    private function quedaOtroTotal(AdministradorPlataforma $modelo): bool
    {
        return AdministradorPlataforma::query()
            ->where('activo', true)
            ->where('perfil', PerfilesPlataforma::TOTAL)
            ->whereKeyNot($modelo->getKey())
            ->exists();
    }

    public function sesiones(Request $request): JsonResponse
    {
        $actual = (string) $request->attributes->get('sesion_plataforma_huella');

        return response()->json(['data' => SesionPlataforma::query()
            ->with('administrador')
            ->whereNull('cerrada_en')
            ->where('expira_en', '>', now())
            ->orderByDesc('ultimo_uso_en')
            ->get()
            ->map(static fn (SesionPlataforma $s): array => [
                'id' => $s->ulid,
                'administrador' => $s->administrador instanceof AdministradorPlataforma
                    ? ['nombre' => $s->administrador->nombre, 'email' => $s->administrador->email]
                    : null,
                'ip' => $s->getAttribute('ip'),
                'agente' => $s->getAttribute('agente'),
                'abierta_en' => $s->created_at?->toIso8601String(),
                'ultimo_uso_en' => $s->ultimo_uso_en?->toIso8601String(),
                'expira_en' => $s->expira_en->toIso8601String(),
                'actual' => $actual !== '' && hash_equals($s->token_hash, $actual),
            ])->all()]);
    }

    public function cerrarSesion(string $sesion): JsonResponse
    {
        $modelo = SesionPlataforma::query()->where('ulid', $sesion)->firstOrFail();
        if ($modelo->cerrada_en === null) {
            $modelo->forceFill(['cerrada_en' => now()])->save();
        }

        return response()->json(status: 204);
    }

    /**
     * Lo que se hizo, de lo más reciente a lo más viejo; por operador, negocio o
     * acción.
     */
    public function bitacora(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'operador' => ['nullable', 'string', 'max:40'],
            'estudio' => ['nullable', 'string', 'max:80'],
            'accion' => ['nullable', 'string', 'max:120'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        $pagina = BitacoraPlataforma::query()
            ->with(['administrador', 'estudio'])
            ->when(($filtros['operador'] ?? '') !== '', fn (Builder $q) => $q->whereHas('administrador', fn (Builder $a) => $a->where('ulid', $filtros['operador'])))
            ->when(($filtros['estudio'] ?? '') !== '', fn (Builder $q) => $q->whereHas('estudio', fn (Builder $e) => $e->where('slug', $filtros['estudio'])))
            ->when(($filtros['accion'] ?? '') !== '', fn (Builder $q) => $q->where('accion', 'like', '%'.$filtros['accion'].'%'))
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(static fn (BitacoraPlataforma $b): array => [
                'id' => $b->ulid,
                'fecha' => $b->created_at->toIso8601String(),
                'accion' => $b->accion,
                'metodo' => $b->metodo,
                'ruta' => $b->ruta,
                'estado' => $b->estado,
                'ip' => $b->ip,
                'operador' => $b->administrador instanceof AdministradorPlataforma
                    ? ['nombre' => $b->administrador->nombre, 'email' => $b->administrador->email]
                    : null,
                'negocio' => $b->estudio instanceof Estudio
                    ? ['slug' => $b->estudio->slug, 'nombre' => $b->estudio->nombre, 'producto' => $b->estudio->producto()->value]
                    : null,
                'datos' => $b->datos,
            ])->all(),
            'meta' => ['pagina' => $pagina->currentPage(), 'ultima_pagina' => $pagina->lastPage(), 'total' => $pagina->total()],
        ]);
    }

    private function yo(Request $request): ?AdministradorPlataforma
    {
        $yo = $request->attributes->get('administrador_plataforma');

        return $yo instanceof AdministradorPlataforma ? $yo : null;
    }
}
