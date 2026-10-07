<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Platform\Operacion\ErroresPlataforma;
use App\Modules\Tenancy\Creditos\Exceptions\SaldoInsuficiente;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Inventario\Exceptions\InventarioException;
use App\Modules\Tenancy\Ordenes\Exceptions\OrdenException;
use App\Modules\Tenancy\Pagos\Exceptions\PagoException;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Qué se reporta como falla. Las reglas del negocio que la API responde con un 4xx y
 * su código (enlace vencido, cupo lleno, saldo insuficiente…) no son fallas: no van al
 * log ni al monitoreo cuando llegan a la respuesta (bootstrap/app.php).
 */
final class ReporteDeErrores
{
    public static function esReglaDelNegocio(Throwable $e): bool
    {
        $estado = match (true) {
            $e instanceof TenancyException,
            $e instanceof ReservaException,
            $e instanceof PagoException,
            $e instanceof OrdenException,
            $e instanceof InventarioException => $e->estadoHttp(),
            $e instanceof SaldoInsuficiente => 422,
            default => null,
        };

        return $estado !== null && $estado < 500;
    }

    /**
     * Un error que se atrapa y no llega a ninguna respuesta (un cobro en segundo plano,
     * la conciliación con la pasarela): nadie más lo ve, así que se reporta aunque sea
     * una regla del negocio (por ejemplo, el negocio desactivó la pasarela).
     */
    public static function reportarAtrapado(Throwable $e): void
    {
        if (! self::esReglaDelNegocio($e)) {
            report($e);

            return;
        }

        Log::warning($e->getMessage(), ['exception' => $e]);
        app(ErroresPlataforma::class)->desdeExcepcion($e);
    }
}
