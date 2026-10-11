# ADR 0130 — TurnoUno sin membresías: los negocios de citas venden bonos

Estado: Aceptado (2026-10-11). Pedido del dueño: «Para TurnoUno quita membresías, no
tiene sentido». Reemplaza la sección «Servicios, combos, bonos y membresías en citas»
del ADR 0091 en lo que toca a las membresías.

## Contexto

El ADR 0091 dejó las membresías en los negocios de citas como opción secundaria. Con
los dos productos separados (ADR 0108), en TurnoUno no tienen sentido: una barbería,
un spa o un consultorio cobra el servicio o vende bonos de visitas prepagadas.

Hasta ahora un negocio de citas podía hacer todo lo de una membresía:
- crearla y venderla;
- que se renovara y se cobrara sola;
- ponerle permanencia, inscripción y anualidad;
- publicarla en su página y cotizarla.

La API no lo impedía en ningún lado.

El dueño decidió tres cosas:
- Las membresías que ya existan en un negocio de citas terminan en su fecha.
- El plan Pro de TurnoUno deja de anunciar «Cobro automático de membresías». Los
  precios no cambian.
- Se reporta antes cuáles hay en cada negocio.

## Decisión

**Qué vende un negocio de citas:**
- servicios y combos (Catálogo);
- bonos de sesiones (`TipoProducto::Paquete`): varias visitas prepagadas con
  vigencia, que se descuentan al agendar como siempre.

**Qué no vende:** nada que funcione como membresía (`ProductoTenant::esMembresia()`):
- una membresía;
- un plan que se renueva (`politica_reset` distinta de `ninguno`);
- uno ilimitado;
- uno con permanencia;
- uno que cobra inscripción o anualidad.

Bloquear solo el tipo `membresia` dejaría pasar un «paquete» que se renueva enviado
directo a la API. Por eso la regla revisa todas esas condiciones.

**Lo decide el servidor (`MembresiasPorModalidadTenant`):**
- Al crear o editar un producto, revisa cómo queda: no puede quedar a la venta como
  membresía (422 `MEMBERSHIP_NOT_AVAILABLE`). Archivarlo sí se puede.
- Al venderlo, en el mostrador (`/acuerdos`), en una orden nueva, en un presupuesto o
  desde la cuenta del cliente.
- Al ofrecerlo: la cuenta del cliente, la página pública y el catálogo de
  presupuestos no listan membresías.
- No hay pago automático en citas:
  - activarlo o invitar a activarlo responde 422 `DIRECT_DEBIT_NOT_ALLOWED`;
  - `domiciliar` al pagar se rechaza;
  - `/mi/perfil` trae `pago_automatico: false`.
- Las cuotas (inscripción y anualidad) son de las membresías: sus rutas pasan a
  `modalidad:clases` y la anualidad no se genera en citas.
- Los saldos de apertura de un plan que se renovaba valen hasta su vencimiento, sin
  renovarse.
- Los avisos de renovación no salen en citas.

**Las que ya existen terminan en su fecha (`RetirarMembresiasCitasTenant`):**
- Las membresías a la venta se archivan.
- Las vendidas que se renuevan reciben la misma baja que da el negocio
  (`BajaDeMembresiaTenant`), sin penalización ni adeudo:
  - terminan al acabar el periodo pagado, o hoy si ya estaban en mora;
  - se anula la renovación por cobrar;
  - se quita el pago automático;
  - sus planes valen hasta esa fecha.
- Corre en tres momentos:
  - con el comando `agendauno:retirar-membresias-citas`, que con `--simular` solo
    reporta qué hay en cada negocio;
  - cuando el superadmin pasa un negocio de clases a citas;
  - cuando el cobro de renovaciones encuentra una membresía en un negocio de citas:
    en vez de cobrarla, la termina.
- Volver a correrlo no cambia nada.

**Plan de TurnoUno (ADR 0107):**
- La función `cobro_automatico` sale del reparto por niveles.
- «Paquetes y membresías» pasa a «Bonos de sesiones».
- «Venta en línea de paquetes» pasa a «Venta en línea de bonos».
- Los precios no cambian.

**Web y app.** En citas:
- el editor de planes solo ofrece el bono de sesiones;
- Planes no muestra el indicador de membresías;
- «Bonos y membresías» pasa a «Bonos»;
- «Con bono o membresía» pasa a «Con bono»;
- el Inicio cuenta «bonos por vencer»;
- la app no muestra el pago automático.

Los negocios de clases no cambian.

## Consecuencias

- TurnoUno vende lo que tiene sentido en citas: servicios, combos y bonos. AgendaUno
  sigue con membresías, permanencia, cuotas y pago automático.
- Ningún cliente de un negocio de citas recibe un cargo de renovación nuevo. Lo que
  ya pagó vale hasta su fecha.
- Al desplegar, el operador corre
  `agendauno:retirar-membresias-citas --simular` para ver qué hay y luego el comando
  sin `--simular`. Aunque no lo corra, el cobro de renovaciones ya no cobra
  membresías en citas.
- `TipoProducto::Membresia`, los ciclos y el cobro recurrente siguen siendo parte del
  núcleo. La regla es por modalidad, no un borrado: AgendaUno los usa.
