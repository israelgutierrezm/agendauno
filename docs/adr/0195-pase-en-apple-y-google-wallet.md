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
- Sin servicio de actualización: si cambia el color o el nombre del negocio, el pase ya
  agregado no cambia hasta que se vuelva a agregar.
- En la app móvil aún no hay botón (agregar a Apple Wallet desde la app pide un plugin
  nuevo). Se hace desde la cuenta en la web.
- Sin validar con certificados reales de Apple ni con una cuenta de Google Wallet:
  probado con certificados y llaves de prueba hechos en la prueba.
