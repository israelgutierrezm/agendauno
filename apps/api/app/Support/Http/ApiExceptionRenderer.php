<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Modules\Tenancy\Creditos\Exceptions\SaldoInsuficiente;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Inventario\Exceptions\InventarioException;
use App\Modules\Tenancy\Ordenes\Exceptions\OrdenException;
use App\Modules\Tenancy\Pagos\Exceptions\PagoException;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Contrato de error estable y legible por máquina para consumidores de /api/*:
 *
 *   { "code": "STRING_CODE", "message": "texto legible", "meta": { ... } }
 *
 * El `code` es un identificador técnico estable (en inglés); el `message` está
 * localizado (es-MX). Ver docs/API.md.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null; // Fuera de la API: manejo por defecto (HTML).
        }

        return match (true) {
            $e instanceof ValidationException => $this->make(
                'VALIDATION_FAILED',
                'Los datos proporcionados no son válidos.',
                422,
                ['errors' => $e->errors()],
            ),
            $e instanceof AuthenticationException => $this->make(
                'UNAUTHENTICATED',
                'Se requiere autenticación.',
                401,
            ),
            $e instanceof AuthorizationException => $this->make(
                'FORBIDDEN',
                'No tienes autorización para realizar esta acción.',
                403,
            ),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $this->make(
                'NOT_FOUND',
                'No se encontró el recurso solicitado.',
                404,
            ),
            $e instanceof SaldoInsuficiente => $this->make(
                'SALDO_INSUFICIENTE',
                $e->getMessage() !== '' ? $e->getMessage() : 'Saldo insuficiente.',
                422,
            ),
            $e instanceof ReservaException => $this->make(
                $e->codigo(),
                $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la reserva.',
                $e->estadoHttp(),
            ),
            $e instanceof PagoException => $this->make(
                $e->codigo(),
                $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar el pago.',
                $e->estadoHttp(),
            ),
            $e instanceof OrdenException => $this->make(
                $e->codigo(),
                $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo crear la orden.',
                $e->estadoHttp(),
            ),
            $e instanceof InventarioException => $this->make(
                $e->codigo(),
                $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la operación de inventario.',
                $e->estadoHttp(),
            ),
            $e instanceof TenancyException => $this->make(
                $e->codigo(),
                $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la operación de tenancy.',
                $e->estadoHttp(),
                $e->meta(),
            ),
            default => $this->generic($e),
        };
    }

    private function generic(Throwable $e): JsonResponse
    {
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        if ($status < 400) {
            $status = 500;
        }

        $codes = [
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_FAILED',
            429 => 'TOO_MANY_REQUESTS',
        ];

        $esErrorServidor = $status >= 500;
        $debug = (bool) config('app.debug');

        return $this->make(
            $codes[$status] ?? ($esErrorServidor ? 'SERVER_ERROR' : 'HTTP_ERROR'),
            $esErrorServidor && ! $debug
                ? 'Ocurrió un error inesperado.'
                : ($e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la solicitud.'),
            $status,
            $esErrorServidor && $debug ? ['exception' => class_basename($e)] : [],
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function make(string $code, string $message, int $status, array $meta = []): JsonResponse
    {
        return response()->json(array_filter([
            'code' => $code,
            'message' => $message,
            'meta' => $meta !== [] ? $meta : null,
        ], static fn ($value): bool => $value !== null), $status);
    }
}
