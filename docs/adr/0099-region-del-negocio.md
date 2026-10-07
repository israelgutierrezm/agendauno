# ADR 0099 — Región del negocio: una moneda y su zona horaria

Estado: Aceptado (2026-10-05). Reemplaza en parte el ADR 0097. Precisado por el ADR
0103 (2026-10-07): todo negocio tiene país y ya no hay «sin país = México».

## Contexto

El ADR 0097 hizo de la moneda un parámetro con valor de plataforma y permitió que cada
sucursal tuviera la suya. El negocio pidió algo más simple y seguro: cada negocio
trabaja con UNA moneda (pesos mexicanos por omisión), y lo que depende de México (las
pasarelas de pago en línea y la facturación CFDI a sus clientes) solo funciona en
pesos. Además, la zona horaria con que se cuentan reportes, cortes y días estaba
repartida: unas partes usaban la del negocio, otras la de «la primera sucursal» y
otras UTC.

## Decisión

- **Una moneda por negocio** (`RegionNegocioTenant`): se guarda entre los parámetros
  del negocio (`negocio.moneda`, número ISO 4217) pero ya no está en el editor de
  parámetros ni tiene valor de plataforma; se cambia en «Moneda y zona horaria»
  (`GET/PUT /negocio/region`). Pesos mexicanos si no elige otra.
  - Solo se cambia **antes de cobrar**: con cobros registrados (pagos, ventas de
    mostrador u órdenes pagadas) queda fija. Al cambiarla, lo ya creado (productos,
    artículos, pagos del personal y compras sin pagar) pasa a la nueva moneda.
  - Las sucursales ya no tienen moneda propia: usan la del negocio. Cualquier moneda
    que llegue (productos, nómina, inventario, facturas, sucursales) debe ser la del
    negocio.
- **Solo en pesos mexicanos**:
  - Las pasarelas de cobro en línea (Stripe, Mercado Pago, OpenPay): con otra moneda
    no cobran (`RegistroDePasarelasTenant::activa`) ni se cargan sus llaves (422). La
    ventanilla (depósito con comprobante) sigue.
  - La facturación a sus clientes: además, solo para negocios en México (`pais` MX o
    sin país). Con otra moneda o fuera de México no se cargan datos fiscales ni sello ni
    se emiten facturas (422).
  - Las pantallas lo explican («solo funciona en pesos mexicanos y para negocios en
    México») y llevan a «Moneda y zona horaria».
- **Zona horaria del negocio** (`estudios.zona_horaria`, Ciudad de México por
  omisión): se elige en la misma pantalla. Con ella se cuentan los días de reportes,
  cortes de caja, vigencias, resúmenes y agenda del negocio; ya no se usa UTC ni la de
  la primera sucursal. Una sucursal conserva su propia zona para sus horarios; sin
  zona, la del negocio, y una sucursal nueva nace con la del negocio.
- La sesión (`/yo` → `estudio`) trae `moneda`, `zona_horaria`,
  `cobra_en_linea_posible` y `factura_posible`.

## Consecuencias

- Un negocio fuera de México o en otra moneda opera, cobra en el negocio y reporta en
  su moneda, pero sin cobro en línea ni facturación.
- La renta de la plataforma (SaaS) sigue en pesos mexicanos (`estudios.moneda`, otra
  cosa).
