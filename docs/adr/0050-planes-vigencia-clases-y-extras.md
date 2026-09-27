# ADR 0050 — Planes: vigencia, clases a las que aplican, clases extra y corte

Estado: Aceptado (2026-09-27). Extiende el motor de membresías
(`docs/MEMBERSHIP_ENGINE.md`).

## Contexto

Un estudio de clases (pole, por ejemplo) vende paquetes de N clases a distintos
precios. Hasta ahora eso no alcanzaba:

- La vigencia solo podía ser "N días desde la compra". Hacían falta "un mes a la
  misma fecha" (del 14 de octubre al 14 de noviembre) y "hasta fin de mes" (del 14
  al 31 de octubre).
- Un paquete no podía limitarse a ciertas clases ("Nivel 1 a 3, no Nivel 4"): solo
  a una actividad o a una sede.
- Las clases extra se vendían como otro paquete, con su propia vigencia.
- El alumno no tenía un corte de cómo usó cada plan.

## Decisiones

- **Vigencia** (`productos_comerciales.vigencia_tipo` + `vigencia_cantidad`,
  `VigenciaProducto`):
  - `dias`: N días (14 oct + 30 → 13 nov).
  - `meses`: N meses a la misma fecha (14 oct → 14 nov; 31 ene → 28 feb, sin
    desbordar).
  - `fin_de_mes`: hasta el último día del mes de compra (1) o de los siguientes.
  - Sin tipo, no vence. El último día cuenta para reservar.
  - La fecha de compra es la del negocio (su zona horaria), no la del servidor.
  - Reemplaza a `vigencia_dias` (la migración convierte lo que había a `dias`).
- **Clase suelta** (`sesion_individual`): siempre de una clase (1000 unidades), con
  su precio y su vigencia. Se compra y luego se reserva, como un paquete.
- **A qué clases aplica** (`producto_ofertas`): el plan elige clases o servicios;
  sin ninguno, aplica a todos.
  - Al vender se copia al derecho (`derecho_ofertas`): editar el plan después no
    cambia lo ya comprado.
  - `ResolverDerechoTenant::cubre` lo respeta junto con actividad y sede.
  - Una clase ligada a un plan no se puede borrar: si se borrara, el plan quedaría
    "para todas" sin que nadie lo decidiera.
  - Sirve igual en negocios de citas ("4 cortes": corte y barba, no tinte).
- **Clases extra** (`add_on`), como pide el motor: una compra aparte, nunca editando
  el paquete.
  - Cada compra tiene su propio acuerdo y su derecho `extra_de_id`, con su asiento
    `add_on` en el ledger.
  - Se suman al paquete vigente más reciente con clases contadas y copian sus clases
    y su sede. Vencen el mismo día que él, y al pausarlo se corren igual.
  - Sin paquete vigente no se venden (`BASE_PACKAGE_REQUIRED`), ni en venta directa
    ni al crear la orden.
  - Si ya se pagaron en línea y el paquete ya no está (venció entre la orden y el
    pago), valen como un paquete aparte con su propia vigencia: no se pierde lo
    pagado.
- **Orden de consumo**: primero lo que vence antes. Con el mismo vencimiento, el
  paquete antes que sus extras; así el corte muestra cuándo se usaron las extras.
- **Corte** (`CorteDePlanesTenant`; `GET /mi/planes` y `/miembros/{p}/planes`):
  - Por cada plan: qué incluía y sus extras (desde el ledger).
  - Cada clase en que se usó (desde sus reservas): asistió, no asistió, cancelación
    tardía o próxima. Lo cancelado a tiempo no cuenta.
  - Lo devuelto, lo vencido, lo apartado, lo disponible y el estado del plan.

## Consecuencias

- La API de productos cambia `vigencia_dias` por `vigencia_tipo` +
  `vigencia_cantidad`, y agrega `vence_si_compra_hoy` y `ofertas`.
- El menú separa Membresías (planes y ventas a alumnos), Punto de venta (mostrador
  e inventario) y Cobros. "Clases y servicios" pasa a Operación.
