# Presentación comercial de precios — AgendaUno

Fecha de revisión: 7 de octubre de 2026 (modelo comercial en dólares, ADR 0107).
Revisiones anteriores: 26 de septiembre y 7 de octubre de 2026 (landing por modalidad).

## Modelo presentado

- **Precios en dólares**, más impuestos. A los negocios de México se les cobra en
  pesos al tipo de cambio FIX del Banco de México del día del cobro, más IVA; fuera de
  México, en dólares. Así se compara con AgendaPro y ReservaClase (más bajo).
- **Clases con cupo** (estudios y academias): una banda mensual según alumnos activos,
  mes vencido. ReservaClase Premium −15 %, redondeado hacia abajo. Todas las
  herramientas de clases desde el primer plan.
- **Citas 1 a 1**: tres planes por profesionales contratados, por adelantado, mensual
  o anual (el anual cuesta 10 meses: 2 de cortesía). Individual (1 profesional),
  Premium y Pro (de 2 a 20; AgendaPro Básico y Premium −15 %, redondeado hacia abajo).
  Las funciones de cada plan se deciden en el servidor (ADR 0107).
- Más de 1,000 alumnos o más de 20 profesionales: cotización con
  `ventas@agendauno.mx` o por WhatsApp (`VITE_VENTAS_WHATSAPP`).
- Cada negocio es de una sola modalidad (ADR 0104): quien da clases y atiende con cita
  registra dos negocios, cada uno con su suscripción.
- Prueba de 30 días sin tarjeta (en citas, con todo lo de Pro).
- El precio base es el importe principal y la leyenda «+ impuestos» va debajo.
- Separar la suscripción de AgendaUno de los cobros que el negocio hace a sus clientes.

## Precios utilizados

Migración `2026_10_08_000200_tarifas_en_usd.php`: una versión nueva por modalidad,
conservando las anteriores (en pesos) y sus cargos. Importes en USD al mes, sin
impuestos.

| Clases: alumnos activos | USD al mes |
| --- | ---: |
| Hasta 40 | 21 |
| 41–60 | 30 |
| 61–80 | 39 |
| 81–100 | 48 |
| 101–150 | 68 |
| 151–200 | 84 |
| 201–300 | 111 |
| 301–500 | 164 |
| 501–1,000 | 297 |
| Más de 1,000 | Cotización |

| Citas: profesionales | Premium | Pro |
| --- | ---: | ---: |
| 1 (Individual) | 9 | — |
| 2 | 24 | 33 |
| 3 | 28 | 50 |
| 4 | 33 | 50 |
| 5 | 37 | 50 |
| 6 | 41 | 58 |
| 7 | 45 | 67 |
| 8 | 50 | 75 |
| 9 | 54 | 84 |
| 10 | 58 | 92 |
| 11 | 62 | 101 |
| 12 | 67 | 109 |
| 13 | 71 | 118 |
| 14 | 75 | 126 |
| 15 | 79 | 135 |
| 16 | 84 | 143 |
| 17 | 88 | 152 |
| 18 | 92 | 160 |
| 19 | 96 | 169 |
| 20 | 101 | 177 |
| Más de 20 | Cotización | Cotización |

Timbres para facturar (en pesos, más IVA): paquetes de 50, 100, 200, 350 y 500 a
$1.80 cada uno ($90, $180, $360, $630 y $900), para clases y citas Pro en México.

## Dónde se presentan

- **Portada (`/#precios`)**: dos tarjetas «desde…», una por modalidad, con el importe
  de entrada de `precios.ts` («Clases con cupo · Por alumnos activos · desde $21» y
  «Citas 1 a 1 · Por plan y profesionales · desde $9», USD al mes más impuestos).
  Llevan a `/clases#precios` y `/citas#precios`.
- **`/clases#precios`**: `PreciosLanding` fijo en clases: tres tarjetas de rangos, la
  tabla completa, qué cuenta como alumno activo y el contacto para más de 1,000.
- **`/citas#precios`**: `PreciosLanding` fijo en citas: Individual, Premium y Pro con
  sus funciones, mensual o anual, la tabla de 2 a 20 profesionales y el contacto para
  más de 20.
- **Páginas por giro**: dicen cómo se cobra su modalidad y enlazan a `/{modo}#precios`.
- Las tarjetas llevan al registro con su modalidad (`/registro?modo=clases|citas`).
- Lo que depende de México (pagos en línea, facturación) lleva «*» y la nota
  «* Solo para clientes de México» (ADR 0099).

## Referencias de presentación

- [AgendaPro](https://agendapro.com/es/planes): precios por profesional y reparto de
  funciones por plan (Premium = su Básico, Pro = su Premium, −15 %).
- [ReservaClase](https://reservaclase.com/): rangos por alumnos (su Premium −15 %).

## Mantenimiento antes de publicar cambios de tarifa

Los importes públicos están centralizados en `apps/web/src/marketing/precios.ts`.
Son una referencia comercial estática, no una consulta automática de facturación.
Antes de publicar otra versión desde el panel de plataforma, actualizar esta referencia,
`precios.ts` y sus pruebas, y volver a generar el sitio. Validar también que las
tarifas del entorno de producción coincidan con las anunciadas. La fuente operativa es
la tarifa versionada del backend.
