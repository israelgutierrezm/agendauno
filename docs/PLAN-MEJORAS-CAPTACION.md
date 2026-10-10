# Plan de mejoras del producto y la captación de AgendaUno y TurnoUno

Guía de ejecución para producto, diseño, desarrollo, pruebas y comercialización

Fecha de referencia: 10 de octubre de 2026. Versión del documento: 1.2.

## Contenido

- [Producto común y plus opcionales](#0-producto-común-y-plus-opcionales)
- [Mejoras frente a lo actual](#1-mejoras-frente-a-las-capacidades-actuales)
- [Brechas importantes](#2-brechas-importantes-que-requieren-completar-el-producto)
- [Captación y continuidad del cliente](#3-áreas-de-oportunidad-para-conectar-mejor-la-captación)
- [Orden de ejecución](#4-orden-propuesto-para-ejecutar-el-plan)
- [Pruebas de aceptación](#5-pruebas-para-aceptar-cada-entrega)
- [Decisiones de negocio](#6-decisiones-de-negocio-que-deben-documentarse)
- [Ventajas y límites de alcance](#7-ventajas-que-conviene-desarrollar-y-comunicar)
- [Referencias](#8-referencias-para-continuar-la-implementación)
- [Plantilla de tareas](#9-plantilla-para-convertir-un-punto-en-una-tarea)

## Propósito y resultado esperado

AgendaUno y TurnoUno son dos productos principales, especializados y con la misma importancia estratégica. AgendaUno tiene prioridad de ejecución y será el primero en lanzarse; eso no lo convierte en el producto central ni convierte TurnoUno en una extensión suya. Ambos utilizan un núcleo técnico compartido, que no pertenece comercialmente a una marca por encima de la otra.

AgendaUno debe ser fácil para vender y agendar clases. TurnoUno debe ser fácil para vender servicios y agendar citas. La base de cada producto debe cubrir su operación común sin exigir configurar una página web completa, campañas o reglas contractuales complejas. Los negocios que necesitan una presencia de marca más elaborada, como el ejemplo de HYX, pueden activar un sitio avanzado y otros plus sobre la base del producto que les corresponde.

La captación básica sí forma parte de ambos productos: compartir un enlace, mostrar la oferta y permitir que el cliente complete el recorrido autorizado. El plus amplía la presentación o una operación especializada; no debe ser necesario para vender la primera clase y reservarla en AgendaUno, ni para ofrecer un servicio y agendarlo en TurnoUno.

Este documento sirve para convertir las recomendaciones en tareas. Cada punto describe el estado actual, el cambio propuesto, cómo realizarlo y cómo comprobar que quedó completo. Las tres primeras secciones corresponden a mejoras de lo existente, brechas importantes y oportunidades de captación. Después se presenta el orden de ejecución y las pruebas de aceptación.

La referencia competitiva principal es Buq y Fitune, incluidos los sitios públicos de HYX y Fluo Movimiento. El objetivo es aprovechar sus aciertos sin copiar módulos que no aporten valor al lanzamiento. Una función anunciada por un competidor no demuestra que funcione bien en todos sus clientes.

### Alcance por marca

| Aspecto | AgendaUno | TurnoUno |
| --- | --- | --- |
| Especialización actual | Clases grupales, estudios, academias y fitness | Servicios con cita y atención por profesional |
| Recorrido principal del cliente | Elegir actividad, horario y plan; reservar una clase | Elegir sucursal, servicio, profesional y horario; confirmar cita |
| Cobro de la plataforma | Alumnos activos conforme a la política comercial | Plan y profesionales conforme a la política comercial |
| Contenido de la landing | Operación de clases, cupos, paquetes, membresías y seguimiento | Agenda individual, servicios, equipo y cobros |
| Núcleo del sistema | Compartido, con capacidades por modalidad | Compartido, con capacidades por modalidad |

Actualmente un negocio opera una modalidad. La combinación de clases y citas dentro del mismo negocio no debe venderse como disponible. Las cuentas, créditos y datos siguen perteneciendo a cada negocio; mejorar la captación no implica compartirlos.

### Cómo interpretar el estado actual

Las capacidades descritas se basan en la revisión de código y documentación y en los recorridos públicos de los competidores. El último corte consultado del repositorio fue `75683a0`. Los criterios de aceptación de este documento son comprobaciones que deberán ejecutarse al implementar; no representan pruebas ya aprobadas.

Los ajustes recientes de vista previa, tema oscuro, captación de interesados y captcha ya tienen correcciones en los últimos commits. Deben verificarse al desplegar, pero no se presentan aquí como fallos todavía abiertos. Este plan tampoco constituye una autorización para publicar, cobrar, abrir el autorregistro o cambiar una política comercial.

### Prioridades y uso de la guía

- **P0:** inconsistencia que afecta disponibilidad, dinero, acceso o datos. Se cierra antes de abrir el recorrido afectado.
- **P1:** mejora necesaria para ofrecer una primera experiencia comercial completa al público seleccionado.
- **P2:** diferenciación o crecimiento posterior. Se adelanta solo si el segmento de lanzamiento la necesita.
- **Condicionada:** su necesidad depende del alcance vendido. Una academia infantil y un estudio de adultos no tienen los mismos requisitos.

Los identificadores M corresponden a mejoras de capacidades existentes, B a brechas y C a captación. Una tarea se considera terminada cuando tiene implementación, pruebas, permisos, comportamiento móvil y evidencia de aceptación. No basta con añadir una pantalla.

## 0 Producto común y plus opcionales

### La base de cada producto

No se define una marca central y otra secundaria. Se distinguen tres niveles: los dos productos comerciales, la operación común completa de cada uno y sus ampliaciones opcionales. El núcleo técnico compartido evita duplicación, pero cada producto tiene identidad, incorporación, navegación y criterios de aceptación propios.

| Producto | Operación común que debe resolver por sí mismo | Prioridad de entrega actual |
| --- | --- | --- |
| AgendaUno | Actividades, sesiones, cupos, alumnos, paquetes o membresías, venta, reserva y asistencia | Primero en lanzarse |
| TurnoUno | Servicios, precios y duración, profesionales, disponibilidad, clientes, citas, atención y cobro | Mantener y validar su recorrido propio; no presentarlo como derivado de AgendaUno |

El recorrido básico de AgendaUno consiste en configurar actividades y horarios, ofrecer un paquete o membresía, venderlo, reservar, registrar asistencia y dar seguimiento al saldo o vencimiento. El de TurnoUno consiste en configurar servicios y disponibilidad, agendar con un profesional, atender y cobrar. Un profesional independiente debe poder hacerlo sin módulos de academia ni un equipo ficticio. En ambos casos, el dueño debe operar desde una interfaz clara sin convertirse en diseñador web o especialista en marketing.

El cliente debe poder consultar la oferta, entender qué incluye, pagar cuando el canal esté habilitado y reservar desde el teléfono. Si el negocio mantiene admisión controlada, la solicitud e invitación deben ser sencillas y trazables. Este criterio no autoriza abrir el autorregistro ni modifica la política vigente.

### Qué pertenece al común y qué puede ser un plus

| Área | Base completa del producto correspondiente | Ampliación opcional |
| --- | --- | --- |
| Agenda | Sesiones, cupos, instructor cuando aplica, reserva, espera y cancelación | Selección visual de estaciones, recursos o reglas especializadas |
| Venta | Planes, cobertura, vigencia, pago y saldo comprensibles | Acuerdos con permanencia, cuotas y bajas contractuales complejas |
| Personas | Alta, invitación, ficha y roles necesarios para operar | Gestión familiar o relaciones adicionales si el segmento lo requiere |
| Acceso público | Página de reservas simple con marca, horarios, planes, contacto y enlace compartible | Sitio comercial de mayor contenido, galería, páginas adicionales y personalización avanzada |
| Dominio | Subdominio del producto y enlaces operativos | Dominio propio verificado y su administración |
| Seguimiento | Avisos operativos, pendientes de atención y vencimientos visibles | Campañas, automatización comercial, segmentación y CRM ampliados |
| Reportes | Ventas, asistencia, saldos y datos básicos de operación | Análisis comercial avanzado, atribución y tableros especializados |
| Experiencia móvil | Recorridos principales utilizables en teléfono | Canales o marca blanca adicionales según disponibilidad y oferta |

Las filas de sesiones, créditos, paquetes y membresías describen principalmente AgendaUno. En TurnoUno, la base equivalente incluye servicios con precio y duración, disponibilidad y asignación por profesional, confirmación o reprogramación de citas y cobros. Los paquetes o membresías no deben exigirse para operar citas. Esta tabla clasifica tipos de capacidades, no impone funciones idénticas a ambas marcas.

Esta clasificación es una propuesta de producto, no una modificación inmediata de funciones disponibles ni una tarifa aprobada. Las funciones que ya existen no se retiran por llamarlas plus. Primero se decide su activación y presentación; cualquier cambio contractual o de plan requiere una decisión comercial separada.

Una función específica de un segmento no tiene que ser automáticamente un complemento de pago. Por ejemplo, familias puede ser imprescindible para una academia infantil, y una segunda sede requiere controles de cobertura completos. Si se vende ese segmento o alcance, sus controles necesarios deben estar incluidos en la experiencia ofrecida. Nunca separar como extra de pago la seguridad, la autorización, la consistencia del cupo o la corrección de los cobros.

### El sitio propio como ampliación de la presencia comercial

La página básica debe permitir operar de inmediato sin editar bloques. Puede tomar nombre, logo, dirección, catálogo y horario del negocio y presentar acciones útiles de forma automática. Publicar una agenda no debe depender de subir fotografías, redactar una historia del estudio o completar una galería.

El plus de sitio permite desarrollar una presentación más completa: portada de marca, explicación de disciplinas, entrenadores, preparación para la primera visita, galería, preguntas frecuentes, campañas y, cuando se implemente, dominio propio. Sigue usando el catálogo, disponibilidad, pagos y reservas del mismo negocio. No debe mantener un segundo inventario de planes ni una agenda duplicada.

El sitio completo no sustituye ni bloquea el enlace directo para reservar. Un negocio puede compartir su portada, una clase, sus planes o la agenda según el contexto. La necesidad de esa ampliación depende de su estrategia comercial, no de que practique HYROX o se llame HYX.

### Cómo evitar que los plus vuelvan genérica la interfaz

1. Iniciar con las tareas comunes y mostrar solo capacidades pertinentes a la modalidad y configuración del negocio.
2. Activar ampliaciones de manera explícita desde una sección secundaria de capacidades, con explicación, requisitos y vista previa del efecto.
3. No llenar el menú principal de módulos deshabilitados. Los plus activos aparecen donde se usan; la oferta comercial de complementos no compite permanentemente con la agenda.
4. Mantener formularios simples y abrir reglas avanzadas cuando el usuario las necesita. Con una sede o un instructor, no exigir selecciones redundantes.
5. Mantener permisos por rol y alcance aunque una capacidad se habilite. Activar un módulo no concede acceso a todos los usuarios.
6. Probar activación y desactivación. Ocultar una función no debe borrar contratos, publicaciones ni historial; debe definir qué acciones siguen permitidas y cómo se conservan obligaciones existentes.

La aceptación es independiente por producto: un estudio sencillo puede vender y reservar en AgendaUno sin activar ningún plus; una barbería o un profesional puede agendar, atender y cobrar en TurnoUno sin activar ningún plus ni configurar clases. En ambos, un sitio avanzado conserva la misma consistencia de catálogo, disponibilidad y cobros. Los plus amplían el alcance, no reparan un producto básico incompleto.

## 1 Mejoras frente a las capacidades actuales

### M01 Convertir el sitio del negocio en una herramienta de conversión

**Actual.** Ya existen tres plantillas, secciones ordenables y ocultables, textos, fotografías, banners, borrador, vista previa y publicación. Horarios, servicios y precios salen de la operación. Guardar no publica. No se necesita construir otro editor desde cero.

**Qué mejoraría.** El sitio debe ayudar al visitante a decidir si ese negocio es adecuado para él y cuál es su siguiente acción. En clases, debe orientar a quien empieza y a quien ya conoce el estudio. En citas, debe llevar rápidamente al servicio disponible.

**Método de ejecución.**

1. Definir plantillas de partida por modalidad y giro, con secciones útiles y textos sugeridos editables. Una plantilla de pole dance puede explicar niveles y primera clase; una de barbería debe priorizar servicios, profesionales y sucursales.
2. Incorporar bloques estructurados de preguntas frecuentes, galería y preparación para la primera visita. Añadirlos al catálogo actual de secciones, conservando la validación y el límite de contenido.
3. Asociar cada botón a una acción real: explorar horario, ver planes, pedir acceso o agendar. Evitar que todos terminen en un contacto genérico.
4. Mostrar una lista de preparación antes de publicar: portada, contacto, ubicación, oferta disponible, aviso publicado y destino válido de los botones. Distinguir errores que impiden publicar de sugerencias de calidad.
5. Añadir la posibilidad de recuperar una publicación anterior, con confirmación e historial. No confundir recuperar el diseño con recuperar precios u horarios anteriores.

**Entrega y aceptación.** El dueño puede preparar y publicar un sitio sin ayuda técnica. El borrador no se filtra al público. Un cambio operativo de precio u horario se refleja sin editar el sitio. Una persona nueva identifica el tipo de negocio, ubicación y siguiente acción en móvil. No hay enlaces sin destino ni fotografías obligatorias para completar el recorrido.

Prioridad P1 para la página operativa básica y sus botones; P2 para ampliar contenido, personalización y recuperación editorial. El sitio avanzado no es un requisito para vender y reservar desde el enlace básico. Depende de M02, M03 y B01 para que los botones tengan destinos completos. Responsable sugerido: producto y diseño, con desarrollo web y API.

### M02 Completar la agenda pública de clases

**Actual.** El escaparate presenta próximas sesiones, actualmente limitado a 12, y un horario de clases. La agenda autenticada del alumno ya cubre periodos más completos. La lista pública de próximas sesiones no equivale a un calendario navegable con selección de una clase concreta.

**Qué mejoraría.** Permitir que un visitante encuentre una clase por día, sucursal, actividad, nivel e instructor y que conserve su elección al entrar o solicitar acceso. Esta es una diferencia importante frente al recorrido observado en HYX y Fluo Movimiento.

**Método de ejecución.**

1. Crear una consulta pública paginada por periodo, limitada a sesiones publicables, con identificador público estable y zona horaria del negocio.
2. Diseñar vista semanal en escritorio y tira de fechas con lista diaria en móvil. Mostrar duración, instructor, sede, nivel cuando exista y disponibilidad entendible.
3. Añadir filtros dependientes de los datos disponibles; no mostrar un filtro de nivel vacío en todos los negocios.
4. Si un día no tiene clases, ofrecer la siguiente fecha disponible sin cambiar silenciosamente la fecha elegida.
5. Al seleccionar una sesión, mostrar su detalle y el siguiente paso según la política de admisión. Conservar la selección mediante un contexto temporal del recorrido, sin guardar información personal en enlaces públicos.
6. Resolver la disponibilidad con la misma regla del motor de reservas. B02 detalla la inconsistencia del contador actual.

**Entrega y aceptación.** Se puede recorrer un mes completo sin extrapolar un horario semanal como si todas las sesiones existieran. Tras autenticarse se recupera la clase elegida, salvo que haya dejado de estar disponible; en ese caso se explica el cambio. No se publican asistentes, datos de miembros ni citas privadas. La confirmación siempre revalida en servidor.

Prioridad P1, con B02 en P0. Responsable sugerido: API, web y pruebas; móvil cuando reciba el mismo enlace de sesión.

### M03 Explicar mejor paquetes y membresías antes de comprarlos

**Actual.** Hay productos con créditos o acceso ilimitado, vigencias, ciclos y restricciones de actividad y sucursal. El sitio público muestra nombre, precio y clases o acceso ilimitado, pero no presenta la vigencia con la misma claridad que la compra del miembro. La API ya entrega parte de esa información.

**Qué mejoraría.** Que el comprador entienda qué puede reservar, dónde, desde cuándo y hasta cuándo. Los nombres creativos del producto pueden mantenerse, pero deben acompañarse de condiciones comparables.

**Método de ejecución.**

1. Definir una presentación comercial derivada del producto operativo: número de clases o acceso ilimitado, vigencia, actividades incluidas y cobertura de sedes.
2. Diferenciar visualmente clase de prueba, paquete y membresía. Un producto recurrente debe indicar periodicidad y condiciones de renovación; uno no recurrente no debe parecer una suscripción.
3. Explicar vigencia por días desde activación frente a fin de mes calendario. Antes del pago, mostrar fechas concretas calculadas para la compra propuesta.
4. Mostrar el total del primer pago y separar cargos adicionales si realmente están implementados y aplican. No anunciar inscripción o anualidad automática basándose en un cobro manual.
5. Permitir comparación corta entre productos y señalar cuáles cubren la sesión o sucursal seleccionada.
6. Revisar reglas de visibilidad de planes privados, archivados, exclusivos y ofertas de primera visita. Si una regla no existe, crearla explícitamente; no simularla solo ocultando una tarjeta.

**Entrega y aceptación.** Un alumno distingue 8 clases hasta fin de mes de 8 clases durante 30 días. Un plan de Roma no aparece como válido para Polanco. El resumen, la orden y el derecho otorgado coinciden. Las condiciones no dependen de texto copiado a mano en la landing.

Prioridad P1. Depende de B03 y, para contratos o cargos adicionales, de B04. Responsable sugerido: producto, API y diseño.

### M04 Conectar la primera compra con la admisión del cliente

**Actual.** Un miembro dado de alta e invitado puede comprar planes y pagar en línea. El cliente nuevo de clases llega a “Pedir acceso”, conforme a la decisión vigente de registro cerrado. En citas, una reserva pública puede crear persona y cita sin crear cuenta.

**Qué mejoraría.** Reducir la pérdida entre interés y compra sin vulnerar la política de acceso. No hace falta quitar el control del negocio para mejorar el recorrido.

**Método de ejecución.**

1. Implementar primero la solicitud de acceso dentro del negocio, con sesión o plan de interés, estado y responsable de seguimiento. B01 define su alcance.
2. Cuando el negocio apruebe, crear o localizar la ficha, emitir invitación y recuperar el contexto comercial al activarla.
3. Evitar pedir de nuevo datos ya proporcionados, sin asumir que coincidencia de correo prueba identidad ni autorización.
4. Reutilizar órdenes, pagos y concesión de derechos existentes. La activación del plan debe ocurrir una vez, después del evento de pago válido.
5. Si posteriormente se aprueba venta abierta, desarrollar una variante configurable con verificación de contacto y reglas de admisión. Mantener el modelo cerrado por defecto hasta esa autorización.

**Entrega y aceptación.** Una solicitud puede convertirse en invitación, compra y reserva con trazabilidad. Reenviar un enlace o repetir un webhook no duplica cuentas, órdenes ni créditos. Una reserva invitada de TurnoUno no se transforma inadvertidamente en cuenta. El visitante sabe si espera aprobación o si puede continuar inmediatamente.

Prioridad P1. Depende de B01, M03 y M07. Responsable sugerido: producto, API y web; seguridad revisa el cambio de acceso.

### M05 Mostrar las consecuencias de reservar y cancelar

**Actual.** Existen cupos, lista de espera, asistencia, cancelaciones, políticas y resolución de cobertura del plan. El alumno puede recibir motivos por los que una sesión no está incluida. Falta hacer esas reglas fáciles de entender en el momento oportuno.

**Qué mejoraría.** Mostrar la decisión relevante antes de confirmar, sin convertir cada pantalla en un reglamento extenso.

**Método de ejecución.**

1. Antes de reservar, indicar plan utilizado, consumo, sede y fecha límite de cancelación en horario local.
2. Si no puede reservar, mostrar el motivo principal y una acción pertinente: ver planes, cambiar sede o revisar vigencia. No usar un mismo mensaje genérico para todo.
3. Diferenciar “reserva confirmada”, “lista de espera”, “lugar ofrecido” y “pendiente de pago”. El color no debe ser el único indicador.
4. Antes de cancelar, calcular el resultado real: devolución, consumo o imposibilidad de cancelación. Aplicar el mismo cálculo en servidor.
5. Mostrar transferencias de clase y cambios relevantes en el historial de la reserva con fecha, actor y motivo, sin mezclarlo con un historial financiero.

**Entrega y aceptación.** Una cancelación tardía no promete devolución. Un crédito vencido no recupera vigencia por un reintegro. Las reservas en espera no parecen confirmadas. El resultado en web, móvil, recepción y notificaciones es congruente.

Prioridad P1. Depende de B02 y de la política de beneficios de B04. Responsable sugerido: dominio de reservas, diseño y pruebas.

### M06 Guiar la elección de sucursal y la cobertura

**Actual.** Hay sedes y restricciones de cobertura en productos y reservas. Esto permite vender productos de una sede y productos multisucursal sin crear otra cuenta del cliente dentro del mismo negocio.

**Qué mejoraría.** Que la sede sea una elección contextual y no un filtro olvidado. El visitante debe comprender si está comprando acceso a una o a varias ubicaciones.

**Método de ejecución.**

1. Con una sola sede, evitar un paso obligatorio de selección. Con varias, presentar tarjetas con nombre, zona, dirección y fotografía opcional.
2. Conservar la sede elegida durante la consulta de clases, servicios y planes. Al cambiarla, revalidar profesional, actividad, horario y producto.
3. Mostrar “solo esta sede” o “todas las sedes incluidas” según la cobertura real. No llamar multisucursal a una lista parcial sin mostrar cuáles incluye.
4. Separar autorización para administrar una sede del derecho del cliente a reservar allí. Son controles distintos.
5. Ofrecer enlace de ubicación validado y dejar claro dónde se realizará la cita o clase.

**Entrega y aceptación.** Cambiar de Roma a Polanco no conserva una hora o profesional inválidos. Un plan local no permite reserva en otra sede por acceso directo a la API. En móvil, las tarjetas siguen legibles y la sede permanece en el resumen.

Prioridad P1. Responsable sugerido: API, diseño y web; pruebas de permisos y cobertura.

### M07 Hacer los cobros comprensibles y verificables

**Actual.** Hay caja, órdenes, pagos, conciliación, facturación y motor de cobro recurrente. La existencia del código no sustituye pruebas con la configuración efectiva de la pasarela y los servicios del entorno de lanzamiento.

**Qué mejoraría.** Que recepción y dueño entiendan qué se debe, qué se pagó y qué requiere revisión, y que el cliente nunca confunda un intento de pago con un pago confirmado.

**Método de ejecución.**

1. Presentar estados de orden, intento y pago con lenguaje operativo. Añadir fecha, referencia y siguiente acción; no mostrar respuestas técnicas del proveedor al cliente.
2. Separar saldo pendiente, cobro recurrente y renovación de beneficios. Un fallo de cargo no debe producir una nueva deuda duplicada en cada reintento.
3. Guiar la configuración de pasarela y señalar cuándo está en pruebas. Bloquear promesas de cobro en línea si falta la preparación necesaria.
4. Verificar pago aprobado, rechazado, abandonado, devolución y webhook repetido o fuera de orden, en pruebas controladas.
5. Revisar el corte SaaS por producto y hacer visible su cálculo para el dueño. La definición de alumno activo debe coincidir con la política comercial publicada.
6. Comprobar conciliación, permisos de devolución y correcciones con auditoría. No tratar el cálculo de honorarios del instructor como nómina fiscal certificada.

**Entrega y aceptación.** El cobro confirmado concede el derecho una sola vez. Una respuesta incierta no se muestra como pago exitoso. Se puede rastrear una diferencia entre orden, pasarela y caja. Las pruebas externas quedan documentadas; no se ejecutan cobros reales sin autorización.

Prioridad P0 para el canal de pago que se vaya a abrir. Responsable sugerido: API y operaciones; producto define los textos y pruebas documenta resultados.

### M08 Acercar las acciones del equipo a la agenda diaria

**Actual.** Existen roles, permisos, alcances por sede, sustituciones, recepción y cálculo de honorarios. El menú ya separa operación diaria, gestión y configuración. El problema a resolver es la cantidad de recorrido necesaria para ejecutar una tarea, no simplemente renombrar todo el menú.

**Qué mejoraría.** Un dueño debe iniciar por lo que necesita atención hoy; un instructor por sus sesiones; un profesional por sus citas. El independiente no debe sentir que administra una cadena.

**Método de ejecución.**

1. Priorizar agenda y recepción en operación diaria. Desde una sesión, permitir revisar asistentes, marcar asistencia, resolver un pago o sustituir instructor con permisos adecuados.
2. En el inicio del dueño, mostrar tareas concretas: pagos pendientes, clientes por confirmar, sesiones con incidencias y solicitudes de acceso. Cada indicador debe abrir el listado ya filtrado.
3. En TurnoUno con un profesional, preseleccionar su agenda y evitar filtros de equipo innecesarios. Mantener disponibles las funciones de crecimiento sin exigir configurarlas.
4. En gestión, conservar personas, productos y reportes. En configuración, usar categorías y navegación interna para negocio, catálogos, reglas e integraciones.
5. Resolver títulos repetidos con una jerarquía consistente: contexto en encabezado y título útil en contenido, sin duplicar “Inicio” o “Configuración” como dos titulares equivalentes.
6. Filtrar por permisos del rol activo, no por todos los roles asignados. Mantener la elección inicial de rol antes de habilitar navegación operativa.

**Entrega y aceptación.** El dueño llega al problema desde el indicador sin volver a filtrar. Un instructor no ve acciones administrativas por compartir otros roles. Un independiente puede completar una cita y cobrar sin pasar por membresías. Menú, pestañas y acceso directo aplican la misma autorización.

Prioridad P1. Responsable sugerido: producto, diseño y web, con revisión de permisos en API.

### M09 Convertir la retención en seguimiento útil

**Actual.** Hay radar, segmentos, comunicaciones y tareas. Los segmentos trabajan sobre miembros existentes. El motor de automatización tiene eventos y creación de tareas; esto no equivale todavía a un sistema completo de campañas comerciales automáticas.

**Qué mejoraría.** Que el negocio sepa a quién contactar, por qué y con qué resultado. Primero debe ser útil con seguimiento humano; después podrá automatizar canales.

**Método de ejecución.**

1. Definir señales verificables: primera clase asistida, plan por vencer, plan vencido y días sin asistencia. No usar “cliente perdido” sin una regla explícita.
2. Crear una tarea con responsable, motivo, fecha y enlace a la persona. Evitar duplicados mientras exista una tarea abierta por el mismo motivo.
3. Preparar mensajes sugeridos editables. Separar avisos necesarios de promociones y respetar la preferencia del cliente.
4. Registrar contacto realizado y resultado: renovó, no respondió, no desea contacto o requiere atención.
5. Para automatizar envíos, incorporar programación, límite de frecuencia, baja, deduplicación, reintentos y registro de entrega. Empezar con un canal preparado, no con todas las redes a la vez.

**Entrega y aceptación.** El negocio no recibe cinco tareas iguales por el mismo vencimiento. Un cliente que no acepta promociones queda excluido de esa campaña. Se puede medir renovación después del seguimiento sin atribuir causalidad automáticamente.

Prioridad P1 para tareas y P2 para envíos automáticos. Depende de C04, C06 y C08. Responsable sugerido: producto, API y operaciones comerciales.

### M10 Aclarar el alcance de Wellhub y TotalPass

**Actual.** Existen validadores de check-in para ambos proveedores. Eso no demuestra sincronización completa de reservas, membresías o liquidaciones con sus plataformas.

**Qué mejoraría.** Presentar una integración con límites claros y operación recuperable cuando el proveedor no responda. Fitune los anuncia como integraciones; la comparación debe hacerse por flujo concreto, no por una casilla de “sí integra”.

**Método de ejecución.**

1. Documentar qué valida cada proveedor, las credenciales necesarias, restricciones temporales y datos conservados.
2. Diseñar estados de validación aprobada, rechazada y no verificable. No registrar asistencia externa como aprobada por un error de conexión.
3. Definir la excepción manual permitida, quién puede autorizarla y qué evidencia queda. No asumir que el proveedor acepta cualquier excepción.
4. Verificar con credenciales y entorno autorizados. Establecer procedimiento de soporte y alertas sin registrar secretos en logs.

**Entrega y aceptación.** La comunicación comercial dice “validación de check-in” cuando ese sea el alcance. Hay pruebas del proveedor o una indicación explícita de pendiente. Una indisponibilidad no bloquea toda la operación local ni inventa validaciones.

Prioridad condicionada: P0 si se ofrece como requisito operativo del piloto; P2 en otro caso. Responsable sugerido: integraciones y operaciones.

### M11 Preparar cada canal móvil según lo que realmente ofrece

**Actual.** Existe PWA por negocio y dos variantes Android del núcleo Flutter. La PWA está pendiente de validación desplegada; la publicación en tiendas requiere sus cuentas y credenciales. iOS y marca blanca tienen trabajo futuro. La PWA no tiene push web implementado; no debe prometerlo por tener FCM en la app oficial.

**Qué mejoraría.** Facilitar la instalación y el regreso al negocio, evitando prometer una app propia cuando se está ofreciendo una web instalada. La ayuda de Fitune distingue expresamente su web app de una descarga desde tiendas.

**Método de ejecución.**

1. Priorizar el recorrido móvil de reserva, compra, cancelación y perfil antes de añadir más funciones.
2. Probar instalación de PWA, nombre, icono, apertura de enlaces y cambio de sesión con dos negocios del mismo producto.
3. No guardar respuestas privadas de API en caché como contenido reutilizable por otro usuario. Limpiar la información de sesión al salir.
4. Para Android, comprobar enlaces profundos, regreso del pago, invitación, permisos de notificaciones y restricción de producto.
5. Preparar una ficha comercial por canal con funciones disponibles y pendientes. Publicar iOS o marca blanca solo después de su implementación y validación.

**Entrega y aceptación.** El cliente abre la clase elegida desde un enlace y no llega a un inicio sin contexto. No aparecen datos de otro negocio o sesión. La instalación no se ofrece como funcionamiento completo sin internet. La landing coincide con la disponibilidad real de cada canal.

Prioridad P1 para web móvil y PWA desplegada; P2 para nuevos canales. Responsable sugerido: web, móvil y operaciones.

### M12 Reforzar identidad y posicionamiento del negocio

**Actual.** Hay subdominios por producto, sitio público con marca del negocio, metadatos y páginas de marketing por giro. Los dominios propios de clientes están diseñados, pero no habilitados. La actualización de SEO en el navegador no prueba por sí sola que el contenido completo se indexe correctamente.

**Qué mejoraría.** Que el negocio tenga una presencia pública encontrable y coherente, y que las dos marcas de la plataforma atraigan al público correcto.

**Método de ejecución.**

1. Separar SEO de la plataforma y SEO de cada negocio. Las landings venden el SaaS; el sitio del estudio vende clases o servicios.
2. Revisar respuesta HTML inicial, títulos, descripción, canonical, sitemap y datos estructurados con herramientas de inspección. Decidir prerenderizado o renderizado en servidor según el resultado, no por preferencia tecnológica.
3. Mantener páginas de acceso, perfil, vista previa y datos privados fuera del índice. Publicar únicamente páginas habilitadas y contenido vigente.
4. Reforzar contenido útil de actividad, zona, primera visita y preguntas frecuentes. Evitar páginas casi idénticas que solo cambien el nombre de una disciplina.
5. Implementar dominios propios después del flujo de B07. Mientras tanto, mejorar compartir enlaces y códigos QR del subdominio existente.
6. Actualizar documentación y matriz comercial cuando una función cambie de estado. No mantener “prelanzamiento cerrado” si el registro del producto está abierto.

**Entrega y aceptación.** Un buscador recibe título y contenido público relevantes, los enlaces compartidos muestran la marca correcta y no se indexan borradores. No se garantiza una posición ni tráfico por añadir metadatos. El sitio mantiene un único destino canónico por contenido.

Prioridad P1 para consistencia e inspección SEO; P2 para dominio propio. Responsable sugerido: web y marketing, con infraestructura para dominios.

## 2 Brechas importantes que requieren completar el producto

### B01 Solicitudes comerciales de clientes dentro de cada negocio

**Brecha.** “Pedir acceso” conduce principalmente a contacto por WhatsApp o teléfono. Falta un recorrido integrado y trazable para transformar el interés del alumno en admisión y compra. La lista de interesados de la plataforma es de dueños potenciales del SaaS; no es un CRM de alumnos de cada estudio.

**Decisión necesaria.** Mantener admisión controlada o autorizar además venta abierta. Se recomienda mejorar primero la admisión controlada. Recoger una solicitud no concede una cuenta ni el derecho a reservar.

**Diseño funcional.** Crear solicitudes dentro de la base del negocio con nombre y contacto mínimos, actividad, sede, sesión o producto de interés, fuente, fecha, responsable y estado. Usar estados definidos: nueva, en seguimiento, aprobada, invitación enviada, convertida, descartada y cerrada. Registrar cambios sin almacenar comentarios innecesarios sobre salud u otros datos sensibles.

**Secuencia de implementación.**

1. Añadir formulario público breve con aviso vigente y protección contra abuso. No exigir consentimiento promocional para tramitar el acceso.
2. Confirmar al visitante que envió una solicitud, indicar cómo recibirá respuesta y ofrecer contacto alternativo cuando exista.
3. Crear bandeja de solicitudes con filtros, buscador, responsable e historial. Reutilizar componentes de listados, sin reutilizar la tabla central de interesados SaaS.
4. Aprobar o descartar con permiso específico. Al aprobar, resolver coincidencias de personas de forma segura y emitir la invitación existente.
5. Mantener el plan o clase de interés en el enlace de continuación, con caducidad y revalidación. Una clase solicitada no ocupa lugar indefinidamente.
6. Cerrar automáticamente como convertida al completar el evento definido, sin borrar su historia de origen.

**Aceptación.** Se completa el caso visitante → solicitud → aprobación → invitación → compra → reserva. Un envío repetido no genera cinco contactos abiertos idénticos. No se puede consultar la solicitud de otro negocio. Una cuenta existente no se enlaza por una coincidencia de correo no verificada.

Prioridad P1. Dependencias M03, M04 y C08. En venta abierta, además se requiere una decisión explícita que modifique la política vigente de acceso.

### B02 Unificar el cálculo de lugares públicos y reservables

**Brecha comprobada en código.** El escaparate resta reservas confirmadas para estimar lugares libres. El motor operativo también considera estados que pueden retener cupo, como ofertas o pagos pendientes conforme a sus reglas. Ambos pueden presentar resultados diferentes.

**Riesgo.** El sitio puede invitar a elegir un lugar que el motor ya considera ocupado. Esto perjudica la conversión y aumenta consultas a recepción.

**Secuencia de implementación.**

1. Extraer o reutilizar una regla de disponibilidad común que contemple estados, expiraciones, capacidad y cancelación de sesión.
2. Usarla en escaparate, calendario público, calendario del miembro y procesos operativos. No copiar un nuevo cálculo en cada vista.
3. Permitir consulta agregada sin producir una consulta por cada tarjeta del mes.
4. Evitar que la caché pública prolongue una retención caducada o muestre disponibilidad garantizada. Indicar que se confirmará al reservar cuando corresponda.
5. Mantener bloqueo y revalidación final en el motor. La lectura pública no debe reservar por sí misma.

**Aceptación.** Para la misma sesión y instante, todos los canales aplican la misma definición. Probar confirmada, ofrecida, pendiente de pago, cancelada y retención vencida. Dos personas que intentan el último lugar no obtienen dos confirmaciones. Una capacidad ilimitada o no definida tiene una presentación explícita.

Prioridad P0. Debe cerrarse antes de M02 y del primer recorrido público de compra y reserva.

### B03 Completar la información comercial pública de los planes

**Brecha.** La página pública no muestra toda la información necesaria para comparar, aunque parte ya viene en la API. Además, debe verificarse y completar la visibilidad de planes privados y las ofertas para clientes nuevos antes de ofrecerlas como reglas automáticas.

**Método.** Crear una respuesta comercial consistente para cada producto, derivada de sus reglas. Debe incluir tipo, precio y moneda, vigencia legible, fecha propuesta de inicio y fin cuando proceda, actividades y sedes permitidas, renovación, restricciones de compra y cargos iniciales aplicables. Un valor ausente no debe convertirse en una afirmación como “sin restricciones”.

La oferta de primera clase necesita reglas concretas: quién puede comprarla, cuántas veces, si requiere no tener plan y cuándo se considera utilizada. Fitune documenta restricciones de primera compra; en AgendaUno deben implementarse y probarse antes de anunciar equivalencia.

**Aceptación.** Una oferta de una sola compra no se puede repetir con otro enlace o dispositivo usando la misma identidad admitida. Un plan archivado no se vende desde una sesión antigua. La cobertura mostrada corresponde al derecho concedido. La información del primer pago se conserva en la orden para auditoría aunque luego cambie el catálogo.

Prioridad P1. Depende de definición comercial y M03. No implica alterar precios SaaS ni convertir todos los paquetes en suscripciones.

### B04 Modelar contratos y cuotas cuando el estudio los necesita

**Brecha.** El caso Grecon distingue paquete mensual, membresía con permanencia, inscripción y anualidad. Hay paquetes hasta fin de mes y beneficios ilimitados; no están completos el compromiso contractual mínimo, la baja anticipada con penalización ni los cargos automáticos de inscripción y aniversario. Registrar un cargo manual no resuelve esas reglas.

**Condición de alcance.** Es P1 si se promete operar exactamente ese modelo desde el lanzamiento. Puede quedar fuera del primer piloto de estudios sin permanencia y sin cuotas automáticas, con límites comerciales explícitos.

**Diseño que se debe conservar.** Separar cuatro conceptos: periodo de beneficios, frecuencia de cobro, vigencia del acuerdo y permanencia mínima. También separar cancelar una reserva de terminar una membresía.

**Secuencia de implementación.**

1. Definir acuerdo con fechas de inicio y permanencia, precio pactado, periodicidad, versión de condiciones aceptadas y evidencia de aceptación.
2. Incorporar flujo de baja con simulación de fecha efectiva, mensualidades pendientes, adeudos y penalización configurada. Definir tratamiento del mes parcial, cambios de precio, pausas y exenciones.
3. Conservar la base de cálculo usada y el actor que aprueba. No recalcular un contrato antiguo con el precio actual del catálogo sin una regla pactada.
4. Generar inscripción y anualidad como conceptos separados, con vencimiento, exención autorizada y referencia única. Definir primera alta, reingreso y aniversario del 29 de febrero.
5. Impedir duplicados por reintentos. Integrar caja y pagos en línea sin conceder clases adicionales por una cuota administrativa.
6. Definir para Open Training una política de visibilidad, además de la cobertura ya existente. Una clase exclusiva puede ocultarse a quien no tiene derecho; una clase normal sin saldo puede mostrarse para vender un paquete.

**Aceptación.** Probar compra a mitad de mes, cambio de mes, renovación, mora, pausa, cuota ya pagada, aniversario y baja anticipada. El cliente ve el cálculo antes de aceptar. La legalidad y redacción contractual requieren revisión profesional; esta fórmula funcional no autoriza cargos ni valida el contrato.

Dependencias M05, M07 y permisos de acuerdos. No imponer permanencia de un año ni acceso ilimitado a todos los negocios por utilizar el término “membresía”.

### B05 Completar el recorrido de familias y tutores si se ofrecen academias infantiles

**Brecha.** La decisión actual excluye familias y tutores de V1. Separar comprador y participante en órdenes no equivale a permitir que un adulto gestione varios menores, sus autorizaciones y reservas.

**Riesgo comercial.** Natación y academias infantiles pueden necesitar que un adulto pague y reserve para dos hijos. No conviene captar esos negocios prometiendo una operación familiar completa que todavía no existe.

**Método.**

1. Definir responsable adulto, participantes y relación autorizada dentro del negocio. No crear una identidad familiar compartida entre negocios.
2. Diseñar selector de participante antes de comprar y reservar, con saldo y cobertura independientes.
3. Definir quién consulta expedientes, recibe avisos, cancela y autoriza cambios. No mostrar datos de todos los menores solo por compartir un teléfono.
4. Modelar consentimiento y límites de datos de menores con revisión legal y de privacidad.
5. Reutilizar comprador y beneficiario existentes, completando relación, permisos y presentación en web y móvil.

**Aceptación.** El adulto puede pagar y reservar para dos participantes sin mezclar créditos. Una autorización retirada impide nuevos accesos. Las notificaciones identifican al participante correcto. Las exportaciones y solicitudes de datos respetan a cada persona.

Prioridad P1 condicionada al segmento infantil; P2 si el piloto es de adultos. No bloquear por esta brecha a una barbería o estudio que no atiende menores con gestión familiar.

### B06 Conectar la selección de un lugar específico de principio a fin

**Brecha.** El motor acepta y valida un lugar específico. El recorrido del miembro en API y pantalla no ofrece todavía la selección completa ni remite ese dato en la reserva. No debe anunciarse “elige tu reformer o estación” solo porque el motor lo soporte internamente.

**Método.**

1. Definir si la clase usa puestos numerados, mapa opcional o asignación automática. No obligar a dibujar un plano a todos los estudios.
2. Exponer lugares disponibles sin datos de asistentes. Diseñar botones numerados accesibles, con mapa como ayuda visual opcional.
3. Añadir selección en web y móvil y enviar el dato a la API del miembro. Mantener validación y bloqueo en servidor.
4. Definir qué ocurre si el lugar se ocupa antes de confirmar: aviso y alternativas, nunca cambio silencioso a otro puesto.
5. Mantener el lugar en detalle de reserva, recepción, transferencia y cancelación según las reglas.

**Aceptación.** Dos usuarios no pueden confirmar la misma estación. Funciona con teclado y sin distinguir solo por colores. No se puede elegir un número fuera del catálogo ni una estación de otra sede. Las clases sin lugares mantienen el recorrido simple actual.

Prioridad P1 si se captan estudios que lo requieren; P2 en otros segmentos. Dependencias B02 y pruebas de concurrencia.

### B07 Habilitar dominios propios de forma segura

**Brecha.** Está diseñado el soporte, pero los hosts ajenos a los dominios de producto no están habilitados como dominios de un negocio.

**Método.** Verificar propiedad por DNS, resolver el negocio desde la base central, emitir certificado, probar sesión y regreso de pagos y mantener canonical. La configuración debe tener estados pendiente, verificado, activo y con error; mostrar instrucciones de recuperación. Revalidar los registros y gestionar cambio o baja del dominio sin dejarlo apuntando a otro negocio.

**Aceptación.** Nadie registra el dominio de otro cliente sin demostrar propiedad. Solo se permiten destinos de regreso verificados; no se abren redirecciones arbitrarias. Login, invitación, pagos y navegación pública funcionan en el dominio elegido. Si falla DNS, el subdominio del producto sigue siendo una alternativa definida.

Prioridad P2, salvo que sea condición de contratación. Dependencias infraestructura, certificados y seguridad. No ofrecerlo como incluido y activo mientras solo exista su diseño.

### B08 Ampliar automatizaciones y medición sin confundir tareas con campañas

**Brecha.** Crear una tarea a partir de un evento no permite todavía dirigir un recorrido comercial completo con envíos, espera, exclusiones y resultados. La analítica web existente tampoco equivale a un embudo persistente por estudio.

**Método.** Empezar con eventos de negocio confiables y seguimiento humano. Añadir después un registro del recorrido por negocio, reglas de deduplicación y canal preparado. Un envío debe conservar propósito, destinatario autorizado, plantilla, resultado y condición de baja. Una campaña debe poder pausarse sin afectar avisos operativos.

**Aceptación.** Una compra repetida en la telemetría no se cuenta como dos ventas. El cliente que renovó sale del seguimiento de vencimiento pendiente. El dueño puede conocer solicitudes y conversiones de su negocio, no de otros. La falta de proveedor de mensajería no rompe la reserva ni el pago.

Prioridad P1 para medición mínima y tareas; P2 para automatización multicanal. Dependencias B01, M09 y C08.

## 3 Áreas de oportunidad para conectar mejor la captación

### Dos recorridos comerciales que deben medirse por separado

La landing de AgendaUno o TurnoUno capta al dueño que paga la plataforma. El sitio público de un estudio o barbería capta a la persona que paga clases o servicios. Mezclar ambos recorridos produce mensajes confusos y métricas que no ayudan a decidir.

| Recorrido | Etapas | Resultado comercial |
| --- | --- | --- |
| Plataforma hacia negocio | Landing → prueba → configuración → publicación → primera operación → suscripción SaaS | Negocio que adopta y paga la plataforma |
| Negocio hacia cliente | Sitio público → oferta adecuada → solicitud o compra → reserva → asistencia → renovación o regreso | Cliente atendido por el negocio |

En clases, la admisión controlada añade aprobación e invitación antes de la compra del nuevo miembro. En citas, el recorrido puede ser servicio → horario → datos → confirmación sin cuenta. No forzar una misma secuencia a ambos modelos.

### C01 Hacer que cada landing venda una especialización clara

**Oportunidad.** Separar las marcas ya permite hablar con precisión. AgendaUno debe mostrar cómo se opera una clase con cupo, planes y alumnos. TurnoUno debe demostrar cómo se agenda y atiende un servicio por profesional.

**Acciones.**

1. Usar un titular de beneficio acompañado de una explicación concreta del producto. Evitar aclaraciones defensivas sobre separaciones que el cliente da por hechas.
2. Mantener un CTA principal de prueba y un acceso secundario discreto para clientes existentes. El directorio no debe competir por atención con la contratación.
3. Mostrar una secuencia visual real: calendario → reserva → asistencia y cobro, específica de la marca. Un carrusel de giros acompaña esa prueba, pero no la reemplaza.
4. Presentar planes SaaS con unidad de cobro, periodo, impuestos y alcance. En clases, explicar alumno activo conforme a la definición comercial; en citas, explicar profesionales y límites del plan.
5. Añadir preguntas de objeción: migración, tiempo de preparación, pagos, soporte y cómo entran los clientes. Utilizar respuestas que coincidan con el producto disponible.
6. Mostrar testimonios solo con autorización y resultados verificables. Identificar las demos como demos; no usarlas como clientes reales.

**Aceptación.** Un dueño entiende si la plataforma corresponde a su operación y qué ocurrirá al iniciar la prueba. La prueba anunciada de 30 días coincide con el alta y la facturación. No se promete publicación en tiendas, dominio propio o canal automático pendiente. Medir clic en prueba y registro completado por producto, sin atribuir éxito solo a visitas.

Prioridad P1. Responsable sugerido: marketing y producto, con diseño web.

### C02 Llevar al dueño de la prueba a la primera operación real

**Oportunidad.** El valor no se demuestra al terminar el registro. Se demuestra cuando el negocio publica una oferta y puede atender o reservar de verdad.

**Acciones.**

1. Crear una lista de preparación por modalidad. Clases: sede, actividades, instructores cuando correspondan, sesiones, plan y reglas. Citas: sede, servicio, profesional, disponibilidad y reglas.
2. En negocios independientes, proponer al dueño como profesional cuando lo autorice, sin crear equipo ficticio ni duplicar identidades.
3. Ofrecer modo de demostración claramente separado de datos reales. Evitar que reservas de prueba entren en reportes o cobro de alumnos activos.
4. Guiar una operación de comprobación y luego publicar. Preparar un enlace y QR para compartir con clientes.
5. Mostrar estado de avance útil, no un porcentaje arbitrario. Cada pendiente abre el ajuste exacto y explica para qué sirve.
6. Avisar del fin de la prueba y del primer cobro con anticipación según la política aprobada. No iniciar cargos domiciliados por tener una tarjeta de prueba registrada.

**Aceptación.** Un dueño de estudio puede publicar sesiones y planes; un barbero solo puede publicar servicios y horas sin configurar membresías. La primera operación y su resultado quedan registrados. Medir tiempo de registro a publicación y a primera operación real, excluyendo datos de demo.

Prioridad P1. Responsable sugerido: producto y experiencia de incorporación, con web y API.

### C03 Orientar al visitante hacia su primera clase

**Oportunidad.** Una agenda llena de nombres y niveles no explica qué elegir a quien nunca ha practicado. Fluo Movimiento aporta información de disciplinas y preparación; esa claridad es más útil que mostrar únicamente fotografías.

**Acciones.** Incorporar una ruta “Es mi primera vez” configurable. Puede sugerir actividades aptas para principiantes, indicar requisitos, duración y qué llevar, y mostrar horarios correspondientes. Usar información que el estudio valide, no inferir nivel por el nombre de la clase. Asociar una clase de prueba solo si existe un producto elegible con reglas de B03.

En TurnoUno, la equivalente es ayudar a elegir un servicio por descripción y duración, sin diagnosticar ni recomendar tratamientos sanitarios. Debe ofrecer contacto cuando el negocio necesite valoración previa.

**Aceptación.** El visitante puede elegir una oferta de entrada y comprender su precio o el paso para solicitarla. Un principiante no termina en una clase avanzada por defecto. Si no hay horario disponible, se permite pedir información sin simular una reserva.

Prioridad P1. Dependencias M01, M02 y M03. Responsable sugerido: diseño, producto y dueño del negocio para el contenido.

### C04 Reducir la pérdida entre interés y primera compra

**Oportunidad.** Cuando el recorrido termina en WhatsApp, la plataforma deja de saber si la persona recibió respuesta, se inscribió o abandonó. B01 incorpora el registro; aquí se define cómo operarlo.

**Acciones.**

1. Capturar el contexto de interés con pocos campos. Dejar preguntas adicionales para después de la admisión si son necesarias.
2. Mostrar a recepción solicitudes nuevas con siguiente acción y responsable. Un recordatorio interno evita que dependan de revisar conversaciones personales.
3. Proponer un tiempo de respuesta que el negocio pueda cumplir. No publicar “respuesta inmediata” si no tiene ese servicio.
4. Permitir continuar después de aprobación sin repetir la búsqueda de actividad, sede o plan.
5. Registrar motivos de cierre limitados y útiles: sin horario compatible, precio, sin respuesta, no cumple requisitos o eligió otra opción. Dejar “otro” con comentario breve opcional.

**Aceptación.** La solicitud llega al negocio correcto y recibe seguimiento. Se conocen solicitudes sin atender, invitaciones pendientes y primeras compras. La tasa de conversión usa una ventana de tiempo definida y no cuenta contactos duplicados como prospectos independientes.

Prioridad P1. Dependencias B01 y C08. Responsable sugerido: operaciones del negocio y producto.

### C05 Acompañar compra reserva y primera asistencia

**Oportunidad.** Pagar un plan y no reservar, o reservar y no acudir, puede impedir que el cliente adopte el estudio. La plataforma debe facilitar los pasos siguientes sin abusar de notificaciones.

**Acciones.** Después de comprar, mostrar horarios cubiertos por el plan y sede. Después de reservar, confirmar fecha, ubicación, preparación y política de cancelación. Ofrecer añadir al calendario. Si compra sin reservar, crear un seguimiento interno tras un plazo configurable, no enviar promociones repetidas. Si no asiste, separar la consecuencia del consumo y la atención comercial.

Con clases llenas, ofrecer otras sesiones o lista de espera real. No añadir a espera sin confirmación del cliente. En citas, mostrar el profesional asignado o explicar cuándo se asignará, sin prometer una persona específica si eligió cualquier disponible.

**Aceptación.** Una compra tiene una acción posterior pertinente. La confirmación coincide con la reserva, no con datos viejos del recorrido. Un recordatorio no lleva a una clase cancelada. Medir compra → primera reserva y reserva → primera asistencia, sin mezclar clientes recurrentes.

Prioridad P1. Dependencias M02, M05 y M07. Responsable sugerido: producto, reservas y comunicaciones.

### C06 Facilitar renovación y recuperación del cliente

**Oportunidad.** Mostrar “plan vencido” no basta para renovar. El alumno necesita saber qué opción sirve para continuar y el negocio necesita distinguir falta de saldo de falta de interés.

**Acciones.**

1. Mostrar fecha de vencimiento y saldo; proponer renovación solo con un producto vendible y compatible.
2. Cuando falle un cargo recurrente, ofrecer la acción de regularización definida. No presentar la deuda como una promoción de compra nueva.
3. Crear seguimiento de primera asistencia, próximo vencimiento e inactividad con responsables y frecuencia controlada.
4. Excluir personas que ya renovaron, dieron baja o no desean promociones. Respetar pausas para no clasificarlas automáticamente como abandonadas.
5. En TurnoUno, sustituir renovación de paquete por siguiente visita cuando corresponda. No obligar a vender membresías para hacer retención.

**Aceptación.** No se ofrece renovar un producto archivado ni se duplica la renovación automática. El indicador abre clientes accionables, con motivo visible. Medir renovaciones entre derechos que vencieron y regresos de clientes inactivos en cohortes separadas.

Prioridad P1 para seguimiento; P2 para campañas automáticas. Dependencias M09 y B08.

### C07 Ofrecer migración guiada como ventaja comercial

**Oportunidad.** Cambiar de sistema exige más que importar nombres. Reducir el riesgo de migración puede ser una ventaja más tangible que añadir funciones poco utilizadas.

**Actual reutilizable.** Ya existen importaciones de personas, instructores y clases y layouts de sesiones por fecha y horarios recurrentes. Se deben aprovechar sus validaciones y patrones, no crear una segunda ruta incompatible.

**Acciones.** Definir preparación de archivos, catálogos, sedes, cuentas e invitaciones; revisar duplicados y presentar simulación antes de confirmar. Para saldos y vigencias, diseñar carga de apertura con evidencia y conciliación, sin inventar pagos históricos. Resolver reservas futuras y evitar duplicar sesiones al repetir el archivo. Acordar un momento de corte para no operar saldos divergentes en dos plataformas.

**Aceptación.** El dueño recibe resumen de aceptados, rechazados y conflictos. Puede corregir filas sin repetir toda la carga. Saldos, vigencias y próximas reservas coinciden con el corte acordado. No se envían invitaciones masivas sin confirmación. Medir tiempo y errores de incorporación, no prometer migración ilimitada inmediata.

Prioridad P1 para el piloto y P2 para ampliar formatos. Responsable sugerido: soporte de incorporación, API y pruebas.

### C08 Medir el recorrido sin exponer información personal

**Oportunidad.** Ya hay analítica web y atribución de campaña. Falta conectar los eventos del sitio con resultados del negocio y con la adopción del SaaS, manteniendo ambos embudos separados.

**Método.** Definir un catálogo de eventos con producto, negocio, identificador técnico, fecha, origen y resultado. Los eventos financieros y de reserva deben provenir del servidor. No enviar correo, teléfono, nombre, información clínica ni detalles de expedientes a herramientas de marketing. Revisar tecnologías y avisos antes de incorporar seguimiento externo.

| Indicador | Definición que se debe fijar | Uso |
| --- | --- | --- |
| Landing a registro SaaS | Registros verificados / visitas elegibles del producto en el periodo | Evaluar adquisición de negocios |
| Activación del negocio | Negocios con primera operación real / altas verificadas de la cohorte | Evaluar incorporación |
| Prueba a suscripción | Negocios con primer pago SaaS confirmado / pruebas que finalizaron en la cohorte | Evaluar adopción pagada |
| Sitio a solicitud | Solicitudes válidas / visitas elegibles del negocio | Evaluar interés en admisión controlada |
| Solicitud a primera compra | Nuevos clientes con pago confirmado / solicitudes válidas de la cohorte | Evaluar seguimiento y venta |
| Compra a primera asistencia | Compradores nuevos que asistieron / compradores nuevos de la cohorte | Evaluar incorporación del alumno |
| Renovación | Derechos renovados según ventana definida / derechos elegibles que vencieron | Evaluar continuidad |
| Tiempo de respuesta | Tiempo entre solicitud válida y primer contacto registrado | Detectar solicitudes sin atención |

**Reglas de medición.** Definir ventana, zona horaria y deduplicación; separar nuevas personas de recurrentes y pruebas de operación real. No atribuir una renovación a una campaña solo porque ambas ocurrieron cerca. Si un contacto pasa de WhatsApp a la plataforma, unir su recorrido solo con una referencia verificable, no con una suposición.

**Aceptación.** Un pago cuenta una vez aunque el proveedor reintente avisos. El dueño ve únicamente sus datos. Las métricas muestran cantidad y denominador, no porcentajes sin contexto. Se pueden reconciliar compras y reservas con los registros operativos.

Prioridad P1 para eventos y métricas mínimas; P2 para análisis avanzado. Dependencias B01, B08 y definición comercial de activación.

## 4 Orden propuesto para ejecutar el plan

### Bloque 0 Acordar alcance y decisiones

Confirmar los segmentos del primer piloto, la política de admisión, los canales de pago y las funciones que se venderán como disponibles. Clasificar cada entrega como operación común, ampliación de sitio o capacidad especializada, siguiendo la sección 0. Registrar las decisiones con fecha y responsable. No incluir toda la paridad competitiva como condición única de V1.

Planificar primero el lanzamiento de AgendaUno por la prioridad actual, con una lista de aceptación separada para TurnoUno. Un requisito exclusivo de clases no debe introducirse en la interfaz de citas ni bloquear su salida si no le aplica. Todo cambio en el núcleo compartido debe comprobar los recorridos esenciales de ambas modalidades para evitar regresiones. La prioridad de lanzamiento no modifica la importancia estratégica ni la identidad de los productos.

**Salida del bloque.** Lista de funciones de lanzamiento y lista explícita de exclusiones. B04, B05 y B06 se clasifican según los negocios seleccionados, no quedan ambiguas. La documentación comercial coincide con el producto.

### Bloque 1 Corregir consistencia y comprobar operación

Ejecutar B02, las validaciones de M07 y las pruebas de permisos y cobertura de M05 y M06. Verificar las correcciones recientes en un entorno con cabeceras, configuración y servicios representativos de producción. No reutilizar resultados de suites antiguas como evidencia del último commit.

**Salida del bloque.** Disponibilidad común, pagos y derechos reconciliados, roles y sedes correctos. Evidencia de pruebas del código elegido para lanzamiento. Correo, colas, tareas programadas, copia y restauración comprobados según el procedimiento operativo.

### Bloque 2 Completar admisión y venta inicial

Implementar B01 en modelo cerrado, B03 y M04. Añadir bandeja de solicitudes e invitación con contexto. Si se autoriza venta abierta, tratarla como variante adicional con sus pruebas; no sustituir silenciosamente la decisión vigente.

**Salida del bloque.** Un cliente nuevo puede completar el recorrido comercial autorizado. Hay responsables para solicitudes pendientes y no se pierden clase, sede o plan elegidos.

### Bloque 3 Mejorar sitio y experiencia pública

Ejecutar primero la parte común de M01, M02, M03, M12 y C03: página operativa, calendario, planes comprensibles y orientación mínima. Aprovechar el constructor existente sin obligar a usarlo. Validar escritorio, tableta y móvil, temas, contraste, accesibilidad y estados sin datos. Revisar primera compra, reserva y cancelación desde un navegador limpio. La galería, el contenido editorial ampliado y la personalización adicional se entregan como plus sin bloquear este recorrido.

**Salida del bloque.** Sitio publicable y comprensible, calendario real navegable y oferta comercial clara. No hay destinos de CTA incompletos. Contenido privado y borradores quedan fuera del índice.

### Bloque 4 Activar captación y seguimiento medibles

Ejecutar C01, C02, C04, C05, C06 y C08 con seguimiento humano de M09. Establecer un piloto con negocios de clases y citas y registrar bloqueos reales. Definir objetivos después de obtener una línea base, no inventar tasas de conversión como resultado esperado garantizado.

**Salida del bloque.** Se puede saber cuántos dueños publican y operan, cuántos interesados se convierten y dónde abandonan. Cada pendiente comercial tiene responsable y siguiente acción.

### Bloque 5 Incorporar diferenciadores seleccionados

Elegir B04, B05, B06, B07, B08, M10 y nuevos canales de M11 según necesidad comprobada. Puede adelantarse cualquiera que sea requisito del piloto; las dependencias de dinero, acceso y reservas siguen siendo obligatorias.

Incluir aquí la ampliación comercial del sitio de M01 y M12 para negocios que desean una página más completa. Diseñar su activación como capacidad del mismo negocio, no como otro sistema. Que una entrega sea opcional no significa que pueda publicarse incompleta si se cobra o se promete.

**Salida del bloque.** Cada diferenciador tiene recorrido completo y alcance comercial verificable. No se añaden módulos solo porque aparecen en una tabla de otro proveedor.

### Resumen de dependencias

| Entrega | Debe estar resuelto antes | Evidencia de cierre |
| --- | --- | --- |
| Calendario público completo | B02 y reglas de publicación | Mismo cupo y revalidación en todos los canales |
| Primera venta del nuevo alumno | B01, B03, política de admisión y pagos | Solicitud o admisión autorizada hasta reserva |
| Contrato y cuotas automáticas | Definiciones comerciales, consentimiento y M07 | Cálculo, orden, pago y baja trazables |
| Selección de estación | B02 y contrato API web móvil | Prueba concurrente y accesibilidad |
| Campañas automáticas | Eventos, preferencias, proveedor y B08 | Exclusión, deduplicación y resultado de envío |
| Dominio propio | Propiedad DNS, certificados y destinos permitidos | Acceso y regreso de pagos sin redirección insegura |
| Publicación de apps | Firma, cuentas, privacidad y flujos de M11 | Pruebas en dispositivos y aprobación del canal |

No se fijan fechas de entrega sin estimar tareas, capacidad y proveedores. El orden define dependencias; el equipo debe convertirlo en una planificación con responsables y tamaños de trabajo.

## 5 Pruebas para aceptar cada entrega

### Escenarios de negocio del piloto

1. Estudio de adultos con una sede, paquete de clases con vencimiento y membresía ilimitada sin permanencia obligatoria.
2. Estudio con dos sedes, producto local y multisucursal, actividad exclusiva y cancelación tardía.
3. Barbería independiente con un profesional, cita sin cuenta y cobro operativo.
4. Barbería con varias sedes y profesionales, servicio con distinta disponibilidad y cambio de sede.
5. Estudio con estaciones numeradas, familia o contrato mínimo solo si esas capacidades se incluyen en el lanzamiento.

Las variantes se configuran sobre el núcleo compartido. No crear ramas de código por nombre de negocio. No usar datos de demostración como evidencia de demanda o ventas reales.

### Matriz mínima de aceptación

| Caso | Comprobación | Resultado necesario |
| --- | --- | --- |
| T01 | Último lugar reservado simultáneamente | Solo una confirmación; alternativa clara para el otro cliente |
| T02 | Oferta o pago pendiente retienen cupo | Contador público coincide con regla operativa |
| T03 | Paquete local usado en otra sede | Rechazo en servidor y motivo comprensible |
| T04 | Vencimiento a fin de mes y cambio de zona | Fechas y derechos correctos en cada canal |
| T05 | Cancelación tardía y crédito vencido | Consecuencia real sin devolución o vigencia ficticia |
| T06 | Solicitud repetida e invitación caducada | Sin duplicación indebida; renovación segura del acceso |
| T07 | Selección conservada durante acceso | Se recupera y revalida clase o plan |
| T08 | Webhook duplicado o fuera de orden | Pago y derecho únicos; estado consistente |
| T09 | Dueño con varios roles | Solo permisos del rol activo; selección inicial protegida |
| T10 | Instructor con alcance de sede | Sin asistentes, cobros o acciones fuera de autorización |
| T11 | Cita invitada sin cuenta | Cita válida sin alta automática de usuario |
| T12 | Salida y entrada en otro negocio | Sin saldos, fotos privadas ni sesión reutilizada |
| T13 | Sitio en borrador y publicación | Solo versión pública; vista previa autorizada |
| T14 | Tema claro oscuro y marca propia | Texto, controles, errores y estados legibles |
| T15 | Móvil y tableta con teclado abierto | Sin acciones inaccesibles ni scroll horizontal global |
| T16 | Sin foto, sin clases o proveedor caído | Estado vacío o recuperación útil; sin éxito falso |
| T17 | Analítica y campañas | Sin datos sensibles; exclusión y deduplicación correctas |
| T18 | Contrato, cuota o estación opcionales | Pruebas específicas aprobadas antes de prometer la función |

### Evidencia que debe conservar el equipo

Para cada entrega, guardar versión de código, entorno, configuración relevante, pruebas ejecutadas y resultado. Añadir capturas o recorrido grabado de los casos visuales principales y referencias de órdenes o reservas de prueba. No guardar contraseñas, tarjetas, tokens ni datos de producción en el informe.

Las pruebas se dividen en automatizadas, manuales y externas. Una prueba con proveedor simulado valida una parte del sistema; la validación con pasarela o integración real se registra aparte. Un caso no ejecutado debe quedar pendiente, no como aprobado por inferencia.

### Regla para decidir la salida

No abrir un recorrido con inconsistencias conocidas en cupo, dinero, permisos o datos. Se puede lanzar un alcance limitado si sus recorridos están completos y las exclusiones se comunican sin vender funciones pendientes. Un calendario atractivo no compensa un pago sin reconciliar; tampoco se necesita replicar cursos, video o asistentes de IA para operar un estudio básico.

## 6 Decisiones de negocio que deben documentarse

Antes de decidir precios de complementos, aprobar la separación entre producto común y plus. No se propone sustituir aquí el modelo actual de alumnos activos o profesionales; se propone que la complejidad adicional sea optativa y que la promesa principal siga siendo fácil de entender.

Mantener documentada la diferencia entre importancia de producto y orden de lanzamiento: AgendaUno primero, ambos productos principales. Las decisiones de inversión y las fechas posteriores se revisan por producto; no se presupone que TurnoUno deba quedar subordinado permanentemente.

1. **Admisión.** Confirmar si se conserva únicamente el acceso autorizado o se ofrece venta abierta opcional. La propuesta no modifica por sí sola el registro cerrado.
2. **Segmentos del piloto.** Definir si se incluyen academias infantiles, estaciones y contratos con permanencia. Esto determina si B04, B05 y B06 son requisitos o ampliaciones.
3. **Primera compra.** Definir qué es clase de prueba, quién puede comprarla y cuándo consume su elegibilidad.
4. **Activación de beneficios.** Elegir inicio por pago, primera reserva o fecha acordada cuando el producto lo permita; mostrar la regla antes de pagar.
5. **Cargos y bajas.** Definir inscripción, anualidad, reingreso, exenciones y penalizaciones solo en negocios que las requieran, con validación contractual correspondiente.
6. **Alumno activo y prueba SaaS.** Mantener una definición verificable y excluir datos ficticios conforme a la política. Confirmar el inicio y fin de los 30 días y el paso a pago.
7. **Soporte y migración.** Establecer alcance y respuesta ofrecida. No anunciar disponibilidad permanente sin capacidad operativa.
8. **Canales y dominios.** Definir qué se publica primero y cuáles requieren autorización externa, cuentas o certificados.

Estas decisiones deben tener responsable y fecha. No deben quedar escondidas en texto de una tarjeta ni resolverse de manera diferente en web y app.

## 7 Ventajas que conviene desarrollar y comunicar

### Ventajas basadas en la operación existente

La combinación de cobertura por actividad y sede, derechos de clases, caja, asistencia, permisos e importación es una base valiosa. Se vuelve comercialmente convincente cuando el dueño puede demostrar un recorrido completo y entender qué ocurre ante una excepción.

La especialización de dos marcas sobre un motor común permite mostrar términos y tareas adecuados sin mantener dos motores de pagos o reservas. Esta arquitectura es una ventaja de mantenimiento; para el cliente, la ventaja visible debe ser una experiencia que corresponde a su negocio.

### Diferenciadores a priorizar

- Primera visita guiada con actividad, nivel, horario y preparación, en lugar de pedir al visitante que descifre el catálogo.
- Transparencia de cobertura y consecuencias antes de reservar, comprar o cancelar.
- Migración asistida con conciliación de saldos y reservas, no solo carga de contactos.
- Inicio del dueño con pendientes accionables y agenda como centro de trabajo.
- Sitio publicable con identidad propia y recorrido comercial conectado a operación.
- Seguimiento medible desde solicitud hasta asistencia y renovación, con permisos y preferencias respetados.

No presentar estas recomendaciones como capacidades disponibles hasta completar sus criterios. La seguridad del aislamiento es una obligación del producto, no un eslogan que deba ocupar la portada.

### Funciones que dejaría fuera del primer alcance salvo demanda validada

Biblioteca de video, cursos bajo demanda, tienda general, constructor libre de páginas, bandeja omnicanal completa, agentes de voz, marca blanca publicada y operación híbrida de clases y citas dentro del mismo negocio. Tampoco convertir TurnoUno en expediente clínico por atender a psicólogos o dentistas: agenda y cobro no equivalen a un sistema clínico completo.

## 8 Referencias para continuar la implementación

### Referencias internas

Repositorio consultado: `C:/Dev/turnouno`. Revisar la versión vigente antes de implementar porque estas rutas pueden evolucionar.

- `docs/MULTIPRODUCTO.md` y `docs/ENTREGA-MULTIPRODUCTO.md`: marcas, dominios, builds, estado de canales y restricciones.
- `docs/adr/0114-sitio-del-negocio.md`: constructor, secciones, borrador, publicación y vista previa. Principal para M01.
- `docs/adr/0093-registro-cerrado.md`: creación de cuentas e invitaciones. Principal para M04 y B01.
- `docs/adr/0115-reglas-del-cliente-configurables.md`: reglas que puede decidir cada negocio. Principal para M05 y configuraciones opcionales.
- `docs/adr/0059-sin-familias-ni-tutores.md`: alcance actual de familias. Principal para B05.
- `docs/adr/0092-importacion-masiva-de-clases.md`: layouts y patrones de importación. Principal para C07.
- `docs/DEMO_GRECON.md`: paquetes mensuales, Open Training y reglas contractuales pendientes. Principal para B04.
- `docs/VERIFICACION-V1.md` y `docs/MOBILE.md`: preparación operativa y canales. Principal para M07 y M11.
- `apps/api/app/Modules/Tenancy/Application/EscaparateTenant.php`: sesiones y productos públicos. Principal para M02, B02 y B03.
- `apps/api/app/Modules/Tenancy/Application/ReservasTenant.php` y `ResolverDerechoTenant.php`: disponibilidad, consumo y cobertura. Principal para M05, B02 y B06.
- `apps/api/app/Modules/Tenancy/Http/Controllers/MiTenantController.php`: contratos del recorrido del miembro y reserva. Principal para M04 y B06.
- `apps/api/app/Modules/Tenancy/Models/AcuerdoTenant.php` y `Application/CobroRecurrenteTenant.php`: acuerdo y recurrencia existentes. Principal para B04 y M07.
- `apps/web/src/views/EstudioPublicoView.vue`, `SitioWebView.vue`, `MisReservasView.vue` y `MisPagosView.vue`: presentación pública, editor, reservas y compra del miembro.
- `apps/web/src/lib/analytics.ts`: analítica web existente. Principal para C08; no sustituye resultados persistentes del negocio.

### Referencias competitivas públicas

Consulta de referencia realizada el 10 de octubre de 2026. Las funciones anunciadas y los recorridos observados se distinguen; no se certificó la administración interna de esos proveedores. Se omiten precios comparativos porque pueden cambiar y no son necesarios para ejecutar este plan.

- [Buq](https://gobuq.ai/) y [sitios web de Buq](https://gobuq.ai/web): referencia de sitio comercial, personalización y conexión con reservas. Relacionados con M01, M12 y C01.
- [Reserva de HYX](https://web.buq.mx/hyx/reservar) y [paquetes de HYX](https://web.buq.mx/hyx/paquetes): recorrido observado de calendario, selección y acceso. Relacionados con M02 y M04.
- [Funciones de Fitune](https://www.fitune.io/features): referencia general de oferta pública. Las funciones marcadas como futuras no se toman como disponibles.
- [Sitio de fitness y wellness de Fitune](https://www.fitune.io/features/free-fitness-wellness-website): referencia de sitio unido a venta y reserva. Relacionado con M01 y C07.
- [Creación de membresías en Fitune](https://help.fitune.io/en/articles/5557556-how-to-create-a-membership): referencia de condiciones y elegibilidad de compra. Relacionada con M03 y B03.
- [Instalación de la web app de Fitune](https://help.fitune.io/en/articles/8685332-how-to-install-the-web-app): distinción entre web instalada y app de tienda. Relacionada con M11.
- [Información de Fluo Movimiento](https://my.fitune.io/fluo-movimiento/info), [horarios](https://my.fitune.io/fluo-movimiento/activities) y [precios](https://my.fitune.io/fluo-movimiento/pricing): recorridos observados de orientación, selección y compra. Relacionados con M02, M03 y C03.

## 9 Plantilla para convertir un punto en una tarea

Usar el identificador de esta guía para mantener trazabilidad. Una mejora puede requerir varias tareas; todas deben cerrar el recorrido descrito y no solo su parte visual.

- Identificador y nombre del punto.
- Problema que resuelve y usuario afectado.
- Modalidad y capacidades a las que aplica.
- Alcance incluido y exclusiones.
- Clasificación como operación común, ampliación de sitio o capacidad especializada; indicar si necesita una decisión comercial antes de activarse.
- Decisiones aprobadas y dependencias.
- Cambios de datos, API, web y móvil necesarios.
- Permisos y alcance por sucursal.
- Estados normales, vacíos, de error y de recuperación.
- Pruebas de aceptación vinculadas a T01 a T18 y casos específicos.
- Evidencia, responsable y versión de entrega.

**Ejemplo de tarea.** B02 puede dividirse en regla común de disponibilidad, consulta pública agregada, presentación y pruebas concurrentes. No debe cerrarse cuando solo cambie el número de una tarjeta: se cierra cuando ese número, el calendario del alumno y la confirmación del servidor aplican la misma regla.

**Primer paso recomendado.** Aprobar el Bloque 0 y ejecutar el Bloque 1. Después completar la admisión controlada y la venta inicial, en paralelo con el diseño del sitio y calendario cuando las dependencias lo permitan. Esta secuencia mejora la captación sin aumentar antes el riesgo operativo.
