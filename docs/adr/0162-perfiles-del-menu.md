# ADR 0162 — Perfiles del menú: lo que se ofrece depende de cómo trabaja el negocio

Estado: Aceptado (2026-10-18). Continúa la revisión E del dueño (2026-10-17): «menos
módulos visibles, más tareas resueltas», sin reconstruir la barra lateral.

## Contexto

El menú ya se filtraba por permisos del rol activo, por modalidad (clases o citas,
ADR 0104), por las funciones del plan (ADR 0107) y por los módulos encendidos (ADR
0123). Aun así, un profesional que trabaja solo veía un área «Equipo» con una lista de
una persona, su disponibilidad escondida dentro de ella y un reporte del equipo con
pago y margen que no aplican. El reporte de Wellhub y TotalPass aparecía en todo
negocio de clases, los usara o no. El dueño pidió:

- profesional independiente: agenda, clientes, cobrar y disponibilidad;
- negocio con equipo: lo anterior, más asignaciones, rendimiento y liquidaciones;
- estudio de clases: sesiones, cupos, asistencia, paquetes y renovaciones;
- lo avanzado según capacidades y permisos, no solo por ser dueño.

## Decisión

- **Un solo menú, con contexto por vista** (`lib/menu.ts`): cada vista puede pedir un
  `contexto`, que se suma a lo de siempre:
  - `equipo`: el negocio trabaja con alguien más (`estudio.con_equipo`);
  - `plataformas`: recibe visitantes de Wellhub o TotalPass
    (`estudio.plataformas_bienestar`, solo en clases);
  - `factura`: puede facturar en su país (`estudio.factura_posible`).
  El contexto es presentación: no da ni quita acceso. Quién entra a cada pantalla lo
  sigue decidiendo `lib/acceso.ts` y, al final, el servidor.
- **Disponibilidad vive en Agenda** (Calendario · Recepción · Disponibilidad, en
  citas), para quien trabaja solo y para el equipo.
- **Equipo** (solo con equipo): Personas · Rendimiento · Nómina. Rendimiento abre el
  reporte del equipo (`/reportes?vista=equipo`) sin las demás pestañas ni las
  sucursales. Asignar a quien imparte una clase sigue en el detalle de la clase, solo
  con equipo.
- **Quien trabaja solo** no ve el área Equipo. En Reportes, la pestaña es «Tu agenda y
  sucursales», con ocupación, citas o clases, faltas y valor atendido, sin pago ni
  margen. Para invitar a su primera persona usa Configuración › Usuarios; al
  invitarla, aparece lo del equipo.
- **Clases**: el menú ya cubre sesiones, cupos (Lugares disponibles), asistencia
  (Recepción y pase de lista), paquetes y renovaciones; no cambia.
- **Reportes de plataformas** solo con Wellhub o TotalPass conectado, o con visitantes
  ya registrados (su historial no se pierde al desconectar). **Facturas**, solo donde
  se puede facturar.

## Consecuencias

- `GET /yo` (y el resto de la sesión) suma `plataformas_bienestar`; `con_equipo` ya
  existía.
- Un negocio que pasa de solo a equipo ve aparecer Equipo sin configurar nada.
- Para agregar un contexto nuevo basta con una regla en `CONTEXTOS` (`lib/menu.ts`) y
  el dato que la respalde en la sesión.
