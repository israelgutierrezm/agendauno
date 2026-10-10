# ADR 0113 — CI/CD por componente: cambios, imágenes en GHCR y despliegue con aprobación

Estado: Aceptado (2026-10-10). Parte del ADR 0108 (plataforma multiproducto).

## Contexto

Un solo repositorio tiene la API, la web (aplicación y dos landings), la app de
Flutter (dos apps) y la infraestructura. El CI corría todo en cada cambio y el
servidor construía sus imágenes al actualizar. Hacía falta: no gastar el CI en lo que
no cambió, publicar imágenes inmutables por componente, poder desplegar sin entrar al
servidor y compilar las apps firmadas, sin que nada llegue a producción o a las
tiendas sin autorización.

## Decisión

- **CI por áreas.** Un trabajo `cambios` compara el PR con su base (`git diff`, sin
  acciones de terceros) y enciende API, web, app e imágenes según las carpetas que
  tocó; en `main` y si cambia el propio CI, todo.
- **Imágenes en GHCR** (`imagenes.yml`): en cada push a `main` (y etiquetas `v*`), las
  cuatro imágenes con la etiqueta del commit corto, la misma `VERSION` de
  `actualizar.sh`. Inmutables: si la etiqueta existe no se publica otra vez. Las
  variables públicas de la web salen de las variables del repositorio (las de
  producción); sin dominio ni reCAPTCHA no se publican.
- **El servidor elige.** Con `REGISTRO` en `web.env`, `actualizar.sh` baja y etiqueta
  las imágenes del commit (también con `--solo`); sin él, las construye como siempre.
  `volver.sh` no cambia: vuelve a imágenes que ya están en el servidor.
- **Desplegar** (`desplegar.yml`): solo a mano, con el entorno de GitHub del servidor
  (`staging`, `produccion`) y sus revisores obligatorios; entra por SSH (llave solo
  para desplegar, huellas fijas) y corre `actualizar.sh` (todo o `--solo`). Los datos
  que viajan se validan (sin caracteres del shell).
- **Apps** (`apps-moviles.yml`): solo a mano, por app (entornos `android-agendauno`,
  `android-turnouno` con su llave de subida y su Firebase); deja el `.aab` firmado
  como artefacto. No sube nada a las tiendas. El CI de cada PR compila los APK de los
  dos sabores (ADR 0111).

## Consecuencias

- Un PR de documentación no levanta MySQL ni compila apps; uno de la landing no corre
  la suite de la API.
- Producción puede dejar de compilar en el servidor (menos memoria y tiempo al
  actualizar) cuando se configuren las variables y el token de GHCR.
- Las imágenes de `web` y las landings son de un entorno (sus dominios van dentro):
  staging construye las suyas.

## Pendiente

- Credenciales: variables del repositorio, entornos con revisores y secretos SSH,
  servidor de staging, llaves de subida y Firebase de cada app, token de GHCR en el
  servidor.
- iOS en un runner macOS cuando existan los esquemas de cada app.
- Subir a las tiendas desde el CI (requiere autorización y cuentas).
