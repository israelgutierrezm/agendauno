# ADR 0062 — Cualquier profesional disponible

Estado: Aceptado (2026-09-28).

## Contexto

Al agendar una cita, el cliente tenía que elegir profesional antes de ver horarios.
En AgendaPro, y en la mayoría de las barberías y salones, el cliente puede no
elegir: le importa la hora, no quién lo atiende. Sin esa opción:

- el cliente revisaba profesional por profesional hasta encontrar una hora;
- el trabajo se cargaba en el primero de la lista.

## Decisión

- **Opción «Cualquier profesional disponible»** al agendar, en la página pública,
  en la cuenta del cliente (web) y en la app.
  - Aparece solo si hay más de un profesional, y es la opción por defecto.
  - Se puede elegir a alguien en particular, como antes.
- **API**: `instructor_id` pasa a ser opcional en
  `GET /citas/disponibilidad`, `POST /citas`, `GET /mi/citas/disponibilidad` y
  `POST /mi/citas`. Sin él:
  - **Horarios**: la unión de los huecos de quienes atienden en esa sede ese día
    (tienen horario de atención ahí), en orden. Cada hueco lleva `profesionales`
    (ULID de quién puede atenderlo).
  - **Agendar**: candidatos son quienes atienden en la sede y cuyo horario cubre la
    cita. Se asigna al que tiene menos citas y clases programadas ese día; a
    igualdad, por nombre.
  - La respuesta de agendar trae `profesional` (`{id, nombre}`), también cuando se
    eligió a alguien, para decirle al cliente quién lo atenderá.
- **Concurrencia**: cada intento es el agendado normal (`AgendarCitaTenant::agendar`),
  con el candado de ese profesional y su transacción.
  - Si el hueco de un candidato ya se ocupó (otro cliente llegó antes), se intenta
    con el siguiente.
  - Nunca se toman dos candados de profesional a la vez, así que no hay abrazos
    mortales entre solicitudes.
  - Un rechazo que no depende del profesional (sin derechos, fuera de la ventana de
    reserva) se responde tal cual, sin probar a los demás.
  - `agendauno:verificar-concurrencia` lo prueba en MySQL: varios clientes piden la
    misma hora a la vez y cada profesional queda con una sola cita.
- **Solo para el cliente**: el negocio (recepción) sigue agendando con un
  profesional concreto.

## Consecuencias

- El cliente ve de una vez todas las horas en que alguien puede atenderlo.
- El trabajo se reparte entre el equipo en lugar de cargarse en el primero de la
  lista.
- Calcular los horarios cuesta una consulta de disponibilidad por profesional de la
  sede. Es aceptable para equipos de pocas personas; si crece, se puede calcular en
  un solo paso.
- Pendiente (fuera de este paso): elegir primero la hora, varios servicios en una
  cita y un selector semanal de horarios.
