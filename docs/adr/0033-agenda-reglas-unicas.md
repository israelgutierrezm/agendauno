# ADR 0033 — Las mismas reglas de agenda en todas partes (fase 1, punto 1.3)

Estado: Aceptado (2026-09-26). Complementa el R3 (recursos) y el R5 (clases recurrentes).

## Contexto

Cada forma de crear o cambiar una sesión revisaba cosas distintas:

- Recepción revisaba al instructor y la sala, pero fuera de una transacción: dos
  solicitudes simultáneas podían pasar la revisión y encimarse.
- Las clases recurrentes solo revisaban la sala, no al instructor. Además, por un
  error con `serie_id != X`, no veían las sesiones sueltas (sin serie) y podían
  encimarse con ellas.
- Asignar un sustituto solo revisaba a ese rol; un asistente podía estar en dos clases
  a la vez.
- Una sala de otra sede o fuera de servicio se podía asignar.

Lo que no se generaba se omitía en silencio.

## Decisiones

- **Un solo verificador** (`VerificarAgendaTenant`) para recepción, reasignar
  instructor, sustitutos y asistentes, citas y clases recurrentes:
  - un profesional no puede tener dos cosas que se solapen;
  - la sala no pasa de su cupo simultáneo, debe ser de la misma sede y estar en
    servicio.
- **Revalidar al guardar, bajo candado**: `bloquear()` hace una escritura sin cambios
  sobre la fila del profesional y después la del recurso, siempre en ese orden para
  no trabarse. Se llama dentro de la transacción que guarda, antes de revisar. En
  MySQL toma el candado exclusivo de la fila; en SQLite, el de escritura. La
  verificación previa del formulario (`/sesiones/verificar`) solo avisa; el guardado
  es el que decide.
- **Mensajes útiles**: "Esa persona ya atiende Nivel 1 a las 09:30.", "Salón A es de
  otra sede.", "Salón A está fuera de servicio.", "Salón A no está disponible en ese
  horario." (neutro de género, porque el nombre del recurso puede ser femenino o
  masculino).
- **Clases recurrentes**:
  - Las fechas que no se pueden generar se omiten y se reportan (`omitidas: [{fecha,
    motivo}]`); la agenda lo muestra al crear la serie.
  - Las instancias ya generadas no se tocan: una que se editó o canceló no resucita.
  - El proceso diario no corre dos veces a la vez (`withoutOverlapping`).

## Verificación

Se lanzaron 10 procesos simultáneos contra el negocio demo, agendando al mismo
profesional a la misma hora:

- con el candado: 1 creada y 9 rechazadas con 422;
- sin él (control): en SQLite, 9 errores "database is locked". En MySQL, donde una
  lectura simple no bloquea, habrían quedado duplicados.

## Consecuencias

- El candado por profesional serializa sus guardados. Es aceptable: son pocos por
  profesional al mismo tiempo.
- Reprogramar (mover una sesión de hora) todavía no existe como operación. Cuando se
  agregue, debe usar el mismo verificador con `excluirSesionId`.
