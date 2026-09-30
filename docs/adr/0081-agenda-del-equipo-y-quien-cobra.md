# ADR 0081 — Agenda del equipo, ocupación por agenda y quién cobra una sesión

Estado: Aceptado (2026-09-30). Completa los reportes R29–R31.

## Contexto

Ya existían reportes de ocupación, rentabilidad por clase, demanda por día y hora,
tendencias y cohortes. Quedaban tres huecos:

- **La ocupación solo se medía por cupo.** En un negocio de citas cada cita tiene
  cupo 1, así que la ocupación salía casi siempre en 100% y el mapa de demanda no
  decía cuándo sobra o falta agenda.
- **No había nada por profesional:** qué tan llena está la agenda de cada quien,
  cuánto deja lo que atiende, cuánto falta su clientela y cuánto se le paga.
- **La nómina y la rentabilidad solo pagaban al personal asignado aparte** (R17), no
  al profesional de la sesión (`instructor_id`). Un barbero con su agenda llena
  salía con nómina en cero si nadie lo asignaba además en «Personal».

## Decisión

- **Quién imparte y quién cobra** (`PersonalDeSesionTenant`), igual para la nómina,
  la rentabilidad y los reportes:
  - el profesional de la sesión la imparte y cobra aunque no se le asigne aparte;
  - el personal asignado cobra con su rol (instructor, asistente o sustituto);
  - un sustituto reemplaza a quien indica o, sin indicarlo, al profesional de la
    sesión: el reemplazado no la imparte ni cobra.
- **Lo que deja cada sesión** (`ValorDeSesionesTenant`) sale de un solo cálculo:
  - asistentes, inasistencias y cancelaciones;
  - el valor de lo atendido: precio de la clase o servicio, o si no tiene, los
    créditos usados × su precio;
  - el pago a cada participante según su esquema.
  - La rentabilidad por clase se calcula ahora con él.
- **Ocupación de la agenda** (`OcupacionDeAgendaTenant`):
  - Disponible son los horarios de atención de cada quien en cada sede, cada día del
    periodo, menos los días que el negocio cierra y los bloqueos (suyos o de toda la
    sede).
  - Agendado es la parte de sus clases y citas no canceladas que cae dentro de ese
    horario, así nunca pasa de 100%.
  - Se reparte por profesional y por franja (día × hora local); un tramo de 10:30 a
    11:15 suma 30 minutos a las 10 y 15 a las 11.
- **Reporte «Agenda del equipo»** (`GET /reportes/equipo`, `facturacion.ver`, un año
  como mucho). Por profesional, en el periodo:
  - ocupación, con horas agendadas entre disponibles; sin horario de atención, «Sin
    horario» y las horas que impartió;
  - clases y citas, inasistencias;
  - valor atendido, pago y margen.
  - Incluye a quien atiende hoy aunque no haya tenido agenda, a quien ya se dio de
    baja si trabajó en el periodo, y una fila «Sin instructor asignado».
  - En la web va en la pestaña «Equipo y sucursales», que ahora usa el periodo en
    esta sección.
- **Demanda y resumen en negocios de citas:**
  - `/reportes/demanda` trae en cada franja `disponible_min`, `agendado_min` y
    `utilizacion_pct`, e incluye las franjas con horario y sin nada agendado.
  - `/reportes/negocio` trae `ocupacion_agenda_pct`.
  - En negocios de citas, la web pinta el mapa y la tarjeta «Ocupación» con la
    agenda. En negocios de clases siguen por cupo.

## Consecuencias

- **La nómina cambia:**
  - quien está en la agenda de una sesión cobra aunque no esté asignado aparte;
  - quien fue reemplazado por un sustituto deja de cobrar esa sesión;
  - asignar además como instructor al mismo profesional no le paga doble.
- La rentabilidad por clase suma ese costo, así que puede bajar el margen de clases
  que antes salían sin costo.
- Nómina, rentabilidad y agenda del equipo cuadran entre sí.
- Calcular la disponibilidad recorre el periodo día por día; por eso la agenda del
  equipo acepta hasta un año.
