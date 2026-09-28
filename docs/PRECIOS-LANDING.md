# Presentación comercial de precios — AgendaUno

Fecha de revisión: 26 de septiembre de 2026.

## Modelo presentado

Mantener dos modalidades, con las mismas herramientas dentro de cada modalidad,
en lugar de inventar niveles Básico / Pro con restricciones que el sistema no aplica.

- Estudios y academias: una banda mensual según alumnos activos.
- Citas: suma marginal de profesionales equivalentes activos, considerando medio tiempo.
- Prueba de 30 días sin tarjeta.
- Mostrar el precio base como importe principal y la leyenda «+ IVA» debajo.
  Los importes introductorios y las tablas también indican que se agrega IVA.
- Separar la suscripción de AgendaUno de los cobros que el negocio hace a sus clientes.

Las tarjetas son ejemplos de capacidad, no nuevos contratos ni planes con funciones
exclusivas. Se actualizaron los rangos, no los cargos históricos ni la medición de uso.

## Precios utilizados

Rangos de clases actualizados por petición comercial. La migración
`2026_09_26_200000_rangos_alumnos_saas.php` publica una versión nueva, conservando
las anteriores. Citas no cambia.
IVA: 16 %. Importes mensuales en MXN antes de posibles prorrateos.

| Estudios: alumnos activos | Base | Total con IVA |
| --- | ---: | ---: |
| 1–49 | $339 | $393.24 |
| 50–99 | $639 | $741.24 |
| 100–199 | $909 | $1,054.44 |
| 200–300 | $1,789 | $2,075.24 |
| 301–500 | $2,649 | $3,072.84 |
| 501–2,000 | $2,889 | $3,351.24 |
| Más de 2,000 | Contactar a ventas | Cotización |

Se elimina el escalón de $1,359. El contacto comercial abre `ventas@agendauno.mx`.
El umbral de 2,000 es comercial: no bloquea las reservas ni cambia automáticamente
el contrato de un negocio. Una propuesta aceptada debe configurarse con el modo de
cuota fija existente; hasta entonces el cálculo por uso conserva su techo técnico.

| Citas: profesionales equivalentes | Base | Total con IVA |
| --- | ---: | ---: |
| 0.5 (uno de medio tiempo) | $134.50 | $156.02 |
| 1 | $269 | $312.04 |
| 2 | $495 | $574.20 |
| 3 | $630 | $730.80 |

La landing explica los tramos adicionales, el tope del componente por profesionales
y los cargos por personas en clases/talleres de un negocio de citas. El precio mínimo
de medio tiempo nunca se anuncia sin indicar esa condición.

## Referencias de presentación

- [Alanna](https://alanna.mx/precios): comparación visual de precios y beneficios.
- [AgendaPro México](https://agendapro.com/mx/planes): capacidad por profesionales y
  separación de importes y complementos.
- [ReservaClase](https://reservaclase.com/): enfoque en estudios y prueba del producto.

Se tomó la claridad de estas estructuras, no sus tarifas ni sus promesas comerciales.
No se añadieron descuentos anuales, WhatsApp incluido, atención 24/7 ni herramientas
clínicas sin una política comercial y capacidad operativa que los respalden.

## Mantenimiento antes de publicar cambios de tarifa

Los importes públicos están centralizados en `apps/web/src/marketing/precios.ts`.
Son una referencia comercial estática, no una consulta automática de facturación.
Antes de publicar otra versión desde el panel de plataforma, actualizar esta referencia,
los tramos detallados de `PreciosLanding.vue` y sus pruebas, y volver a generar el sitio.
Validar también que las tarifas del entorno de producción coincidan con las anunciadas.
La fuente operativa sigue siendo la tarifa versionada del backend y su cálculo de uso.
