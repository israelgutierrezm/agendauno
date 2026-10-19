# ADR 0171 — Respaldos cifrados

Estado: Aceptado (2026-10-20). Amplía el ADR 0051 (respaldos fuera del servidor).

## Contexto

Cada noche se respaldan la base central, la base de cada negocio y los archivos subidos
a un bucket fuera del servidor. Iban comprimidos, pero no cifrados. Esos respaldos
traen todo: clientes, teléfonos, expedientes, respuestas de formularios, datos de
salud y de menores, pagos. Si alguien llega a leer el bucket (una llave filtrada, un
permiso mal puesto, el propio proveedor), lee todo eso.

## Decisión

- **Con `RESPALDOS_LLAVE` cada respaldo sale cifrado.** La llave son 32 bytes en
  base64 (`openssl rand -base64 32`). Se cifra en el servidor, antes de subirlo.
  Lleva el sufijo `.enc` (`…sql.gz.enc`, `…tar.gz.enc`).
- **AES-256-GCM por bloques de 1 MiB**, sin cargar el respaldo en memoria
  (`CifradoRespaldos`).
  - Cada respaldo usa su propia llave, derivada de `RESPALDOS_LLAVE` con una sal al
    azar (HKDF).
  - Cada bloque lleva su número y si es el último. Un byte cambiado, un bloque de menos
    o de más, o un orden distinto se detectan al descifrar.
  - Se usa OpenSSL, que está en todas las instalaciones de PHP, y no sodium.
- **La suma sha256** sigue al lado. Es la del archivo cifrado: se comprueba antes de
  descifrar.
- **Restaurar** descifra solo, con la llave con que se guardó. Cada respaldo dice qué
  llave usó (un identificador, no la llave). Al cambiar la llave, las de antes van en
  `RESPALDOS_LLAVES_ANTERIORES` (separadas por comas). Los respaldos viejos sin cifrar
  se siguen restaurando igual.
- **El simulacro de cada domingo** restaura los respaldos cifrados. Así comprueba
  también que la llave en uso sirve.
- **Restaurar los archivos a mano**: `agendauno:bajar-respaldo` baja el paquete,
  comprueba su suma y lo descifra; queda comprimido para `tar -xzf`.
- **Sin llave**, se guardan como antes. `agendauno:verificar-produccion` lo marca:
  «Respaldos cifrados».
- El inventario que va al lado (`.inventario.json`, los negocios que trae el respaldo
  de la plataforma) no se cifra. Solo trae sus identificadores públicos.

## Consecuencias

- **La llave se guarda también fuera del servidor**, en el gestor de contraseñas del
  dueño, junto a `APP_KEY`. Si el servidor se pierde y la llave solo estaba en su
  `api.env`, los respaldos no se pueden abrir.
- Cifrar un respaldo agrega poco tiempo frente a volcarlo y comprimirlo. Ocupa casi lo
  mismo: 21 bytes más por cada MiB.
