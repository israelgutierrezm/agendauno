# ADR 0118 — Planes comparables, clase de prueba y lo que necesita quien llega por primera vez

Estado: Aceptado (2026-10-10). Plan de mejoras de captación, M02, M03/B03, C03 y M01 (bloque 3).

## Contexto

Quien todavía no conoce el negocio decide con lo que ve en su página. Fitune y Buq muestran
precios por categoría con su periodo, una «clase muestra», la descripción y los requisitos de
cada disciplina, y la ficha del coach (ver `docs/ANALISIS-COMPETENCIA-CLASES.md`). Nuestra
página mostraba el nombre, el precio y los créditos de cada plan, sin decir hasta cuándo
servía, para qué clases ni cuál recomendaba el negocio. Tampoco había una forma de vender una
clase de prueba sin que la comprara cualquiera, cuantas veces quisiera.

## Decisión

**Calendario público por fecha (M02).** `GET /calendario` (solo modalidad clases, con la página
pública activa) devuelve las clases de un periodo de hasta 31 días con los lugares de la regla
única de cupo (ADR 0116), los filtros que tienen datos y la siguiente fecha con clases.

**Planes comparables (M03).** Un plan (`productos_comerciales`) suma:

- `descripcion` (300): qué incluye, en una línea;
- `destacado`: el que el negocio recomienda; va primero en su grupo y marcado;
- `primera_vez`: es una clase de prueba (ver abajo).

La página pública los agrupa (primera vez, clase suelta, paquetes, membresías y lo demás) y
dice en cada uno qué cubre, a qué actividad aplica y la **fecha exacta** hasta la que se puede
usar si se paga hoy (`vence_si_compra_hoy`, la regla de `VigenciaProducto`; la vigencia corre
desde el pago, decisión del dueño). La cuenta del alumno («Mis pagos») y la app muestran lo
mismo antes de pagar. «Lo quiero» abre la solicitud de acceso con ese plan como interés: el
prospecto llega a Prospectos con él (ADR 0117).

**Clase de prueba (B03).** Decisión del dueño: solo la compra quien **nunca vino**, y una sola
vez. `PrimeraVezTenant::elegible()` exige que la persona no tenga ningún acuerdo (ningún plan
antes, tampoco otra clase de prueba) ni asistencias registradas como presente. Se valida en el
servidor:

- al crear la orden (`OrdenesTenant::crear`), en la cuenta y en caja, con una sola unidad;
- otra vez al cobrarla (`CobrarOrdenTenant`), porque dos órdenes pendientes pueden pedirla y
  solo debe pagarse una.

El error es `FIRST_VISIT_ONLY` (422). `/mi/productos` dice `disponible: false` para que la web
y la app no la ofrezcan a quien ya no le toca; esconderla no sustituye la validación. Las
renovaciones no pasan por esta regla.

**Lo que necesita quien llega por primera vez (C03).** Una clase (`ofertas`) suma
`para_principiantes`, `requisitos` (qué necesita saber) y `que_llevar` (300 cada uno); la
página los muestra con un filtro «Solo para principiantes». Un integrante del equipo suma
`semblanza` (600), que escribe en «Mi perfil» quien da clases o atiende y que la página
muestra junto a su foto.

Todo es opcional: sin datos, la página se ve como antes.

## Consecuencias

- Un negocio vende una clase de prueba sin revisar a mano quién la compra; quien ya tiene o
  tuvo un plan, o ya asistió, recibe un mensaje claro en lugar de un cobro indebido.
- Una persona que vino como invitada sin plan y no tiene asistencia registrada todavía puede
  comprarla: la regla mira lo que el sistema sabe, no lo que el negocio recuerda.
- Las fechas que ve el cliente salen del servidor (zona del negocio), no del navegador.
- Los textos nuevos los escribe el negocio; no hay contenido inventado por el sistema.
