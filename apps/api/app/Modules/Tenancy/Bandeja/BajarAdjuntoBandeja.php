<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Bandeja;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeConversacionTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Baja en cola el archivo de un mensaje que llegó a la bandeja (ADR 0200), fuera del
 * aviso de Meta. Lleva el negocio: corre en su base. Si no se pudo, queda la
 * referencia y se intenta al abrirlo.
 */
class BajarAdjuntoBandeja implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(
        public readonly int $estudioId,
        public readonly int $mensajeId,
    ) {}

    public function handle(GestorDeConexionTenant $gestor, AdjuntosBandejaTenant $adjuntos): void
    {
        $estudio = Estudio::query()->find($this->estudioId);
        if (! $estudio instanceof Estudio) {
            return;
        }
        $gestor->ejecutarEn($estudio, function () use ($adjuntos): void {
            $mensaje = MensajeConversacionTenant::query()->with('conversacion')->find($this->mensajeId);
            if ($mensaje instanceof MensajeConversacionTenant) {
                $adjuntos->bajar($mensaje);
            }
        });
    }
}
