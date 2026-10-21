# ADR 0193 — Demo de 15 minutos agendable en la landing

Estado: Aceptado (2026-10-20).

## Contexto

El comparativo recomendó copiar de las landings de la competencia una «demo de 15
minutos agendable en la misma página, con confirmación por WhatsApp». Hasta ahora, quien
quería verla escribía a ventas o, con el registro cerrado, dejaba sus datos en la lista
de interesados.

## Decisión

- **El superadmin la enciende y fija su horario** en Parámetros → Demos:
  - si se agendan (`demos.activas`, apagado por omisión);
  - cuánto dura cada una (15 min);
  - de qué hora a qué hora, de lunes a viernes, y los sábados si los enciende;
  - cuántos días hacia adelante se ofrecen y con cuánta anticipación;
  - cuántas demos caben a la misma hora (cuántas personas atienden).

  Todo en la zona de ventas (`VENTAS_ZONA`, centro de México por omisión).
- **La landing ofrece los horarios libres** en el llamado final, sin agregar una banda.
  Pide nombre, negocio, correo, teléfono y, si quiere, su giro. Lleva el aviso de
  privacidad aceptado (con la versión leída), captcha y un campo trampa. Apagadas, no
  se muestra.
- **Un horario no se toma dos veces**: cada demo ocupa un lugar del horario
  (`ranura = {inicio UTC}#{n}`, único). Si dos personas agendan a la vez, la segunda
  toma el siguiente lugar o recibe «ya no está disponible».
- **Confirmación**:
  - por correo, a quien la pidió, con el enlace para cancelarla
    (`/demo/cancelar?demo=…&token=…`), y a ventas (`VENTAS_CORREO`) con sus datos;
  - por WhatsApp solo si lo pidió con su casilla (no viene marcada) y la plataforma
    habla con los dueños por WhatsApp (ADR 0070). La plantilla `agendauno_demo_agendada`
    se registra en Meta como las demás de dueños: el superadmin la ve en su lista.
- **El superadmin las ve** junto a los interesados, las próximas primero, y marca si se
  atendió, si no contestó o si se canceló.
- **Medición**: el evento `demo_scheduled` solo dice el producto, sin datos de la
  persona.

## Consecuencias

- Mientras la plantilla de WhatsApp no esté aprobada en Meta, la confirmación por
  WhatsApp falla en silencio (se reporta) y queda la del correo.
- No hay recordatorio automático antes de la demo: ventas la ve en su lista.

## Recordatorios (2026-10-21)

- A quien la pidió se le recuerda por correo un día antes (si la agendó con más de un
  día) y una hora antes (si la agendó con más de una hora), con el enlace para
  cancelarla. Los manda `agendauno:recordar-demos` cada cinco minutos.
- Cada recordatorio se toma una sola vez (`recordatorio_dia_en`, `recordatorio_hora_en`),
  aunque dos procesos revisen a la vez. Una demo cancelada no se recuerda.
