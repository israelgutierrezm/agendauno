# ADR 0195 — Pase de entrada en Apple Wallet y Google Wallet

Estado: Aceptado (2026-10-20). Sin validar con certificados reales.

## Contexto

El comparativo pidió el pase de entrada en Apple Wallet y Google Wallet, además del QR
de la app. El QR de la app (`AU1.…`) vence en minutos y la pantalla lo renueva sola, así
una captura no sirve después. Un pase en la cartera no se puede renovar cada minuto sin
un servicio de actualización por pase, que Apple y Google piden aparte.

## Decisión

- **El pase de la cartera lleva su propio código**: `AU2.{persona}.{token}`.
  - El token se guarda cifrado en la persona (APP_KEY), así el mismo código sirve en
    las dos carteras y se puede volver a entregar.
  - Recepción y el kiosco lo aceptan como el QR, con el mismo `resolver`.
- **El negocio decide si lo permite** (`acceso.pase_en_wallet`, encendido por
  omisión). Apagado, la cartera no se ofrece y esos pases dejan de servir.
- **Se renueva** desde la cuenta (si perdió su teléfono): se crea otro token y el
  anterior deja de servir en las dos carteras.
- **Apple**: un `.pkpass` con:
  - `pass.json` (genérico, con los colores de la marca del negocio);
  - íconos neutros;
  - `manifest.json` con el SHA-1 de cada archivo;
  - la firma PKCS #7 separada del manifiesto (DER), con el certificado del tipo de
    pase de la plataforma y el intermedio WWDR de Apple.

  Se empaqueta con un ZIP sin compresión propio (`App\Support\ZipSinCompresion`): la
  imagen del API no trae la extensión zip.
- **Google**: el enlace «Guardar en Google Wallet» con un JWT RS256 firmado con la
  cuenta de servicio de la plataforma. Lleva la clase genérica
  `{emisor}.agendauno_pase_entrada` y el objeto del pase con su QR.
- **Credenciales de la plataforma** por variables de entorno (`WALLET_APPLE_*`,
  `WALLET_GOOGLE_*`). Son rutas a los archivos de certificado y de la cuenta de
  servicio, no van en la base. Sin ellas, la cartera no se ofrece.

## Consecuencias

- El código de la cartera no caduca: una captura de pantalla sirve hasta que se renueve
  o el negocio lo apague. Por eso es opcional por negocio.
- Al principio el pase guardado no se actualizaba; ahora sí: ver «Se actualiza solo».
- La app móvil ya ofrece «Agregar a Apple Wallet» en iPhone (PassKit por un canal nativo,
  sin plugin) y Google Wallet en Android.
- Sin validar con certificados reales de Apple ni con una cuenta de Google Wallet:
  probado con certificados y llaves de prueba hechos en la prueba.

## Se actualiza solo (2026-10-21)

Pedido del dueño: los pases ya guardados se actualizan solos cuando cambia el plan o el
saldo.

- **El pase muestra** su plan (o «Sin plan vigente», o «en pausa»), hasta cuándo vale y
  su saldo (o «Ilimitado»), además del nombre, el negocio y el código.
- **Se anota al bajarlo**: el pase (por persona y negocio) va en la base de la plataforma
  (`pases_wallet`), con la huella de lo que muestra. El número de serie es el negocio y
  la persona por su identificador público (`{negocio}-{persona}`), así no cambia con el
  slug; el objeto de Google, `{emisor}.{negocio}_{persona}`.
- **Apple**: el pase lleva `webServiceURL` (`/api/v1/wallet/apple`) y un
  `authenticationToken` propio de su serie (HMAC con la llave de la app). El teléfono
  registra su token de avisos (`dispositivos_pase_wallet`), pregunta qué cambió y pide el
  pase nuevo, como lo pide Apple.
- **Cada 10 minutos** (`agendauno:actualizar-pases-wallet`) se revisa, negocio por
  negocio, la huella de cada pase guardado. Si cambió: un aviso vacío por APNs (HTTP/2,
  con el certificado del tipo de pase) a cada teléfono, y un `PATCH` al objeto en Google
  Wallet (token de la cuenta de servicio, guardado 50 minutos). Un teléfono que Apple ya
  no reconoce (410) se olvida; un objeto que nunca se guardó en Google (404) no es error.
- Renovar el pase cambia su código: el pase guardado recibe el nuevo.
- Sin validar con certificados ni cuentas reales (APNs y Google simulados en la prueba).
- Migración de la plataforma `2026_10_21_000409_pases_wallet`; `WALLET_APPLE_APNS_URL`
  (producción de APNs por omisión).
