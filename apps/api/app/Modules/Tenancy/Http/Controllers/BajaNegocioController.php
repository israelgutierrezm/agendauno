<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ArmarExportacionNegocio;
use App\Modules\Tenancy\Application\BajaDeNegocio;
use App\Modules\Tenancy\Application\ExportacionDeDatosNegocio;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Exceptions\BajaNoPermitida;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ExportacionNegocio;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El dueño da de baja su negocio o revierte la baja que pidió, y descarga todos sus
 * datos (ADR 0213). Solo un dueño: para darla confirma con su contraseña y escribe la
 * dirección del negocio.
 */
class BajaNegocioController
{
    public function __construct(
        private readonly BajaDeNegocio $bajas,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function dar(Request $request): JsonResponse
    {
        $usuario = $this->dueno($request);
        $estudio = $this->estudioDe($request);
        $validado = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'confirmacion' => ['required', 'string', 'max:100'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);
        if ($usuario->password === null) {
            throw ValidationException::withMessages(['password' => ['Entras con Google y aún no tienes contraseña: créala con «¿Olvidaste tu contraseña?» y vuelve a intentarlo.']]);
        }
        if (! Hash::check((string) $validado['password'], (string) $usuario->password)) {
            throw ValidationException::withMessages(['password' => ['La contraseña no coincide.']]);
        }
        if (mb_strtolower(trim((string) $validado['confirmacion'])) !== mb_strtolower((string) $estudio->slug)) {
            throw ValidationException::withMessages(['confirmacion' => ["Escribe «{$estudio->slug}» para confirmar."]]);
        }
        $motivo = trim((string) ($validado['motivo'] ?? ''));
        $estudio = $this->bajas->dar($estudio, BajaDeNegocio::POR_DUENO, $motivo !== '' ? $motivo : null);
        $this->auditoria->registrar($usuario, 'negocio.baja', 'estudio', (string) $estudio->slug, null, [
            'datos_borrar_en' => $estudio->datos_borrar_en?->toDateString(),
        ], $motivo !== '' ? $motivo : null);

        return response()->json(['data' => [
            'estado' => $estudio->estado->value,
            'datos_borrar_en' => $estudio->datos_borrar_en?->toDateString(),
        ]]);
    }

    public function revertir(Request $request): JsonResponse
    {
        $usuario = $this->dueno($request);
        $estudio = $this->estudioDe($request);
        if ($estudio->baja_por !== BajaDeNegocio::POR_DUENO) {
            throw new BajaNoPermitida('La baja la hizo la plataforma: para revertirla, comunícate con soporte.');
        }
        $estudio = $this->bajas->revertir($estudio);
        $this->auditoria->registrar($usuario, 'negocio.baja_revertida', 'estudio', (string) $estudio->slug);

        return response()->json(['data' => ['estado' => $estudio->estado->value]]);
    }

    /** La última descarga de sus datos (o null). */
    public function exportacion(Request $request): JsonResponse
    {
        $this->dueno($request);
        $ultima = ExportacionNegocio::query()
            ->where('estudio_id', $this->estudioDe($request)->getKey())
            ->latest('id')
            ->first();

        return response()->json(['data' => $ultima instanceof ExportacionNegocio ? self::presentar($ultima) : null]);
    }

    /**
     * Pide una descarga nueva: se arma en cola y se avisa por correo. Si hay una en
     * proceso, esa; la anterior lista se reemplaza.
     */
    public function exportar(Request $request): JsonResponse
    {
        $usuario = $this->dueno($request);
        $estudio = $this->estudioDe($request);
        $enProceso = ExportacionNegocio::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('estado', ExportacionNegocio::EN_PROCESO)
            ->where('created_at', '>=', now()->subHour())
            ->latest('id')
            ->first();
        if ($enProceso instanceof ExportacionNegocio) {
            return response()->json(['data' => self::presentar($enProceso)], 202);
        }
        ExportacionNegocio::query()->where('estudio_id', $estudio->getKey())->get()
            ->each(static function (ExportacionNegocio $anterior): void {
                ExportacionDeDatosNegocio::borrarArchivo($anterior);
                $anterior->delete();
            });
        $exportacion = ExportacionNegocio::query()->create([
            'estudio_id' => $estudio->getKey(),
            'solicitada_por' => (string) $usuario->ulid,
            'correo' => $usuario->email,
            'estado' => ExportacionNegocio::EN_PROCESO,
        ]);
        $this->auditoria->registrar($usuario, 'negocio.exportacion', 'estudio', (string) $estudio->slug);
        ArmarExportacionNegocio::dispatch((int) $exportacion->getKey());

        return response()->json(['data' => self::presentar($exportacion->refresh())], 202);
    }

    public function descargarExportacion(Request $request): StreamedResponse
    {
        $this->dueno($request);
        $estudio = $this->estudioDe($request);
        $modelo = ExportacionNegocio::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('ulid', (string) $request->route('exportacion'))
            ->first();
        abort_unless($modelo instanceof ExportacionNegocio && $modelo->descargable(), 404, 'Esta descarga ya no está disponible: pide otra.');

        return DiscosDeArchivos::privado()->download(
            (string) $modelo->ruta,
            'datos-'.$estudio->slug.'-'.$modelo->lista_en?->format('Y-m-d').'.zip',
            ['Content-Type' => 'application/zip'],
        );
    }

    /**
     * @return array{id: string, estado: string, tamano_bytes: int|null, lista_en: string|null, vence_en: string|null, error: string|null}
     */
    private static function presentar(ExportacionNegocio $e): array
    {
        return [
            'id' => $e->ulid,
            'estado' => $e->estado === ExportacionNegocio::LISTA && ! $e->descargable() ? 'vencida' : $e->estado,
            'tamano_bytes' => $e->tamano_bytes,
            'lista_en' => $e->lista_en?->toIso8601String(),
            'vence_en' => $e->vence_en?->toIso8601String(),
            'error' => $e->estado === ExportacionNegocio::FALLIDA ? 'No se pudo preparar la descarga. Vuelve a pedirla; si sigue fallando, comunícate con soporte.' : null,
        ];
    }

    private function dueno(Request $request): Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);
        abort_unless($usuario->tieneRol('propietario'), 403, 'Solo el dueño del negocio puede hacerlo.');

        return $usuario;
    }

    private function estudioDe(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
