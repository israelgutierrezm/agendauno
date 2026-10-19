# ADR 0164 — Fallos reproducidos R01–R06: alcance, vigencia de grupos y cobro de cita

Estado: Aceptado (2026-10-18). Corrige los seis fallos reproducidos de la revisión del
dueño del 2026-10-18. No agrega módulos: endurece lo que ya existía.

## R01. Grupos con alcance por sucursal

Un grupo es de la sucursal de su plantilla. `AccesoGrupoTenant` acota:

- la lista y su contador;
- cada grupo por su ULID, para leer y para escribir: inscritos, inscribir, baja,
  cambio (también el destino), cerrar, reabrir y crear con un horario de otra sede.

Quién ve qué:

- el propietario y quien no está acotado ven todo;
- el equipo acotado, sus sedes;
- quien imparte, los grupos que imparte o los de una sede donde tiene un rol del
  equipo (como `AccesoSesionTenant`).

Solo se inscribe a quien se puede ver (`AlcancePersonaTenant`).

## R02. Respuestas de fichas con la regla del expediente

`AccesoExpedienteTenant::acotar()` es la misma regla que `puedeVer()`, como consulta:

- miembros: quien ve miembros, de sus sedes y, si imparte, de sus clientes;
- instructores y personal: quien administra al equipo.

El listado de respuestas la aplica en la lista, la búsqueda, las páginas y el total.
Diseñar fichas (`formularios.gestionar`) ya no basta para leer respuestas: hace falta
poder abrir el expediente.

## R03. Vigencia de una inscripción

`inscripciones_grupo.alta_desde` (nuevo) y `baja_desde` (ya existía) forman el
intervalo en días del negocio. El listener de fechas nuevas evalúa cada fecha contra
ese intervalo:

- una baja o un cierre con fecha futura conservan las fechas anteriores, también las
  que se generan después;
- un alta futura (cambio de grupo desde un día) no reserva antes de ese día;
- la agenda avisa también a grupos cerrados con inscritos vigentes;
- quien ya tuvo una reserva en esa fecha (aun cancelada) no se vuelve a reservar.

Hasta el día de su baja, la persona cuenta como inscrita.

## R08 (con R03). Cambio de grupo todo o nada

`BajasDeGrupoTenant::cambiar()` corre en una sola transacción:

- el destino va bajo candado y se revisa ahí;
- primero la baja y luego el alta.

Es parcial si alguna fecha no se puede reservar o cancelar. En ese caso no se aplica
salvo con `parcial: true`: responde 409 con lo que habría pasado y no cambia nada.

`GET …/cambio` es la vista previa exacta: la misma operación, deshecha. La web la
muestra fecha por fecha y pide confirmar el parcial.

## R04–R06. El cobro de una cita es una operación

- **Siempre deja su venta, aun solo el servicio.** Es la operación que se recupera y
  el comprobante que se manda. Sus sumas no cambian: el servicio va en
  `servicio_minor` y su dinero es el pago de la orden.
- **Las listas del mostrador y los movimientos de caja no la muestran** (scope
  `delMostrador`): no es una venta de productos.
- **La clave del intento va ligada** a la reserva y a una huella de lo pedido
  (`ventas_pos.reserva_id`, `huella_intento`):
  - lo mismo recupera el mismo cobro;
  - otra cita, otros importes o el Mostrador con esa clave responden 409 sin tocar
    nada;
  - el Mostrador aplica la misma regla.
- **El ticket sale para todo cobro**, y su envío va aparte (`ticket_envio`:
  `enviado` | `fallido`):
  - un correo fallido no deshace el cobro;
  - se reenvía con `POST /pos/ventas/{venta}/ticket`, que solo manda el correo;
  - un reintento no lo vuelve a mandar si ya salió.

## Consecuencias

- Migraciones `000153` (`alta_desde`) y `000154` (venta ligada a su reserva, huella y
  envío fallido).
- Los cobros de solo servicio anteriores no tienen venta. Solo los nuevos.
