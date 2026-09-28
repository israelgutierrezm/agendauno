# ADR 0052 — MySQL en READ COMMITTED y verificación de concurrencia

Estado: Aceptado (2026-09-27).

## Contexto

Las pruebas corren con SQLite para las bases de los negocios. SQLite ignora
`lockForUpdate` y hace una escritura a la vez, así que ninguna prueba puede
provocar una carrera. En producción cada negocio vive en MySQL (InnoDB).

La regla del dominio es serializar con un candado sobre la fila «padre» y revisar
después:

- reservar bloquea la sesión y luego cuenta los lugares;
- agendar o reprogramar una cita bloquea al profesional (y la cabina) y luego busca
  choques;
- apartar o gastar crédito bloquea el derecho y luego calcula el saldo del libro.

InnoDB trabaja por defecto en `REPEATABLE READ`: una transacción toma su «foto» de
los datos en su **primera lectura normal** y la usa hasta el final. Solo las lecturas
con candado ven lo último. Si una transacción lee algo antes de tomar el candado, las
lecturas de después del candado siguen viendo la foto vieja y no ven lo que la otra
solicitud acaba de guardar. Casos encontrados al revisar el código:

- **Último crédito.** Dos reservas de clases distintas con el mismo paquete: cada una
  bloquea su propia sesión y lee (la foto se toma ahí). La segunda espera el candado
  del derecho, pero calcula el saldo con su foto vieja: ve el crédito que la primera
  ya apartó y el paquete queda en negativo.
- **Reprogramar a una clase.** `moverAClase` lee la sesión de origen antes de
  bloquear la de destino; el conteo de lugares del destino sale de la foto vieja y
  puede sobrevender el último lugar.
- **Reprogramar una cita.** `moverCita` lee la reserva antes de bloquear al
  profesional; la búsqueda de choques no ve una cita recién agendada en ese hueco.

## Decisiones

- **Las conexiones MySQL (la central y la de cada negocio) usan `READ COMMITTED`**
  (`isolation_level` en `config/database.php`, ajustable con `DB_ISOLATION_LEVEL`).
  Cada lectura ve lo último confirmado, así que lo que se lee después del candado ya
  incluye lo que guardó la solicitud anterior. Es el comportamiento por defecto de
  PostgreSQL y el que supone el diseño. No se pierde protección: todos los candados
  del código bloquean filas existentes (la sesión, el profesional, el derecho, la
  orden), no rangos vacíos. Con binlog, MySQL exige `binlog_format=ROW` (su valor por
  defecto).
- **`agendauno:verificar-concurrencia`** comprueba en MySQL real lo que las pruebas no
  pueden. Crea un negocio temporal (`tenant_verificacion_*`, dentro del permiso
  `tenant\_%`), lanza procesos que arrancan a la vez y hacen la operación real del
  dominio, revisa el resultado y borra todo:
  - varias personas por el último lugar de una clase (cupo 1 y 3);
  - reservas simultáneas con el último crédito del paquete;
  - el mismo profesional y horario (agendar y reprogramar hacia ese hueco);
  - cancelar y reprogramar la misma reserva a la vez;
  - reprogramaciones y reservas por el último lugar;
  - una cancelación que libera lugar mientras otros lo piden;
  - respaldo y restauración de un negocio, comparando cada tabla con su suma;
  - migraciones de varios negocios: se aprovisionan a la vez y se deshacen y
    rehacen las últimas migraciones en todos a la vez; deben quedar en la misma
    versión y con el mismo esquema.

  En cada caso revisa además que el libro de créditos cuadre (ningún paquete en
  negativo y un crédito apartado por reserva con lugar) y que ningún proceso
  termine en un error inesperado (candado muerto, error de SQL).
- **CI** corre la verificación contra MySQL 8.4 después de las pruebas. En el
  servidor se corre antes de abrir y al cambiar de servidor o de versión de MySQL
  (`docs/DESPLIEGUE.md`). Con `--base=` usa una base vacía existente en lugar de
  crear bases (para un MySQL compartido); en ese modo migra un solo negocio.

## Evidencia (MySQL 8.3, 4 rondas, 6 procesos)

| Caso | REPEATABLE READ | READ COMMITTED |
|---|---|---|
| Último lugar de una clase (cupo 1 y 3) | OK | OK |
| Último crédito del paquete | 4 reservas con 1 crédito; paquete en −3 | OK: entra 1 |
| Mismo profesional y horario | 2 y 3 citas encimadas cuando reprograman | OK: 1 cita |
| Cancelar y reprogramar la misma reserva | OK | OK |
| Reprogramaciones por el último lugar | 4 y 5 personas en una clase de 1 | OK: 1 |
| Cancelación que libera lugar | OK | OK |
| Respaldo y restauración (80 tablas) | OK | OK |
| Migraciones (deshacer 3 y rehacer) | OK | OK |

## Consecuencias

- Una carrera que las pruebas en SQLite no ven se detecta en CI antes de llegar a
  producción.
- La verificación es lenta (minutos) y necesita un MySQL con permiso para crear
  bases, por eso no forma parte de la suite.
- Si en el futuro hace falta un candado de rango (evitar que aparezca una fila que
  aún no existe), no basta `READ COMMITTED`: se bloquea la fila padre, como hoy.
