# ADR 0097 — Moneda del negocio: catálogo y parámetro

Estado: Aceptado (2026-10-05).

## Contexto

El dinero siempre se guardó como `*_minor` + `moneda`, pero la moneda de lo nuevo
estaba fija en el código («MXN» en citas, escaparate, inventario, nómina y en varias
pantallas) o se escribía a mano (tres letras libres en productos y sucursales). El
reporte ya separa por moneda (ADR 0096); faltaba decidir con qué moneda trabaja cada
negocio.

## Decisión

- **Catálogo** (`Pagos\CatalogoMonedas`): las monedas con las que puede trabajar un
  negocio (MXN, USD, EUR, CAD, GTQ, CRC, DOP, COP, PEN, ARS, UYU, BRL). Todas con dos
  decimales: el dinero se guarda en centavos; una moneda sin centavos (p. ej. el peso
  chileno) necesita otro tratamiento antes de entrar.
- **Parámetro** `negocio.moneda` (ADR 0042): la plataforma fija la de todos (pesos
  mexicanos si no la cambia) y cada negocio puede usar otra. Los parámetros se guardan
  como enteros, así que la moneda se guarda con su número ISO 4217 (484 = MXN); el
  parámetro trae `etiquetas` para mostrarlas por nombre («MXN · Peso mexicano»).
- **Lo nuevo la toma** si no se indica otra: productos y planes, esquemas de pago de
  la nómina, artículos de inventario, facturas, el precio de citas y clases de pago
  (en la moneda de su sucursal, si tiene una propia) y el escaparate. Cualquier moneda
  que llegue se valida contra el catálogo.
- **Sucursal**: puede tener una moneda propia, elegida del catálogo; sin elegir, usa la
  del negocio.
- La sesión (`/yo` → `estudio.moneda`, también en la app) la expone para mostrar y
  proponer precios; cada importe guardado conserva su propia moneda.

## Consecuencias

- Cambiar la moneda no convierte lo ya creado: los precios existentes conservan la suya
  y los reportes separan por moneda.
- Las pasarelas reciben la moneda de cada cobro, pero cada cuenta acepta las suyas (una
  cuenta mexicana de Mercado Pago cobra en pesos; OpenPay México, en pesos o dólares):
  antes de cambiar la moneda hay que revisar la pasarela del negocio.
- El cobro del SaaS (renta de la plataforma) sigue en pesos mexicanos.
