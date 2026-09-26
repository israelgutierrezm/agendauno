# ADR 0039 — Recursos en citas (fase 2, punto 2.4)

Estado: Aceptado (2026-09-26). Usa el verificador único (ADR 0033), los márgenes
(ADR 0036) y los bloqueos (ADR 0037).

## Contexto

Las salas ya existían para las clases (R3: unidad o pool, por sede), pero las citas
no las usaban. En un spa con una sola cabina, dos terapeutas disponibles podían
agendar a la misma hora y chocar en la cabina.

## Decisiones

- **El servicio dice qué espacios puede usar**: `oferta_recursos` (cabinas,
  consultorios, sillones o equipos). Vacío significa que no requiere espacio: los
  negocios que no lo necesitan no ven esta complejidad.
- **La cita toma uno libre de su sede** (`ElegirRecursoTenant`):
  - los candidatos son los del servicio en esa sede y activos, en orden estable;
  - toma el primero sin choque en su tramo ocupado (atención más márgenes) y sin
    bloqueo;
  - al guardar, toma el candado de cada candidato antes de revisarlo, así dos
    solicitudes simultáneas no se quedan con la misma cabina;
  - un recurso de otra sede nunca es candidato.
- **Disponibilidad**: un hueco se ofrece solo si además del profesional hay un
  espacio libre, en recepción, en la cuenta del cliente y en la página pública.
- **Reprogramar**: la cita conserva su espacio si sigue libre a la nueva hora; si no,
  toma otro de sus candidatos; si no hay ninguno, no cambia nada.
- **Detalle**: la sesión guarda su `recurso_id`. La agenda y el detalle ya muestran
  la sala.
- **Pantalla**: el catálogo muestra "Espacios que usa" (casillas por espacio y sede)
  solo si el negocio tiene espacios.

## Consecuencias

- La elección es "el primero libre". Repartir la carga entre cabinas o preferir una
  por profesional queda como mejora.
- Un espacio en modo pool admite varias citas simultáneas hasta su capacidad, igual
  que en las clases.
