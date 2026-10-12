# ADR 0132 — Equipo y bitácora del superadmin, y la plataforma por producto

Estado: Aceptado (2026-10-11). Pedido del dueño: «revisa el súper administrador».

## Contexto

La revisión encontró el superadmin bien conectado: cada pantalla con su ruta y cada
ruta protegida. Faltaban dos cosas: saber quién hizo qué y separar por producto.

- **Sin rastro.** El ADR 0126 dio a cada operador su cuenta, pero lo que cada uno
  cambiaba no quedaba registrado. El motivo de una suspensión solo iba al log.
- **Operadores solo por comando.** Se daban de alta con `agendauno:admin-plataforma`;
  no había cómo verlos, desactivarlos ni cerrar sus sesiones.
- **Fallas:**
  - «Ver su página» abría `/estudio/{slug}` en el dominio en curso: con un negocio de
    TurnoUno terminaba en 404.
  - La columna de adeudo sumaba pesos con dólares y los mostraba como pesos.
  - Guardar vacía la llave de FacturAPI la borraba, y con ella la facturación de
    todos.
  - Cambiar Stripe de prueba a real quitaba las tarjetas de todos los negocios sin
    preguntar.
- **Exposición fuera de producción.** En un staging público el código de entrada
  viajaba en la respuesta y el token solo abría la API.
- **Producto.** La lista de negocios no decía de qué producto era cada uno. Las
  tarifas se nombraban por modalidad. Los textos decían «AgendaUno», y en turnouno.mx
  se leían «TurnoUno». El cambio de modalidad no avisaba que cambiaba el producto.

## Decisión

**Bitácora (`bitacora_plataforma`, migración del control plane 000201):**
- El middleware `plataforma.bitacora` anota toda petición del superadmin que no sea
  de lectura. Guarda:
  - el operador;
  - la acción, que es el nombre de la ruta;
  - el negocio, por su slug o por el de un cargo;
  - los datos enviados, sin secretos: tokens, contraseñas, llaves y códigos quedan
    como `••••`;
  - la IP y el resultado (código HTTP).
- Cada entrada de un operador también queda anotada.
- Si anotar falla, la respuesta sale igual y el error se reporta.
- El log lleva el correo del operador (`Log::withContext`).
- Pestaña «Bitácora» (`GET /plataforma/bitacora`): filtra por operador, negocio o
  acción y pagina de 50 en 50.
- La ficha del negocio muestra la última suspensión: quién, cuándo y por qué.

**Equipo:**
- Pestaña «Equipo»:
  - Operadores: `GET`/`POST /plataforma/administradores` y
    `PUT /plataforma/administradores/{id}` para el nombre, `activo` y la contraseña.
  - Sesiones: `GET /plataforma/sesiones` y `DELETE /plataforma/sesiones/{id}`. Las
    sesiones tienen ahora identificador público.
- Reglas:
  - siempre queda un operador activo;
  - nadie se desactiva a sí mismo;
  - desactivar a alguien o cambiarle la contraseña cierra sus sesiones, salvo la de
    quien cambia la suya;
  - la contraseña tiene al menos 12 caracteres.
- La limpieza diaria borra los códigos vencidos y las sesiones vencidas o cerradas
  hace más de 30 días. La bitácora se conserva.

**Fallas corregidas:**
- «Ver su página» usa la dirección canónica en el dominio del producto del negocio.
- `GET /plataforma/estudios` trae `adeudos[]` por moneda en lugar de
  `adeudo_minor`.
- FacturAPI:
  - «Guardar llave» solo funciona con una llave escrita;
  - «Quitar llave» es un botón aparte y pide confirmación.
- Stripe:
  - si las llaves cambian de modo, la API responde 409
    `GATEWAY_MODE_CHANGE_UNCONFIRMED` hasta recibir `confirmar_cambio_modo: true`;
  - la web lo confirma con el mismo mensaje.
- Al pasar un negocio de citas a clases se limpia su plan por niveles.
- La configuración ya no impide entrar: si falla, se avisa.
- Una sesión vencida (401) regresa a la entrada.

**Seguridad fuera de producción:**
- El código de entrada viaja en la respuesta, y el token solo abre la API, únicamente
  en `local` y `testing`; en staging ya no.
- La verificación de producción revisa además dos puntos:
  - que el token solo no abra el superadmin;
  - que haya al menos un operador activo.

**Por producto:**
- Negocios:
  - la lista tiene una columna de producto;
  - se filtra por producto, por estado y por adeudo.
- Las tarifas se titulan con su producto, que va como valor y no en el texto.
- El cambio de modalidad dice que el negocio pasa de un producto a otro, con su nueva
  dirección, sus correos y su app.
- Los textos del superadmin dejan de nombrar una marca: «la plataforma», «Negocios».

## Consecuencias

- Cada cambio del superadmin tiene autor, y la cuenta de cada operador se administra
  sin consola.
- Acciones que afectan a todos los negocios (FacturAPI, Stripe) ya no se hacen por
  accidente.
- Pendiente, si se pide:
  - resumen por producto (MRR, negocios por estado);
  - cobros y alertas filtrados por producto;
  - interesados con paginación completa;
  - timbres por negocio;
  - contactos de ventas y soporte por producto en la configuración.
