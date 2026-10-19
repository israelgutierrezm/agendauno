# ADR 0170 — Archivos de los negocios en S3

Estado: Aceptado (2026-10-20). Amplía el ADR 0048 («los archivos viven en el volumen
`storage`») y el 0051 (respaldos).

## Contexto

Los logos, las portadas, las fotos de personas, servicios, sucursales y artículos, las
imágenes del sitio y los documentos se guardan en el volumen `storage` del servidor
(discos `public` y `local`) y se respaldan cada noche. Si el servidor se pierde, se
pierde lo subido desde el último respaldo. Además, el volumen ata la API a un solo
servidor.

El código usaba `public` y `local` en 17 lugares. Cambiar dónde se guarda obligaba a
tocarlos todos.

## Decisión

Hay dos discos de archivos, cada uno configurable por separado
(`config/agendauno.php`, `archivos`). Todo el código los toma de
`DiscosDeArchivos::publico()` y `::privado()`.

| Disco | Qué guarda | Por omisión | En S3 |
|---|---|---|---|
| Públicos (`ARCHIVOS_DISCO_PUBLICO`) | Lo que se ve en páginas públicas: logos, portadas, fotos, imágenes del sitio | `public` (volumen, `/storage/…`) | `s3_publicos`: su bucket bajo `publicos/`, servido por `ARCHIVOS_S3_URL` |
| Privados (`ARCHIVOS_DISCO_PRIVADO`) | Documentos: expedientes, contratos, comprobantes | `local` (volumen, sin URL) | `s3_privados`: otro bucket, bajo `privados/`, nunca público |

- **Dos buckets.** Las imágenes necesitan un bucket público, por su dominio o un CDN.
  Los documentos no pueden ir en él: en R2, un bucket público expone todo lo que
  tiene. Ninguno de los dos es el de los respaldos.
- **Los documentos siguen saliendo por la API**, con su permiso, también desde S3.
  Nunca se da su URL.
- **Por omisión no cambia nada**: el volumen, con su respaldo de cada noche.
- **Mudanza:** `agendauno:mover-archivos` copia lo del volumen (todo `public` y
  `local/documentos`) a los discos configurados. También cambia las URL guardadas:
  el logo, la portada y el SEO del negocio, y el borrador y las versiones de su sitio.
  - Los originales se quedan para poder volver atrás.
  - Se puede repetir: lo que ya está igual no se vuelve a copiar.
  - `--dry-run` solo cuenta.
- **Mientras tanto**, el sitio sigue aceptando sus imágenes del volumen
  (`DiscosDeArchivos::rutaPublica`). La app instalable toma el logo de donde esté: del
  propio origen si está en el volumen, de su URL si está en S3. El ícono de inicio de
  iOS, igual.
- **Respaldos:** el de archivos de cada noche sigue llevando el volumen. Si lo nuevo va
  a S3, su `LEEME.txt` lo dice: eso lo cuida el bucket con su versionado.
- **Verificación de producción:** con S3, revisa que haya URL pública para las
  imágenes y que los documentos tengan un bucket propio.

## Consecuencias

- Activarlo es solo configuración, más una corrida de `agendauno:mover-archivos`. Los
  pasos están en `docs/DESPLIEGUE.md`, «Archivos de los negocios en S3».
- Con S3, la API ya no depende del volumen para los archivos de los negocios.
- La validación con un bucket real (R2 o S3) queda para cuando el dueño cree los
  buckets: se probó con discos simulados.
