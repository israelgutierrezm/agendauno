# ADR 0233 — Los planes 100 % digitales no se venden en las apps

Estado: Aceptado (2026-10-31). Sigue al ADR 0211 (apps sin venta digital).

## Contexto

Una revisión para las tiendas encontró que `/mi/productos` listaba planes que solo dan
videos o solo clases en línea, y que se compraban y pagaban en la app. Eso va contra las
reglas 3.1.1 y 3.1.3 de Apple. El ADR 0211 solo cubría el pago de una clase en línea.

## Decisión

Solo lo **100 % digital** queda fuera de la app; un plan mixto se sigue vendiendo, porque
su valor principal es presencial (decisión del dueño). `ProductosDigitalesTenant` lo
calcula, sin guardar nada: un plan es digital si

- da reservas y **todas** las ofertas vigentes que cubre son clases en línea (grupal con
  `enlace_en_linea`); sin ofertas propias cubre las de su actividad o, sin actividad,
  todas; o
- no da reservas (sin ilimitado, créditos ni unidades por ciclo) e incluye de forma
  expresa algún video publicado.

Una cita en línea no cuenta: es un servicio entre dos personas (3.1.3(e)).

Con `X-App-Producto`, `/mi/productos` no lo lista, y `POST /mi/ordenes` y
`/mi/ordenes/{orden}/cobrar` responden 422 («Este plan no se vende desde la app.» y «Este
pago no se hace desde la app.», sin decir dónde comprarlo). La web sigue igual, y
`digital` viene en cada producto y en las órdenes por pagar.

## Consecuencias

- Como se calcula, un cambio de catálogo (quitar el enlace de una clase, sumar una
  presencial) cambia la respuesta de inmediato y no hace falta migrar datos.
- `X-App-Producto` no es seguridad (ADR 0111): evita una venta por error de una app
  oficial, no a quien arma la petición a mano, que de todos modos paga en la web.
