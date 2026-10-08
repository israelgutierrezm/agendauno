# ADR 0107 — Modelo comercial: precios en USD, niveles de citas, anual, domiciliación y timbres

Estado: Aceptado (2026-10-07). Ajusta los ADR 0019 (cobro por modalidad), 0032 (cargo
estable), 0094 (cobro por profesional) y 0073 (suspensión por renta).

## Contexto

Por decisión comercial los precios se publican en dólares para compararse con
AgendaPro y ReservaClase (más bajos que ellos). La V1 cobra solo con Stripe; Mercado
Pago y OpenPay para la renta quedan pendientes. Hasta ahora:

- la renta se cobraba mes vencido en pesos, por alumnos activos (clases) o por
  profesionales activos medidos (citas), pagando el dueño cada mes en la página de
  Stripe, sin cargo automático;
- no había niveles: todos los negocios tenían todas las funciones;
- no había pago anual ni control de cuántas facturas (CFDI) emite cada negocio.

## Decisión

### Moneda

- Las tarifas se publican en **USD** (una versión nueva de cada tarifa,
  `2026_10_08_000200_tarifas_en_usd`; las anteriores en MXN se conservan y sus cargos
  no cambian). La definición lleva su `moneda`; sin ella, es de pesos.
- **Negocios en México**: se les cobra en **pesos**, convirtiendo el importe en USD al
  tipo de cambio FIX del Banco de México (serie SF43718) del día en que se emite el
  cargo (`TiposDeCambio`). Con `BANXICO_TOKEN` se consulta la API SIE (una vez por día
  y hora; los días sin FIX rige el último publicado); sin él, rige el que capture el
  superadmin (`PUT /plataforma/tipo-cambio`). Se guarda por día en `tipos_cambio`, en
  diezmilésimas (sin flotantes). Si el último tiene más de
  `renta.tipo_cambio_dias_vigencia` días (7), el cargo espera y se avisa al superadmin.
- Fuera de México se cobra en **USD**. El cargo guarda lo que costó en la moneda de la
  tarifa (`monto_tarifa_minor`, `moneda_tarifa`) y el tipo de cambio aplicado.
- IVA: 16 % en México; fuera, `iva_porcentaje_extranjero` de la tarifa (0 % por ser
  exportación de servicios, a confirmar con el contador). La factura (CFDI) de la
  renta solo se emite por cargos en pesos; los demás tienen su recibo.
- Una cuota fija pactada puede ser en USD (`cuota_fija_moneda`) y se convierte igual.

### Clases (sin cambios de fondo)

- Por **alumnos activos** del mes, mes vencido, con la escalera en USD (ReservaClase
  Premium −15 %, redondeado hacia abajo): hasta 40 → 21; 41–60 → 30; 61–80 → 39;
  81–100 → 48; 101–150 → 68; 151–200 → 84; 201–300 → 111; 301–500 → 164;
  501–1,000 → 297. Más de 1,000: cotización (cuota fija pactada).
- Sin pago anual. Se puede domiciliar.

### Citas: niveles por profesionales contratados (`PlanCitasSaas`)

- **Individual** (1 profesional, 9 USD), **Premium** y **Pro** (de 2 a 20
  profesionales; AgendaPro Básico y Premium −15 %, redondeado hacia abajo). Más de 20:
  cotización (cuota fija pactada). La tarifa guarda `niveles` y `meses_anual` (10).
- Se cobra por **profesionales contratados** y **por adelantado**, por periodos de
  calendario: el primero empieza al terminar la prueba (el mes se prorratea por días)
  y cada uno al día siguiente del anterior; mensual (hasta fin de mes) o anual (un año,
  cuesta 10 meses). `estudios` guarda el plan y hasta cuándo está cubierto
  (`plan_cubierto_hasta`); un periodo que pasó completo sin cobrarse (negocio
  suspendido) no se cobra.
- Subir (cuesta más) aplica al momento y se cobra la diferencia de los días que faltan
  del periodo pagado (cargo `ajuste`, `clave` `ajuste:<ulid>`); bajar, o pasar de
  mensual a anual, aplica desde el siguiente periodo (`plan_siguiente`).
- No se contratan menos profesionales de los que tiene, y nunca se cobran menos de los
  que tiene. Después de la prueba no puede sumar más de los contratados al invitar,
  reactivar, dar el rol de profesional o importar (`PROFESSIONAL_SEATS_EXCEEDED`); en
  la prueba, hasta el máximo.
- Durante la prueba gratis tiene las funciones de Pro. Al terminar, si el dueño no
  eligió su plan, queda en Individual (un profesional) o Premium con los
  profesionales que ya tiene.
