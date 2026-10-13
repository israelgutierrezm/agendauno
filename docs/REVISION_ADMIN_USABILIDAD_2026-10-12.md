# Administración: revisión de usabilidad y mejoras

Fecha: 12 de octubre de 2026.

## Resultado y alcance

Se revisó la navegación de administración y sus patrones compartidos para las dos modalidades: clases y citas. Se implementaron mejoras concretas, conservando rutas, permisos del rol activo, restricciones de suscripción y lógica de reservas y cobros.

Esta revisión es de interfaz y usabilidad. No certifica la salida a producción ni sustituye las pruebas funcionales de extremo a extremo. En el navegador se usaron negocios de demostración: no se realizaron cobros, envíos, borrados, cambios de permisos ni altas de credenciales.

Se preservaron los cambios simultáneos del usuario en marketing, precios y medición. No forman parte de esta entrega.

## 1. Menú orientado al trabajo del dueño

| Grupo | Opciones principales | Intención |
| --- | --- | --- |
| Operación diaria | Inicio, Agenda, Alumnos/Clientes, Cobros | Resolver lo que ocurre hoy. |
| Gestión | Ventas, Captación, Equipo, Marketing, Reportes | Organizar el negocio y evaluar resultados. |
| Configuración | Configuración del negocio, Mi suscripción | Preparar la operación y administrar la plataforma. |

Las opciones secundarias siguen en pestañas del área. Configuración conserva su navegación de categorías y opciones: no se aplana un catálogo de ajustes amplio dentro del lateral principal.

### Cambios realizados

- Captación y Equipo pasan a Gestión. No se eliminan funciones ni se modifican permisos.
- Se incorpora un buscador en el menú expandido. Encuentra también vistas secundarias y sinónimos de configuración.
- Cada resultado indica su contexto: por ejemplo, «Pasarelas de pago · Configuración del negocio · Pagos e integraciones».
- El buscador toma exclusivamente las opciones permitidas de la sesión activa. No expone destinos ocultos por rol, modalidad o suscripción.
- Los resultados conservan parámetros y anclas de destino, y la búsqueda se limpia después de navegar.

### Cómo continuar

Evitar agregar cada función nueva como otra opción principal. Primero decidir si es una tarea diaria, gestión, o configuración; después ubicarla dentro de las pestañas o categorías existentes. Probar el menú con un rol personalizado, no solamente con el dueño que ve todo.

## 2. Configuración que realmente acota la pantalla

Antes, varias opciones llevaban a una página larga que mostraba datos, página pública, giro y terminología a la vez.

Ahora los destinos `#datos`, `#pagina-publica`, `#tipo-negocio` y `#terminologia` muestran solamente su sección. La publicación y los enlaces públicos se agrupan con Página pública. Al cambiar entre estas secciones, sus componentes permanecen montados para conservar borradores; esto no equivale a guardarlos ni garantiza conservarlos al salir de la página o recargar.

Los permisos de edición se mantienen. Sin una sección reconocida se conserva la vista general, por compatibilidad con enlaces existentes.

## 3. Formularios: escoger el contenedor según la tarea

No todo debe transformarse en un modal. La decisión aplicada es:

| Contenedor | Cuándo usarlo | Ejemplos |
| --- | --- | --- |
| Modal | Alta o edición delimitada, que debe devolver al listado. | Nueva ficha, conexión API/webhook, sucursal. |
| Panel lateral | Consulta o edición contextual mientras importa mantener el elemento de origen. | Detalle de una reserva o persona; conservar los patrones existentes cuando funcionan. |
| Página | Editor prolongado, con varias secciones o trabajo repetido. | Sitio web, programación, constructor de preguntas, análisis de reportes. |

### Cambios realizados

- Sucursales: formulario amplio en modal, con distribución en dos columnas cuando hay espacio. En teléfono queda en una columna y el contenido tiene desplazamiento propio.
- Ficha de datos: el alta inicial deja de ocupar permanentemente un espacio del listado. Se abre con «Nueva ficha de datos». El constructor de campos continúa en la página.
- Se añaden etiquetas persistentes para nombre, destinatarios, pregunta, tipo y opciones de respuesta. El placeholder ya no es la única identificación del campo.
- Integraciones: alta de llave y webhook a demanda, con selección visual de permisos/eventos. No se cambian los datos enviados a la API. El secreto continúa mostrándose en el bloque de entrega única tras crear la llave.
- Modal compartido: descripción opcional y estado ocupado. Durante una operación de guardado no se puede cerrarlo accidentalmente por Escape, fondo o botón de cierre. Se conserva el control de foco y bloqueo de desplazamiento del fondo.

### Criterios de aceptación para nuevos formularios

