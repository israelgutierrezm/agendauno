<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Latido del programador y de la cola: prueba que ambos siguen vivos (chequeos de
// salud, Docker y turnouno:verificar-produccion).
Schedule::command('turnouno:latido')->everyMinute();

// Resumen de alertas de la plataforma al superadmin (ALERTAS_CORREO), agrupado.
Schedule::command('turnouno:enviar-alertas')->everyTenMinutes()->withoutOverlapping();

// Reanuda las membresías cuya pausa terminó (antes de renovar ciclos y cobrar).
Schedule::command('turnouno:reanudar-pausas')->dailyAt('00:05')->withoutOverlapping();

// Reinicia/renueva a diario los ciclos vencidos de los derechos recurrentes.
Schedule::command('entitlements:generar-ciclos')->dailyAt('00:15')->withoutOverlapping();

// Publica los eventos de dominio pendientes del outbox de cada estudio (R39).
// Frecuente para baja latencia; withoutOverlapping evita relays solapados.
Schedule::command('turnouno:despachar-outbox')->everyMinute()->withoutOverlapping();

// Reintenta las entregas de webhook fallidas de cada estudio (R40).
Schedule::command('turnouno:reintentar-webhooks')->everyFiveMinutes()->withoutOverlapping();

// Envia los mensajes encolados (y reintenta los fallidos) de cada estudio (R28).
Schedule::command('turnouno:enviar-mensajes')->everyMinute()->withoutOverlapping();

// Materializa la agenda recurrente de cada estudio (R5): ventana deslizante diaria.
Schedule::command('turnouno:generar-agenda')->dailyAt('00:30')->withoutOverlapping();

// Expira las ofertas de lista de espera vencidas y re-ofrece el cupo (R7).
Schedule::command('turnouno:expirar-ofertas')->everyMinute()->withoutOverlapping();

// Recordatorios de clases y citas (24 h y 2 h antes): emite el evento; el mensaje
// sale por las plantillas activas en los siguientes minutos.
Schedule::command('turnouno:enviar-recordatorios')->everyFiveMinutes()->withoutOverlapping();

// Libera las reservas pago-para-reservar (citas) no pagadas a tiempo (R-citas).
Schedule::command('turnouno:expirar-reservas-pago')->everyMinute()->withoutOverlapping();

// Vuelve a consultar, con la misma llave, las devoluciones que la pasarela no confirmó.
Schedule::command('turnouno:conciliar-reembolsos')->everyFiveMinutes()->withoutOverlapping();

// Pregunta a la pasarela por los cobros cuyo aviso (webhook) no llegó y aplica lo que
// habría aplicado el aviso. Antes de que venza el apartado de una cita (30 min).
Schedule::command('turnouno:conciliar-pagos')->everyFiveMinutes()->withoutOverlapping();

// Cobra las renovaciones recurrentes vencidas y reintenta a los morosos (Etapa 2).
// Antes de escalar el dunning, para dar oportunidad a los reintentos del día.
Schedule::command('turnouno:cobrar-suscripciones')->dailyAt('00:45')->withoutOverlapping();

// Aviso de renovación próxima (3 días antes), a las 09:00 de CDMX: cuándo, cuánto y
// cómo se paga; a quien paga a mano le abre la renovación para pagarla por adelantado.
Schedule::command('turnouno:avisar-renovaciones')->dailyAt('15:00')->withoutOverlapping();

// Cobro del SaaS mes vencido (ADR 0019 y 0032): a diario a las 02:00 de CDMX se emiten
// los cargos de los meses que ya cerraron en la zona de cada negocio (congelando su
// medición); un cargo emitido no se vuelve a calcular, así que solo emite los que falten.
Schedule::command('turnouno:generar-cargos-renta')->dailyAt('08:00')->withoutOverlapping();

// Respalda la base central y los archivos subidos (03:05) y la base de cada negocio
// (03:15), en el disco de respaldos (en producción, fuera del servidor), y borra los
// viejos (retención).
Schedule::command('turnouno:respaldar-plataforma')->dailyAt('03:05')->withoutOverlapping();
Schedule::command('turnouno:respaldar-estudios')->dailyAt('03:15')->withoutOverlapping();

// Simulacro de restauración (domingos): prueba que los respaldos se pueden restaurar.
Schedule::command('turnouno:simulacro-restauracion')->weeklyOn(0, '04:30')->withoutOverlapping();

// Escala el dunning: suspende las membresias morosas cuya gracia vencio (R10).
Schedule::command('turnouno:escalar-dunning')->dailyAt('01:00')->withoutOverlapping();
