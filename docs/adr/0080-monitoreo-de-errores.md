# ADR 0080 — Monitoreo de errores propio

Estado: Aceptado (2026-09-30). Amplía las alertas del ADR 0051.

## Contexto

Los errores de la API llegaban como alertas agrupadas (ADR 0051): tipo, archivo y
línea, y un contador. Eso no bastaba para operar el servicio:

- no había traza ni contexto (qué ruta, qué negocio, qué rol, qué versión);
- no se podían marcar resueltos o ignorados, ni saber si uno arreglado volvía;
- los errores de la web y de la app no llegaban a ningún lado;
- los que nacían en una librería (p. ej. una consulta) se agrupaban todos en el
  mismo archivo de la librería.

Se eligió un monitoreo propio en lugar de Sentry u otro servicio. No agrega
dependencias ni costo, y los datos de los negocios no salen de nuestros servidores.

## Decisión

- **`errores_plataforma` (plataforma):** un renglón por error, agrupado por huella:
  origen (`api`, `web` o `app`), tipo y lugar. Guarda:
  - cuántas veces pasó y la primera y la última vez;
  - la versión en que se vio primero y la última (`APP_VERSION`; en la web,
    `VITE_APP_VERSION`; en la app, `--dart-define=APP_VERSION`);
  - la traza y el contexto de la última vez;
  - su estado: abierto, resuelto o ignorado.
- **API:** todo lo que Laravel reporta pasa por `ErroresPlataforma::desdeExcepcion`.
  - Laravel no reporta validación, permisos ni «no encontrado».
  - El **lugar** es el primer archivo del código propio en la traza, no el de la
    librería.
  - La **traza** va sin los argumentos de cada llamada.
  - El **contexto** es el método y la plantilla de la ruta (sin valores), el nombre
    de ruta, la correlación, el ULID del usuario y su rol activo, el negocio, y en
    consola el comando.
  - Una **consulta** que falla se guarda con el SQL sin valores y el mensaje del
    motor con lo que va entre comillas tachado.
  - Sigue llegando como alerta por correo, salvo que esté ignorado.
- **Tachado** (`Tachador`) de mensajes, trazas y rutas de la web y la app: correos,
  llaves de pasarelas, tokens y cadenas largas, y diez dígitos o más (tarjetas,
  teléfonos).
- **Web y app:** `POST /api/v1/errores`, sin sesión porque también fallan la página
  comercial y el registro.
  - Tiene tope de 30 por minuto por IP y un tope diario de errores nuevos, el
    parámetro `errores.nuevos_clientes_por_dia` (200). Pasado el tope, los ya
    conocidos se siguen contando y el superadmin recibe una alerta.
  - La **web** (`src/lib/errores.ts`) reporta los errores de Vue, los de JavaScript
    y las promesas sin manejar. No reporta errores de la API, de otros orígenes
    (extensiones) ni ruido conocido del navegador. Manda cada error una vez, 10 como
    mucho por página, con la ruta, la versión y el negocio de la sesión.
  - La **app** (`ReporteErrores`) reporta los errores de Flutter y los asíncronos sin
    manejar, excepto los de red. Manda cada error una vez, 10 como mucho por sesión.
- **Resuelto y regresión:** al resolverlo se guarda la última versión en que se vio.
  - Si vuelve en esa misma versión, el arreglo aún no se publica: se cuenta y sigue
    resuelto.
  - Si vuelve en otra versión, se reabre, suma una regresión y avisa («Error que
    volvió»).
  - Uno **ignorado** se sigue contando, pero ya no genera alertas.
- **Superadmin:** pestaña «Errores».
  - Muestra abiertos, resueltos e ignorados con su conteo, y filtra por origen.
  - Cada error dice cuántas veces pasó, dónde, cuándo por última vez, la versión, el
    negocio y si volvió.
  - El detalle trae la traza y el contexto, y los botones «Resuelto», «Ignorar» y
    «Reabrir».
  - API: `GET /plataforma/errores`, `GET /plataforma/errores/{error}` y
    `PUT /plataforma/errores/{error}`.
- **Limpieza:** `agendauno:limpiar-registros` borra los errores que no han pasado en
  `limpieza.dias_errores` días (90), en cualquier estado (ADR 0079).

## Consecuencias

- Un error se investiga desde el superadmin, con traza y contexto, sin entrar al
  servidor a leer logs. El log normal sigue igual.
- La traza de la web apunta a los archivos compilados (sin source maps). Sirve para
  agrupar y ubicar la pantalla; para la línea exacta hay que compilar la misma
  versión.
- Cualquiera puede mandar errores falsos a la entrada pública. Los topes lo acotan y
  un error falso se ignora en un clic.
