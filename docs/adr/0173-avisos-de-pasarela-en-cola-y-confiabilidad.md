# ADR 0173 — Avisos de pasarela en bandeja, eventos aparcados, CSV seguro y chequeos de salud

Estado: Aceptado (2026-10-20).

## Contexto

La revisión para producción (ADR 0151) dejó cuatro pendientes de confiabilidad:

1. Los avisos de Stripe, Mercado Pago y OpenPay se procesaban dentro de la misma
   petición. Eso incluye confirmar el pago, entregar lo que se compró y, en Mercado Pago
   y OpenPay, releer el cobro en su API. Si algo tardaba, la pasarela cortaba por tiempo y
   repetía el aviso. Si algo fallaba, la pasarela recibía un error y lo reintentaba a su
   ritmo.
2. Un evento del outbox que fallaba cinco veces quedaba aparcado sin que nadie se
   enterara. Sus correos, webhooks del negocio o automatizaciones no salían.
3. Cada exportación CSV escapaba a su manera. Algunas no neutralizaban las fórmulas: un
   nombre como `=HYPERLINK(…)` se ejecutaba al abrir el archivo en una hoja de cálculo.
   Retención y la nómina (que se arma en el navegador) no lo hacían.
4. Los contenedores `api` y `web` no tenían chequeo de salud. `worker`, `scheduler` y
   `redis` sí.

## Decisión

### 1. Los avisos de pasarela van a una bandeja

Sigue la forma de los avisos de Wellhub y TotalPass (ADR 0148):

- El webhook comprueba la firma al momento, como antes. Un aviso sin firma válida no se
  guarda.
- Lo guarda en `avisos_pasarela` y contesta 200.
- Lo procesa después de responder (`defer`).
- El mismo aviso de Stripe (`evt_…`) no se guarda dos veces.
- Si procesarlo falla, `agendauno:procesar-avisos-pasarela` (cada minuto) lo reintenta a
  los 2, 4, 8 y 16 minutos.
- Al quinto intento queda «fallido» y el superadmin recibe la alerta
  `aviso_pasarela_fallido`. Si era un cobro, la conciliación de pagos lo retoma.
- Lo que hace cada aviso no cambia (`NotificacionesStripe`, `NotificacionesMercadoPago`,
  `NotificacionesOpenPay`). Sigue siendo idempotente.
- La copia de los avisos ya terminados se borra a los
  `limpieza.dias_avisos_pasarela` días (30 por omisión). Los pagos y la bitácora se
  quedan.

### 2. Los eventos aparcados avisan

- Cuando un evento del outbox agota sus intentos, el superadmin recibe la alerta
  `evento_outbox_aparcado`, con el tipo y el error.
- Ya corregida la causa, `agendauno:despachar-outbox --reintentar-aparcados` los vuelve a
  la fila.

### 3. Una sola regla para los CSV

`App\Support\Csv` (API) y `lib/csv.ts` (web) son la única forma de armar una celda:

- A un texto que empieza con `=`, `+`, `-`, `@`, un tabulador o un retorno se le antepone
  un apóstrofo, salvo que sea un número (un monto negativo sigue siendo número).
- Se entrecomilla si trae coma, comillas o un salto de línea.

La usan todas las exportaciones: clientes, padrón facturable, retención, pagos,
movimientos, bitácora, timbres, tendencias y la nómina.

### 4. Chequeos de salud de `api` y `web`

- **`api`**: `agendauno:salud-api` le pide a PHP-FPM su ping (`ping.path = /ping`) por
  FastCGI, como lo haría nginx.
  - Falla si no acepta conexiones o si todos sus procesos están atorados.
  - No agrega paquetes a la imagen: el cliente FastCGI es `PingFpm`.
- **`web`**: nginx contesta `/_salud-web` solo desde el propio contenedor y sin pasar por
  la API.
- La CI corre los dos contra las imágenes.

## Consecuencias

- Responder rápido ya no depende de las APIs de las pasarelas ni de lo que tarde entregar
  lo comprado.
- Un webhook con firma válida ya no responde 500. El fallo queda en la bandeja y, si
  persiste, en las alertas.
- Docker marca `api` y `web` como sanos o no (`docker compose ps`). No los reinicia solo:
  eso sigue a cargo de `restart: unless-stopped` y del monitoreo.
