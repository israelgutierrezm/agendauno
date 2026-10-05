# ADR 0098 — El personal sin sucursal no ve nada

Estado: Aceptado (2026-10-05). Reemplaza la compatibilidad «sin asignación ve todo» de
R19.

## Contexto

El alcance por sucursal (R19, `ResolverAccesoTenant`) acotaba al personal a las
sucursales que tenía asignadas, pero quien no tenía ninguna veía todas. Se pensó para
negocios de una sola sucursal y para el personal anterior a las asignaciones; en un
negocio con varias, olvidar asignar a alguien le daba acceso a todo.

## Decisión

- **Quién ve qué** (`ResolverAccesoTenant::sucursalesPermitidas`):
  - propietario y administración: todas (nunca acotados);
  - quien solo es cliente o alumno: no aplica (lo suyo va por su cuenta);
  - el resto del personal (recepción, quien imparte, roles propios): solo las
    sucursales que tiene asignadas; **sin ninguna, no ve nada** (lista vacía).
  - Con una sola sucursal en el negocio todo es de ella: no hay a qué acotar.
  - Asignado a todas las sucursales, ve todo (también lo que no tiene sucursal).
- **Asignar no amplía por sí solo**: estar asignado a una sucursal como quien imparte
  solo dice dónde trabaja; únicamente un rol del equipo asignado en esa sucursal (p. ej.
  recepción de esa sede) amplía a quien imparte a todas las clases de ella
  (`AccesoSesionTenant`).
- **Lo dice claro**: `/yo` trae `sin_sucursal`; el panel muestra «Aún no tienes una
  sucursal asignada» y Usuarios marca a esa persona.
- **Asignar es obligatorio en la interfaz**: con varias sucursales, invitar a alguien
  del equipo pide su sucursal, y no se le puede quitar la última. El servidor no lo
  exige (importaciones, cambios de rol): su regla es la de arriba, sin sucursal no ve.
- **Continuidad**: al abrir la segunda sucursal, el personal sin asignar se queda con la
  primera (`AsignarSucursalAlPersonalTenant`); la migración `000112` asigna todas las
  sucursales al personal que hoy no tiene ninguna en negocios con varias, para que nadie
  pierda lo que hacía.

## Consecuencias

- Nadie del personal ve sucursales que no le asignaron.
- Un instructor sin sucursal en un negocio con varias tampoco ve sus clases hasta que se
  le asigne una.
