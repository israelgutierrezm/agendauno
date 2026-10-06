<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Latido del programador y de la cola: prueba que ambos siguen vivos (chequeos de
// salud, Docker y agendauno:verificar-produccion). Es la única tarea que corre en
// mantenimiento: al publicar, prueba que el programador nuevo funciona antes de abrir
// (ninguna tarea del negocio corre hasta abrir).
Schedule::command('agendauno:latido')->everyMinute()->evenInMaintenanceMode();

// Resumen de alertas de la plataforma al superadmin (ALERTAS_CORREO), agrupado.
Schedule::command('agendauno:enviar-alertas')->everyTenMinutes()->withoutOverlapping();

// Reanuda las membresías cuya pausa terminó (antes de renovar ciclos y cobrar). Cada
// hora: el día termina en la zona de cada negocio, no a medianoche UTC; reanudar una
// pausa que ya se reanudó no hace nada.
Schedule::command('agendauno:reanudar-pausas')->hourlyAt(5)->withoutOverlapping();

// Reinicia/renueva los ciclos vencidos de los derechos recurrentes en cuanto termina
// su último día en el negocio (por eso cada hora; un ciclo ya renovado no se repite).
Schedule::command('agendauno:generar-ciclos')->hourlyAt(15)->withoutOverlapping();

// Publica los eventos de dominio pendientes del outbox de cada estudio (R39).
// Frecuente para baja latencia; withoutOverlapping evita relays solapados.
Schedule::command('agendauno:despachar-outbox')->everyMinute()->withoutOverlapping();

// Reintenta las entregas de webhook fallidas de cada estudio (R40).
Schedule::command('agendauno:reintentar-webhooks')->everyFiveMinutes()->withoutOverlapping();

// Envia los mensajes encolados (y reintenta los fallidos) de cada estudio (R28).
Schedule::command('agendauno:enviar-mensajes')->everyMinute()->withoutOverlapping();

// Materializa la agenda recurrente de cada estudio (R5): ventana deslizante diaria.
Schedule::command('agendauno:generar-agenda')->dailyAt('00:30')->withoutOverlapping();

// Expira las ofertas de lista de espera vencidas y re-ofrece el cupo (R7).
Schedule::command('agendauno:expirar-ofertas')->everyMinute()->withoutOverlapping();

// Recordatorios de clases y citas (24 h y 2 h antes): emite el evento; el mensaje
// sale por las plantillas activas en los siguientes minutos.
Schedule::command('agendauno:enviar-recordatorios')->everyFiveMinutes()->withoutOverlapping();

// Al terminar una clase o cita, quien no tiene registro «no se presentó» (ADR 0101).
Schedule::command('agendauno:marcar-inasistencias')->everyFiveMinutes()->withoutOverlapping();

// Libera las reservas pago-para-reservar (citas) no pagadas a tiempo (R-citas).
Schedule::command('agendauno:expirar-reservas-pago')->everyMinute()->withoutOverlapping();

// Vuelve a consultar, con la misma llave, las devoluciones que la pasarela no confirmó.
Schedule::command('agendauno:conciliar-reembolsos')->everyFiveMinutes()->withoutOverlapping();

// Pregunta a la pasarela por los cobros cuyo aviso (webhook) no llegó y aplica lo que
// habría aplicado el aviso. Antes de que venza el apartado de una cita (30 min).
Schedule::command('agendauno:conciliar-pagos')->everyFiveMinutes()->withoutOverlapping();

// Cobra las renovaciones recurrentes vencidas y reintenta a los morosos (Etapa 2), a
// las 00:45 de CDMX (ya empezó el día del cobro en el negocio). Una vez al día: es la
// cadencia de los reintentos.
Schedule::command('agendauno:cobrar-suscripciones')->dailyAt('06:45')->withoutOverlapping();

// Aviso de renovación próxima (3 días antes), a las 09:00 de CDMX: cuándo, cuánto y
// cómo se paga; a quien paga a mano le abre la renovación para pagarla por adelantado.
Schedule::command('agendauno:avisar-renovaciones')->dailyAt('15:00')->withoutOverlapping();

// Cobro del SaaS mes vencido (ADR 0019 y 0032): a diario a las 02:00 de CDMX se emiten
// los cargos de los meses que ya cerraron en la zona de cada negocio (congelando su
// medición); un cargo emitido no se vuelve a calcular, así que solo emite los que falten.
Schedule::command('agendauno:generar-cargos-renta')->dailyAt('08:00')->withoutOverlapping();

// Suspensión automática por renta vencida tras los días de gracia (ADR 0073), a las
// 09:30 de CDMX, antes de los avisos a los dueños (que avisan la suspensión). Al pagar
// se reactiva al momento; esto también reactiva a quien ya pagó, por si acaso.
Schedule::command('agendauno:suspender-por-renta')->dailyAt('15:30')->withoutOverlapping();

// Avisos de la plataforma a los dueños (ADR 0071): prueba por terminar, renta lista,
// renta vencida y pago recibido, por correo y WhatsApp. Cada hora de 09:00 a 20:00 de
// CDMX; no repite ninguno.
Schedule::command('agendauno:avisar-duenos')->hourlyAt(20)->between('15:00', '02:00')->withoutOverlapping();

// Respalda la base central y los archivos subidos (03:05) y la base de cada negocio
// (03:15), en el disco de respaldos (en producción, fuera del servidor), y borra los
// viejos (retención).
Schedule::command('agendauno:respaldar-plataforma')->dailyAt('03:05')->withoutOverlapping();
Schedule::command('agendauno:respaldar-estudios')->dailyAt('03:15')->withoutOverlapping();

// Borra los registros técnicos vencidos (envíos y códigos de WhatsApp, sesiones de
// tarjeta), con los plazos de los parámetros de plataforma (ADR 0079). 03:40 de CDMX.
Schedule::command('agendauno:limpiar-registros')->dailyAt('09:40')->withoutOverlapping();

// Simulacro de restauración (domingos): prueba que los respaldos se pueden restaurar.
Schedule::command('agendauno:simulacro-restauracion')->weeklyOn(0, '04:30')->withoutOverlapping();

// Escala el dunning: suspende las membresias morosas cuya gracia vencio (R10).
Schedule::command('agendauno:escalar-dunning')->dailyAt('01:00')->withoutOverlapping();