- `agendauno:generar-cargos-renta` emite a diario los periodos que empiezan; los meses
  de citas que ya rigen con la tarifa por niveles no se cobran vencidos.
- El dueño lo cambia en «Mi suscripción» (`PUT /renta/plan`, con `estudio.gestionar`).

### Funciones por nivel (citas, `FuncionesPlan`)

| | Individual | Premium | Pro |
|---|---|---|---|
| Agenda y citas, app, recordatorios por correo y app, página con URL propia, agendar sin cuenta | ✓ | ✓ | ✓ |
| Pagar al agendar en línea y cobro en caja; clientes, reseñas y reportes básicos | ✓ | ✓ | ✓ |
| Equipo (quien no atiende), varias sucursales, cabinas y recursos | — | ✓ | ✓ |
| Paquetes y membresías, mostrador e inventario, comisiones y nómina, promociones | — | ✓ | ✓ |
| Documentos y consentimientos (el aviso de privacidad del negocio, en todos) | — | ✓ | ✓ |
| Facturación electrónica (timbres), pago automático de membresías, venta en línea de paquetes | — | — | ✓ |
| Formularios, lealtad, mensajes masivos y plantillas | — | — | ✓ |
| Integraciones, llaves de API y webhooks, roles propios, reportes avanzados | — | — | ✓ |

Los negocios de clases, los de cuota fija y los que aún tienen una tarifa anterior
tienen todas. Lo decide el servidor: el middleware `plan:<función>` en las rutas y
`FuncionesPlan::exigir` donde depende del dato (otra sucursal, invitar a quien no
atiende, un consentimiento que no es el aviso de privacidad), con
`PLAN_FEATURE_NOT_INCLUDED` (403). `/yo` manda `estudio.plan` (`nivel` y `sin`, lo que no
incluye): la web oculta esas pantallas y pestañas, y al cliente del negocio no se le
ofrecen la compra en línea ni el pago automático.

### Domiciliación (cargo automático con tarjeta, `DomiciliacionRenta`)

- El dueño guarda su tarjeta en Stripe (sesión de Checkout en modo `setup`, en la
  cuenta de la plataforma, con su cliente de Stripe). Al volver se confirma con la
  sesión (`POST /renta/tarjeta/confirmar`) y el aviso de Stripe también la guarda. Solo
  se guardan marca, últimos 4 y vencimiento.
- `agendauno:cobrar-renta-domiciliada` (cada hora) cobra a la tarjeta (`off_session`)
  los cargos pendientes; un cambio de plan se cobra al momento. La llave de
  idempotencia lleva el número de intento: Stripe no cobra dos veces.
- Si el banco rechaza, se reintenta a los 3 y a los 7 días de emitido el cargo; si la
  tarjeta pide autenticación, ya no se reintenta y el dueño paga a mano. Un cargo con
  un pago en línea en curso no se cobra a la tarjeta. Sigue aplicando la suspensión por
  renta vencida (ADR 0073).

### Timbres (`TimbresTenant`)

- Paquetes de 50, 100, 200, 350 y 500 timbres, en **pesos** más IVA, al triple del
  costo por timbre de FacturAPI ($0.60 → $1.80 MXN, parámetro
  `timbres.precio_centavos`): $90, $180, $360, $630 y $900. Para negocios de clases y
  de citas Pro (en la prueba, también), solo México.
- Se compran con Stripe (cargo `timbres`, sin vencimiento: no suspende; si su pago
  caduca, queda `cancelado`) y se llevan como **saldo con movimientos** en la base del
  negocio (`saldo_timbres`, una fila que se bloquea, y `movimientos_timbres`): la
  compra pagada suma una sola vez (también se repone al consultar los timbres si la
  base del negocio no respondió) y cada factura timbrada resta uno. Sin saldo no se
  timbra (`STAMPS_EXHAUSTED`); si el proveedor rechaza la factura, no se gasta. Es un
  control de cuántas facturas emite; AgendaUno paga a FacturAPI su membresía y cada
  timbre.

## Consecuencias

- Los cargos de citas dejan de ser mes vencido por medición: son por adelantado por lo
  contratado. La medición de profesionales activos se queda como referencia.
- Un periodo puede tener más de un cargo (el del periodo, sus ajustes y compras de
  timbres): el índice único es (`estudio`, `periodo`, `clave`).
- La landing, «Mi suscripción», el superadmin (tarifas por niveles, tipo de cambio,
  cuota fija en USD) y los términos y el aviso de privacidad se actualizaron a USD,
  niveles, anual, domiciliación y timbres.
- Pendiente: Mercado Pago y OpenPay para la renta; ocultar en la app móvil las
  funciones que el plan no incluye (el servidor ya las niega).
