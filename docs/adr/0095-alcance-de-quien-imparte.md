# ADR 0095 — Alcance de quien imparte: sus clientes, sus tareas y nada económico

Estado: Aceptado (2026-10-04).

## Contexto

Una revisión del perfil del instructor encontró que su acceso era más amplio de lo
que su trabajo necesita, y que algunas restricciones solo existían en la pantalla:

- Con `miembros.ver`, un barbero consultaba los 729 clientes del negocio y las
  reseñas de todos los profesionales.
- La ficha de un cliente entregaba desde el servidor sus compras, importes y formas
  de pago aunque el rol no tuviera `ordenes.ver` (la pantalla solo ocultaba botones).
- La bandeja de tareas no limitaba la consulta ni las acciones (completar, reabrir)
  al responsable.

El negocio ya acotaba las sesiones de quien imparte (`AccesoSesionTenant`: un
instructor *acotado* —con un rol de quien imparte y sin un rol del equipo— solo opera
sus sesiones). Faltaba aplicar el mismo criterio a lo demás.

## Decisión

- **Clientes** (`AlcanceClientesTenant`): un instructor acotado solo ve a quienes
  reservaron alguna de sus sesiones. Se aplica en el servidor al directorio
  (`GET /miembros`), a la ficha y el resumen de una persona, a su expediente, sus
  documentos y sus consentimientos pendientes (403 si no es su cliente). Los números
  de todo el negocio (`/miembros/resumen`, `/retencion/por-vencer`) no son para él.
  El resto del personal conserva su alcance (con el de sucursal, R19).
- **Reseñas**: el instructor acotado ve solo las de sus clases o citas.
- **Lo económico**: la ficha entrega compras, importes, formas de pago y lo pendiente
  de pago solo a quien tiene `ordenes.ver`; a los demás, `null`.
- **Tareas**: permiso nuevo `tareas.equipo` («Ver y atender las tareas de todo el
  equipo»). Sin él, cada quien ve, completa y reabre solo sus tareas. Lo tienen el
  propietario, administración y recepción (que atiende los seguimientos automáticos,
  sin responsable); el instructor, no.

## Consecuencias

- El instructor ve un directorio corto (sus clientes) y la ficha de cualquier otro
  devuelve 403; las pantallas que piden números del negocio dejan de mostrarlos.
- Un rol propio con faceta de quien imparte hereda el mismo alcance (es la misma
  regla de `esInstructorAcotado`). Si un instructor también tiene un rol del equipo,
  al entrar con ese rol ve todo.
- Las tareas automáticas sin responsable solo las ve quien tiene `tareas.equipo`.
- Queda pendiente separar en permisos explícitos «clientes de sus sesiones» y «todo
  el directorio» para roles propios que no son de quien imparte; hoy el alcance se
  decide por la faceta del rol activo.
