# ADR 0092 — Importación de clases por fecha y programación semanal

Estado: aceptado, 2026-10-02.

## Objetivo y límites

Agenda → Importar clases ofrece dos layouts CSV UTF-8, descargables desde la pantalla:

- **Fechas específicas:** una fila por sesión, con `fecha`.
- **Programación semanal:** una fila por clase/día/horario, con `dia`, `desde`, `hasta` inclusivos. Se crean plantillas reales compatibles con cambiar una sesión o esta y las siguientes.

Es un flujo de la modalidad **clases**, protegido por `agenda.gestionar` en interfaz y servidor, incluido para roles personalizados. No importa citas privadas ni clientes ni reservas.

## Layouts

Fechas: `referencia,clase,sucursal,instructor,sala,fecha,inicio,fin,cupo,estado`.

Semanal: `referencia,clase,sucursal,instructor,sala,dia,desde,hasta,inicio,fin,cupo`.

- `referencia`: clave estable única dentro del negocio y modalidad (letras, números, guion, punto o guion bajo). No reciclarla para otra clase.
- `clase`: nombre exacto o ULID de la oferta ya configurada, no del programa general. Nombres ambiguos requieren ULID.
- `sucursal`: nombre o ULID autorizado para el usuario. Su zona horaria convierte las horas locales a UTC.
- `instructor`: nombre, correo o ULID de un profesional activo del negocio; vacío significa sin asignación y muestra advertencia. No invita usuarios automáticamente.
- `sala`: nombre o ULID de recurso activo de esa sucursal; opcional.
- `fecha`, `desde`, `hasta`: AAAA-MM-DD.
- `dia`: un día por fila, lunes a domingo o ISO 1–7. Para varios días, usar distintas filas y referencias.
- `inicio`, `fin`: HH:mm, 24 horas, fin posterior al inicio el mismo día.
- `cupo`: entero positivo; vacío hereda de la oferta. Si no hay cupo configurado, es obligatorio.
- `estado`: vacío/programada o suspendida; suspendida se materializa como cancelada, nunca reservable. Solo en fechas específicas.

Acepta coma o punto y coma y BOM de Excel. No admite XLSX directamente. Descarga sin filas de ejemplo para evitar crear sesiones accidentales. Catálogos consultables en la misma pantalla. Precios, políticas de reserva y créditos siguen perteneciendo al catálogo; no se redefinen por CSV.

## Validación y persistencia

1. Parseo estricto de encabezados y anchura de filas, máximo de 2 MB y `importaciones.max_filas`.
2. Resolución exclusivamente tenant-local y validación de scope de sucursal, incluyendo asignación del instructor.
3. Expansión semanal limitada a un año y `importaciones.max_sesiones_agenda` (500 por defecto). Los cierres globales existentes se excluyen y se reportan; una fecha específica cerrada se rechaza salvo suspensión.
4. Vista previa ejecuta la creación dentro de una transacción que siempre revierte, usando `VerificarAgendaTenant` y `GenerarAgendaTenant`. Detecta conflictos también entre filas del archivo. No envía invitaciones, recordatorios ni reservas.
5. Confirmación firmada, ligada a archivo, modalidad, negocio y actor, con vigencia de 30 minutos. La revisión también fija el resultado mostrado, para impedir aplicar silenciosamente cambios de catálogo o cierres.
6. Al guardar se ejecutan otra vez todas las reglas; si una fila falla se revierte el lote completo. Un cambio desde preview requiere revisar de nuevo.
7. Registro en `importaciones_agenda`, con referencia única, huella de fila, lote y ULIDs de sesiones/serie; auditoría `agenda.importar` con actor y resumen. Los candados de sucursales serializan cargas del negocio y se conservan los candados normales de profesional/recurso.

## Duplicados e historial

- Mismo modo + referencia + contenido: omitir, sin recrear sesiones movidas/canceladas ni reactivar series.
- Misma referencia con datos distintos: error, nunca actualizar a ciegas.
- Otra referencia que coincida con una sesión existente de la clase/sucursal/sala/hora: error y revisión manual.
- Las sesiones con reservas no se modifican. La importación solo crea.
- No existe borrado automático/reversión del lote desde esta pantalla. Corregir desde la agenda con sus permisos y reglas de cancelación. No eliminar sesiones con reservas para “reimportar”.

## Despliegue y verificación

Aplicar migraciones tenant con `php artisan agendauno:migrar-estudios` en el despliegue normal. La tabla nueva no altera sesiones preexistentes.

Pruebas: `ImportacionClasesTenantTest.php`, `RecurrenciaAgendaTenantTest.php`, `ImportarClasesView.spec.ts`, y las pruebas de acceso/menú. Las pruebas de API usan almacenamiento aislado bajo `storage/testing`.

Para cargas mayores, dividir archivos por mes/sucursal. Esta versión acota trabajo síncrono; un importador asíncrono con progreso y reanudación es una evolución independiente, no una razón para aceptar archivos sin límite.
