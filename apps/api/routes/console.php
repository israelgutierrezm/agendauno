<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reinicia/renueva a diario los ciclos vencidos de los derechos recurrentes.
Schedule::command('entitlements:generar-ciclos')->dailyAt('00:15');

// Publica los eventos de dominio pendientes del outbox de cada estudio (R39).
// Frecuente para baja latencia; withoutOverlapping evita relays solapados.
Schedule::command('turnouno:despachar-outbox')->everyMinute()->withoutOverlapping();

// Reintenta las entregas de webhook fallidas de cada estudio (R40).
Schedule::command('turnouno:reintentar-webhooks')->everyFiveMinutes()->withoutOverlapping();

// Envia los mensajes encolados (y reintenta los fallidos) de cada estudio (R28).
Schedule::command('turnouno:enviar-mensajes')->everyMinute()->withoutOverlapping();

// Materializa la agenda recurrente de cada estudio (R5): ventana deslizante diaria.
Schedule::command('turnouno:generar-agenda')->dailyAt('00:30');

// Expira las ofertas de lista de espera vencidas y re-ofrece el cupo (R7).
Schedule::command('turnouno:expirar-ofertas')->everyMinute()->withoutOverlapping();

// Libera las reservas pago-para-reservar (citas) no pagadas a tiempo (R-citas).
Schedule::command('turnouno:expirar-reservas-pago')->everyMinute()->withoutOverlapping();

// Cobra las renovaciones recurrentes vencidas y reintenta a los morosos (Etapa 2).
// Antes de escalar el dunning, para dar oportunidad a los reintentos del día.
Schedule::command('turnouno:cobrar-suscripciones')->dailyAt('00:45')->withoutOverlapping();

// Cobro del SaaS mes vencido (ADR 0019): el día 1 se congela la medición del mes que
// cerró (alumnos o profesionales activos según la modalidad) y se genera su cargo.
Schedule::command('turnouno:generar-cargos-renta')->monthlyOn(1, '02:00')->withoutOverlapping();

// Escala el dunning: suspende las membresias morosas cuya gracia vencio (R10).
Schedule::command('turnouno:escalar-dunning')->dailyAt('01:00');