1. Título que describa la acción, no solamente el nombre del módulo.
2. Etiqueta visible de cada campo, validación comprensible y errores próximos a la tarea.
3. Acción principal y Cancelar consistentes; no duplicar botones de guardado.
4. Mientras se guarda: prevenir envío duplicado y cierre accidental.
5. Al fallar: conservar los datos introducidos.
6. Después de guardar: regresar al contexto y actualizar el elemento afectado.
7. Para borradores extensos: avisar antes de abandonar. No aplicar ese requisito indiscriminadamente a filtros o búsquedas.

## 4. Listados más legibles y visuales

### Sucursales

El listado se convierte en tarjetas con foto real cuando existe, o un icono de ubicación si no. Incluye nombre, región, dirección, zona horaria, impuesto y acciones contextualizadas. No se inventan imágenes ni direcciones.

Se incorpora búsqueda por nombre, dirección o región. La ausencia de dirección se señala como «Dirección por completar»; una búsqueda sin coincidencias tiene mensaje específico.

### Filtros

Los filtros aplicados se muestran como etiquetas removibles aunque el panel de filtros esté cerrado. Cada etiqueta muestra el valor seleccionado y permite quitar solamente ese filtro. Así el usuario entiende por qué ve menos resultados.

### Tablas compartidas

`TablaDatos` presenta las filas como fichas etiquetadas en teléfonos, conservando sus columnas, slots y acciones. La cabecera sigue disponible para lectores de pantalla. Una tabla comparativa puede optar por conservar el formato horizontal con `adaptarMovil=false`.

Este cambio no convierte automáticamente todas las tablas específicas de cada módulo: los componentes con tablas propias requieren su revisión correspondiente. No eliminar columnas financieras importantes sólo para que quepan.

### Planes

Las listas largas de clases incluidas se resumen con el número de clases y pueden desplegarse para consultar la lista completa. No se cambian condiciones, elegibilidad ni límites del plan.

## 5. Lenguaje y jerarquía visual

- Encabezado compartido con icono opcional y total en una insignia compacta.
- Agenda de clases: el campo «Oferta» pasa a «Clase», con ayuda en el modal de nueva clase.
- Equipo en modalidad clases: explicación sobre instructores y clases, sin texto pensado para citas.
- Reportes: «Ingreso promedio por alumno/cliente» en lugar de ARPU; la pestaña de personas usa la terminología del negocio.
- Ventas: «Registro manual» en lugar de la clave técnica `manual`.
- Captación: descripción breve y neutral sobre acompañar contactos hasta su primera compra o reserva, sin repetir «no es un cliente» ni prometer reglas de acceso que varían por modalidad.
- Pasarelas: etiquetas comprensibles para credenciales y sección desplegable «Configurar conexión». Se mantienen visibles el proveedor y las advertencias de preparación/prueba. Los identificadores técnicos permanecen como referencia secundaria.
- Nómina: nombres de roles personalizados desde el catálogo recibido, en vez de claves como `rol_caja`.

Los nuevos estilos usan las variables del tema. No se introduce otra paleta independiente ni se modifica el estilo de las landings.

## 6. Cobertura real de la revisión

### Inspección en navegador

En clases se inspeccionaron Inicio, Agenda, personas, captación, cobranza, instructores, ventas, comunicación y reportes. También los destinos de configuración: marca/página pública, sitio, sucursales, región, fiscal, giro, terminología, catálogo, planes, recursos, políticas/cierres/programación/límites, pasarelas, integraciones, usuarios, roles, requisitos, consentimientos y fichas.

En citas se inspeccionaron Agenda, Clientes, Cobros, Mostrador, Captación, Equipo, disponibilidad y Reportes. Se amplió la revisión a Recepción, Inventario y su modal de alta, Presupuestos, Promociones, Lealtad, Reseñas, Nómina, solicitudes de privacidad y Bitácora. Abrir una pantalla no implica haber ejecutado todos sus procesos.

Se comprobaron específicamente las mejoras de sucursales, fichas, integraciones, navegación de configuración, búsqueda del menú y filtros. Se revisaron escritorio (1440 × 900), tableta (768 × 1024) y teléfono (390 × 844) en pantallas representativas. Sucursales se comprobó también con Medianoche; se restauró el tema original. No se probaron todas las combinaciones de tema y pantalla.

### Pruebas automatizadas

