# ADR 0022 — Bajas lógicas y reactivación de personas y usuarios

Estado: Aceptado (2026-09-24). Primer paso de "bajas lógicas en todo el sistema":
personas y usuarios. Le siguen pagos (quién cobró y movimientos), la bitácora
completa y el resto de catálogos.

## Contexto

No había baja lógica: ninguna tabla tenía `deleted_at`; alumnos se "archivaban", el
equipo no se podía dar de baja y varias pantallas borraban de verdad. El dueño del
producto pidió bajas lógicas con el correo (y el celular) como datos clave: el correo
no puede pasar a otro usuario y, si alguien dado de baja vuelve a registrarse, se
reactiva. También control de quién y cuándo hizo cada movimiento.

## Decisiones

- **`SoftDeletes` de Laravel en `users` y `personas`** (`deleted_at` +
  `eliminado_por`). El alcance global oculta a los dados de baja en todo el sistema
  por defecto (no se les reserva, cobra, notifica ni cuenta como activos); el motivo y
  el detalle van en la bitácora (`miembro.baja`, `miembro.reactivado`,
  `usuario.baja`, `usuario.reactivado`, `miembro.celular_liberado`).
- **El historial resuelve a los dados de baja**: las relaciones hacia persona o
  usuario usan `withTrashed()` (órdenes, reservas, pagos, sesiones…), igual que la
  ficha, el resumen, el expediente y los derechos. Vender o reservar no.
- **Dar de baja a un alumno cierra lo vigente**: reservas futuras (sin penalizar
  créditos), membresías (canceladas: dejan de renovarse y cobrarse) y pagos
  automáticos; su cuenta, si es solo de alumno (sesiones revocadas). Las membresías
  canceladas no regresan al reactivar.
- **El correo es de la persona para siempre** (índice único en `users.email`, y en
  el sistema también el de personas): registrarse, ser dado de alta por el negocio,
  agendar una cita pública o ser invitado al equipo con el correo de alguien dado de
  baja lo **reactiva** con su historial. De paso se corrige que el registro duplicaba
  a la persona que el negocio ya había dado de alta.
- **El celular avisa, no reactiva** (los números se reasignan): el alta con el
  celular de alguien dado de baja responde 409 `PERSON_DEACTIVATED_MATCH` con
  `meta.persona`; el negocio reactiva a esa persona o indica que es otra
  (`liberar_celular`: se le quita el número a la dada de baja, queda en bitácora).
- **Dos bajas distintas**: la baja lógica (reversible) y la **cancelación de datos
  ARCO**, que sigue anonimizando (lo exige la LFPDPPP) y además queda dada de baja;
  esa no se puede reactivar y el correo queda libre para un registro nuevo.
- **Equipo**: no puedes darte de baja a ti mismo ni dejar al negocio sin dueño.
- **Cobro del SaaS**: quien se dio de baja durante el mes sigue contando ese mes
  (sí usó el servicio).
- Las excepciones de tenancy pueden llevar `meta` en el contrato de error.

## Consecuencias

- Código nuevo que consulte personas o usuarios para historial o reportes debe
  decidir explícitamente si incluye a los dados de baja (`withTrashed()`).
- Las importaciones rechazan correos de dados de baja (se reactivan desde Miembros o
  invitándolos de nuevo), para no reactivar en bloque sin querer.
- Pendiente de esta línea de trabajo: `pagos` sin quién cobró y la liquidación en caja
  sin registro de pago; bitácora sin filtros por fecha/usuario; catálogos que aún se
  borran de verdad.
