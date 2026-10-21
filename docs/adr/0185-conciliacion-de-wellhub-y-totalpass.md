# ADR 0185 — Conciliación de pagos de Wellhub y TotalPass

Estado: Aceptado (2026-10-20). Completa los ADR 0148 y 0149.

## Contexto

Ya registramos las visitas validadas y las reservas de Wellhub y TotalPass. Faltaba saber
si la plataforma pagó lo que debía. Según su documentación pública:

- Ninguna de las dos publica una API de pagos para los gimnasios.
- Wellhub deja descargar del Partner Portal el detalle de check-ins con su valor (.xlsx)
  y paga el día 15 por el mes anterior.
- TotalPass muestra lo pagado en su portal, pero no documenta el formato.

## Decisión

- **Lo esperado** son las visitas validadas del mes (en el día del negocio) por lo que la
  plataforma paga por visita. El negocio captura esa tarifa con su fecha de vigencia
  (`tarifas_plataforma`, migración de negocio 000178), y cada visita se espera con la
  tarifa de su día. Las excepciones (visitas que autorizó alguien del equipo sin la
  plataforma) no cuentan, porque la plataforma no las aprobó.
- **Lo recibido** es el reporte de pagos del portal, que el negocio guarda como CSV y lo
  importa (`conciliaciones_plataforma`, `pagos_plataforma`).
  - Las columnas se reconocen por su encabezado: fecha, monto y, si viene, la referencia
    del check-in.
  - Fechas AAAA-MM-DD o DD/MM/AAAA.
  - Montos con punto o coma decimal, sin flotantes.
  - Volver a importar el mismo mes lo reemplaza.
- **El emparejamiento** usa la referencia del check-in o, si no hay, el día (la primera
  visita sin pago de ese día).
- **El resumen** da lo esperado, lo recibido, la diferencia, las visitas sin pago, los
  pagos sin visita y los montos distintos. Una visita en $0 (primera visita gratis o tope
  mensual por persona) sale como monto distinto y el negocio decide si la reclama.
- Lo ven quienes ven la facturación (`facturacion.ver`). Capturar la tarifa e importar
  lo hace quien administra el negocio (`estudio.gestionar`).

## Consecuencias

- Cuando una plataforma publique sus pagos por API, se sustituye la importación; el
  resumen se queda igual.
- No se comprobó con un reporte real de cada plataforma: el formato de columnas se
  validará con el primero que traiga el dueño.
