# ADR 0160 — Importar las citas futuras de otro sistema

Estado: Aceptado (2026-10-17). Continúa las importaciones de miembros, saldos,
instructores y clases.

## Contexto

Un negocio de citas que llega de otro sistema trae su agenda de las próximas semanas.
Sin importarla, recepción tenía que capturar cita por cita y, al hacerlo, a cada
cliente le llegaba un aviso de una cita que ya tenía. La landing ya decía que las citas
futuras no se importaban.

## Decisión

- **Plantilla** (`GET /importaciones/citas/plantilla`):
  - `cliente`, `email`, `celular`, `servicio`, `profesional`, `sucursal`, `fecha`
    (AAAA-MM-DD) y `hora` (HH:MM, en la zona de la sucursal);
  - `referencia`, `email` y `celular` son opcionales.
- **Vista previa** (`POST /importaciones/citas/preview`): ejecuta las mismas reglas en
  una transacción que se revierte, y responde una confirmación cifrada de 30 minutos.
  La importación (`POST /importaciones/citas`) exige esa confirmación con el mismo
  archivo y las mismas opciones. Si algo cambió desde la vista previa, pide revisar de
  nuevo.
- **Correspondencias**:
  - Servicio, profesional y sucursal se buscan por nombre, sin mayúsculas ni acentos.
  - Uno que no coincide, o que coincide con varios, vuelve en `faltantes`. La pantalla
    deja elegir a cuál corresponde, y `correspondencias[campo][valor] = ulid` vale para
    todas sus filas.
- **Clientes**:
  - Se reconocen por correo o celular; si no, se crea su ficha con el alta de siempre.
  - Un celular de una ficha dada de baja no se le quita: la fila pide decidirlo.
- **Conflictos**: cada cita se agenda como en recepción (`AgendarCitaTenant`,
  `porNegocio`), con su candado por profesional y su cabina. Lo que se encima, ya pasó
  o no se encuentra es error de su fila. Se importa todo o nada.
- **Sin duplicar**: una cita del mismo cliente, servicio y hora que ya está agendada se
  marca «Ya estaba» y no se crea otra. Volver a importar el mismo archivo no duplica.
- **Avisos apagados**:
  - Los eventos de la reserva y de su orden quedan publicados sin consumidores: ni
    correos, ni webhooks, ni conversiones.
  - Los recordatorios antes de la cita solo si se marca «Mandarles su recordatorio»
    (`reservas.sin_avisos`).
  - El servicio queda por cobrar en caja, como una cita agendada por el negocio.
- La bitácora registra la importación (`importacion.citas`) con cuántas citas, cuántas
  ya estaban y cuántos clientes nuevos.
- **Web**: Agenda › Importar citas (solo en negocios de citas), con `agenda.gestionar`
  y `reservas.gestionar`.

## Consecuencias

- Migración de negocio 000150 (`reservas.sin_avisos`). Las reservas anteriores
  conservan sus recordatorios.
- El tope de filas es el de las demás importaciones (`importaciones.max_filas`).
- No se importan pagos ni anticipos de esas citas: quedan por cobrar.