- Repetición final de administración: 69 pruebas aprobadas en 12 archivos después de corregir los cambios, incluyendo Nómina y los nombres de roles personalizados.
- Formularios/configuración/captación: repetición posterior de 9 pruebas aprobadas tras añadir etiquetas al constructor.
- Tipado y revisión de estilo de los archivos modificados: aprobados.
- Compilación de producción de la aplicación (`dist/app`): aprobada. No se desplegó ni se publicó.
- La ejecución amplia inicial: 1070 aprobadas de 1075. Hubo un fallo de Reportes introducido durante el trabajo, corregido y verificado después; una prueba nueva de Configuración requirió verificar el estilo aplicado por `v-show` por el comportamiento de JSDOM con nodos desconectados. Ambos quedaron aprobados en la repetición.
- La prueba de rutas comerciales que excedió el tiempo volvió a pasar con menor concurrencia.
- Persisten dos fallos fuera de esta intervención: el test de acceso por subdominio carece de `resolve` en su router simulado y espera conservar un destino que el validador no puede resolver en ese mock; el test de compra del miembro no contempla `atribucion: null` que ahora incluye la petición. No se modificaron esos flujos ni sus pruebas para ocultar los fallos.

## 7. Siguiente prioridad de usabilidad

### A. Nómina: separar configuración de consulta — aplicado

Se aplicó el botón «Definir esquema» y el modal contextual por integrante. Periodo, indicadores y resultados permanecen en la página. Quien no tiene esquema sigue visible y puede configurarse desde su fila. Se preservó el cálculo y el contrato de la API.

### B. Lealtad: separar puesta en marcha y entrega cotidiana

Se separó la consulta del programa de su edición mediante «Configurar programa» y un modal con borrador independiente: cancelar no modifica el programa guardado. El alta de recompensa también abre un modal. Una posible mejora posterior es priorizar canjes pendientes y separar el historial por pestañas. Las confirmaciones de entregar/cancelar canjes se conservaron.

### C. Documentos y datos fiscales

Requisitos: se implementaron alta y edición en modales a demanda; la lista muestra su estado y destinatarios. Fiscal sigue pendiente: selector buscable del régimen SAT en lugar de exigir recordar su clave, validado contra el catálogo vigente. No cambiar ni inventar las reglas fiscales sólo por una mejora visual.

### D. Disponibilidad del equipo

Con una sola sucursal y un solo profesional, preseleccionar únicamente si el contexto es inequívoco y autorizado. Con varios, conservar selección explícita y mostrar el contexto junto a las acciones. Añadir un estado inicial visual que indique qué falta seleccionar, sin parecer un fallo de carga.

### E. Consistencia móvil de tablas específicas

Priorizar Nómina, promociones, bitácora y canjes. Distinguir listas convertibles en fichas de matrices que necesitan desplazamiento horizontal. El criterio es que las acciones y los estados principales estén visibles, no obligar a todas las tablas a un único formato.

### F. Cierre funcional antes de publicación

Reconciliar los dos tests pendientes de acceso/compra con las implementaciones actuales y hacer una pasada end-to-end separada de esta revisión visual: crear/editar sin duplicación, errores de API, permisos por rol/sucursal, reservas concurrentes, pagos de prueba y recuperación de borradores. No publicar con la interpretación de que esta revisión visual ya cubrió esas operaciones.

## 8. Seguimiento: consulta y gestión inequívocas

Se aplicó el ajuste solicitado después de la primera revisión:

- Placeholder del alta de ficha: «Ejemplo: Ficha médica».
- En diseño ya no se selecciona automáticamente la primera ficha. Primero se muestran tarjetas con nombre, destinatarios, estado real y número de campos.
- «Gestionar ficha» abre su contenido explícitamente. El encabezado identifica cuál se está gestionando y «Volver a las fichas» devuelve al listado.
- Agregar preguntas abre un modal que identifica la ficha de destino. Un error conserva el borrador y no cambia el contexto.
- Para personas sin permiso de gestión, el acceso es «Ver campos» y no se ofrecen acciones de alta de preguntas.
- La vista de respuestas mantiene su selector y selección inicial. Al ir desde una ficha a sus respuestas conserva la ficha elegida.
- Requisitos documentales, esquemas de Nómina y altas de recompensas adoptan el mismo patrón de consulta primero y edición explícita. Los catálogos que ya usaban botones de edición/modal conservaron ese flujo. Las páginas que son en sí una configuración no se convierten arbitrariamente en listados.

Limitación funcional preexistente: las fichas permiten crear preguntas, pero la API no ofrece modificación/borrado de preguntas existentes ni edición del nombre de la ficha. Por eso el botón dice «Gestionar ficha», no «Editar», y el texto describe las acciones disponibles. Completar esa edición exige definir versionado y compatibilidad con respuestas históricas; no se añadieron endpoints ni controles ficticios en esta intervención de interfaz.

Validación del seguimiento: 28 pruebas aprobadas en ocho archivos seleccionados, incluyendo lectura sin permisos de gestión, errores de alta, peticiones de guardado, contexto de edición, selección de la Reficha al consultar respuestas, cancelación de borradores, documentos y vista del instructor. Tipado, revisión de estilo y compilación de la aplicación aprobados. La prueba visual con computer-use verificó las cuatro áreas sin guardar datos del negocio; el modal de preguntas y el listado se comprobaron también a 390 px sin desbordamiento horizontal.
