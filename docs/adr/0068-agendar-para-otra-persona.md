# ADR 0068 — Agendar para otra persona

Estado: Aceptado (2026-09-29).

## Contexto

Es común agendar para alguien más: una mamá para su hijo, alguien para su pareja o
su papá. El ADR 0059 quitó familias y tutores (fichas ligadas con permisos entre
ellas) por la complejidad que traían. Hacía falta una forma de agendar para otra
persona sin volver a eso.

## Decisión

- La cita es de **quien agenda**: su ficha, sus avisos, su pago y su historial.
- Se guarda solo el nombre de **quien asiste** (`reservas.asiste`, hasta 120
  caracteres).
  - No se crea una ficha para esa persona ni se ligan fichas.
  - Se captura en la página pública (casilla «Es para otra persona») y desde la
    cuenta: `POST /citas` y `POST /mi/citas` con `asiste`.
- **Quién lo ve**:
  - el negocio, en el detalle de la cita («Asiste»);
  - el cliente, en sus reservas de la web («… · para Juanito») y de la app
    («para Juanito»).

## Consecuencias

- Resuelve el caso común sin fichas ligadas ni permisos entre personas.
- La asistencia y el historial quedan en la ficha de quien agenda. Si el negocio
  necesita el historial de quien asiste (p. ej. una clínica), esa persona debe tener
  su propia ficha. Eso es trabajo aparte y pide revisar el ADR 0059.
