# ADR 0084 — Navegación del dueño por áreas y registro de acceso

Estado: Aceptado (2026-10-01). Sigue el documento «Reformulación de la navegación del
dueño en AgendaUno» (v1.0, 30 de septiembre de 2026).

## Contexto

El menú del dueño era un árbol de tres niveles con 38 pantallas: había que recorrer
módulos para llegar a las tareas del día. Además, el menú era la fuente de las reglas
de acceso del router: una pantalla que no estaba en él y no tenía una regla aparte
quedaba abierta por omisión, así que moverla de lugar podía quitarle su protección.

## Decisión

### Acceso independiente del menú

- `lib/acceso.ts` tiene la regla de cada ruta privada: los permisos (todos), la
  modalidad, la función del perfil, la faceta (portal del alumno o de quien imparte)
  o solo la sesión (perfil, elegir rol).
  - La portada de Configuración se abre si se puede abrir alguna de sus opciones.
    Es la única regla de tipo «cualquiera», explícita y con prueba.
- Una ruta privada sin regla se niega. Una prueba exige que toda ruta con
  `requiereSesion` tenga su regla y que toda regla sea una ruta.
- El router valida también el inicio de cada quien, sin excepción. Si ni el inicio
  se permite, va al perfil (siempre permitido con sesión), sin ciclos.
  - Recepción es el inicio solo con `reservas.gestionar`, no por el nombre del rol.
- Suspendido por renta, solo se entra a la renta (ADR 0073).
- El servidor sigue validando cada lectura y cada cambio.

### Presentación (`lib/menu.ts`)

- **Lateral:** tres grupos con títulos discretos, sin desplegar, y un enlace por área.
  - **Operación diaria:** Inicio, Agenda, Alumnos o Clientes (con la terminología
    del negocio), Cobros y Equipo.
  - **Gestión:** Ventas, Marketing y Reportes.
  - **Configuración:** Configuración del negocio y Mi suscripción.
  - Cada área abre su primera vista permitida. Un área o un grupo sin vistas
    permitidas no aparece. Nombres y orden son los mismos para todos los roles.
- **Pestañas del área** (`PestanasArea`): las vistas permitidas arriba de la
  pantalla, como enlaces con su dirección. Con una sola vista no hay pestañas; en
  el teléfono son una lista con la vista activa.
  - Inicio: Resumen y Tareas.
  - Agenda: Calendario, Recepción, Grupos y cursos, Lugares disponibles.
  - Clientes: Directorio, Renovaciones y Expedientes.
  - Cobros: Por cobrar, Movimientos, Conciliación, Caja y Facturas.
  - Equipo: Personas, Disponibilidad y Nómina.
  - Ventas: Membresías y paquetes, Mostrador e Inventario.
  - Marketing: Comunicación, Promociones, Lealtad y Reseñas.
  - Mi suscripción: Suscripción y pagos, y Uso facturable.
- **Configuración del negocio:** el tercer nivel.
  - Una portada (`/ajustes`) con las categorías: Negocio, Servicios y catálogos,
    Agenda y reservas, Pagos e integraciones, Accesos y permisos, y Documentos y
    privacidad. Cada una con una frase de qué configura y sus opciones.
  - Al abrir una opción, `LayoutConfiguracion` muestra la navegación secundaria:
    «Buscar ajuste» (por nombre, categoría o sinónimos), una categoría abierta a la
    vez y la ruta de ubicación.
  - Bajo 1280 px, la navegación pasa al botón «Secciones de configuración», que
    abre un panel dentro de la página, sin encimarse con el menú principal.
- **Una sola ubicación activa** (`ubicacion()`): por nombre de ruta, su `?vista=` y
  su ancla. Las fichas y las importaciones dejan activa su área (Clientes, Equipo).

### Pantallas que se reparten

- **Cobranza:** por cobrar, movimientos, conciliación y caja son vistas
  (`/cobranza?vista=…`). Cada una carga solo lo suyo al abrirse.
- **Documentos:** la vista va en `?vista=`.
  - Los documentos de las personas son Clientes › Expedientes.
  - Los requisitos y los consentimientos son Configuración › Documentos y privacidad.
  - Consentimientos pide además `documentos.gestionar`, como antes su pestaña.
- **Configuración** y **Reglas de agenda** siguen siendo una pantalla, con anclas
  para cada opción: datos e imagen, página pública, terminología; políticas, días
  de cierre y programación recurrente (esta, solo en clases).
- **Planes y paquetes** pasan a Configuración › Servicios y catálogos. Ventas los
  enlaza («Administrar planes y paquetes») a quien los puede abrir.
- **Formularios:** el diseño es Configuración › Documentos y privacidad y la
  revisión de respuestas es Clientes › Respuestas de formularios
  (`/formularios?vista=respuestas`). Cada vista enlaza a la otra, y solo la de
  respuestas las consulta.

### Enlaces de paso

Lo que se configura en otra área se enlaza desde donde se usa, a quien puede
abrirlo:

- Agenda lleva a «Disponibilidad del equipo» (solo en citas) y a «Reglas de reserva».
- Equipo y la ficha del instructor llevan a «Administrar acceso», en Configuración ›
  Accesos y permisos.
- **«Volver» de las fichas** (`lib/regreso.ts`):
  - Regresa a la pantalla del menú de donde se llegó: «Volver a recepción», «Volver
    a respuestas de formularios».
  - Así no se pierde la bandeja de trabajo.
  - Entrando directo o desde la lista, vuelve a la lista con el término del negocio.
- **Vender desde la ficha** abre directo la venta, sin el resumen intermedio que
  repetía la ficha.

### Cambios sin guardar

`lib/cambiosPendientes.ts` avisa antes de perder lo editado:

- Cada formulario declara cuándo tiene cambios. Casi siempre lo hace comparando con
  una foto de lo último cargado o guardado.
- Con cambios, preguntan:
  - salir a otra pantalla;
  - cambiar de rol;
  - cerrar o recargar la pestaña (el aviso del propio navegador).
- Moverse entre anclas de la misma pantalla no pregunta.
- Lo usan:
  - datos fiscales;
  - página pública;
  - terminología;
  - reglas de agenda (política general y la excepción abierta);
  - pasarelas (llaves escritas, activa o modo).

## Consecuencias

- Las 38 pantallas del panel conservan su ruta y su permiso; ninguna se quitó. Una
  prueba lo comprueba.
- Quitar una pantalla del lateral ya no le quita su protección.
- Un rol con solo facturas, tareas, integraciones o pasarelas llega a su pantalla sin
  los permisos de las demás del área.
- La separación de permisos financieros (operación frente a suscripción) y una
  búsqueda global de personas quedan fuera: son cambios de permisos o funciones
  nuevas, no de navegación.
