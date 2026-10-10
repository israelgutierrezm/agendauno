# ADR 0108 — Plataforma multiproducto: AgendaUno (clases) y TurnoUno (citas)

Estado: Aceptado (2026-10-10). Reemplaza en parte el ADR 0056 («el producto se llama
AgendaUno»): AgendaUno sigue siendo el producto de clases y la plataforma; TurnoUno
vuelve como el producto de citas. Se apoya en el ADR 0104 (modalidad excluyente) y
mantiene el ADR 0093 (registro cerrado de clientes).

## Contexto

La plataforma atiende negocios de clases (estudios boutique, gimnasios, academias) y
de citas (barberías, salones, spas). Desde el ADR 0104 cada negocio es solo de una
modalidad y la frontera núcleo/clases/citas existe en el código. Comercialmente, una
sola marca con una landing que obliga a elegir «clases o citas» no posiciona bien a
ninguno de los dos mercados. El dueño decidió vender **dos productos**:

- **AgendaUno** (agendauno.mx): negocios de clases; se lanza primero.
- **TurnoUno** (turnouno.mx): negocios de citas; se prepara en paralelo y su
  contratación pública queda cerrada hasta autorizar su lanzamiento.

Sin duplicar backend, pagos, autenticación, superadmin ni el modelo de una base por
negocio.

## Decisión

### El producto sale de la modalidad

- `ProductoComercial` (`agendauno` | `turnouno`) se deriva de `estudios.modalidad`:
  clases → AgendaUno, citas → TurnoUno (`Estudio::producto()`). No se guarda aparte,
  así que no puede contradecir la modalidad, y cambiarla (solo el superadmin, antes de
  operar) cambia el producto.
- Se distinguen: **producto** (marca, dominio, landing, app), **modalidad** (cómo
  atiende: clases o citas), **giro** (`PerfilNegocio`: terminología), **capacidades**
  (lo que la modalidad habilita), **plan** (`FuncionesPlan`, lo contratado) y
  **permisos** (RBAC). Ninguno se deduce de otro fuera de esas reglas.
- Un negocio con los dos productos (clases y citas) **no** se habilita: requeriría
  reservas, cobro, créditos y permisos por producto dentro de un mismo negocio. Si se
  decide a futuro, el producto pasaría a ser un conjunto guardado por negocio; nada
  de lo de aquí lo impide.

### Dominios y resolución

- Cada producto tiene su dominio y la URL de su web (`config/agendauno.php`,
  `productos`; AgendaUno usa `APP_TENANT_DOMAIN` y `APP_SPA_URL` de siempre; TurnoUno,
  `TURNOUNO_DOMINIO` y `TURNOUNO_URL_WEB`).
- Las rutas de un negocio se montan por ruta (`/api/v1/app/{slug}`) y por el
  subdominio **de cada producto** (`{slug}.agendauno.mx`, `{slug}.turnouno.mx`).
- `ResolverEstudio` abre un negocio solo en el dominio de su producto: un negocio de
  citas responde 404 en agendauno.mx y en su subdominio, y viceversa. Fuera de los
  dominios de los productos (local, la IP del servidor) no se revisa; los avisos de
  las pasarelas llegan por cualquiera.
- CORS admite los dos dominios y sus subdominios. El regreso de un pago vuelve al
  sitio desde el que se pagó si es de un producto, o a la web del producto del
  negocio.
- El directorio de negocios lista solo los del producto (el pedido o el del dominio).
- Hay subdominios reservados que ningún negocio puede tomar (`www`, `app`, `api`,
  `panel`, `admin`, los nombres de los productos…).

### Marca en lo que se manda

- Los enlaces de correos y avisos (activar cuenta, restablecer contraseña, entrar,
  pagar una cita apartada, renovaciones, el calendario iCal) llevan a la web del
  producto del negocio (`MarcaProducto`).
- El pie de los correos dice «con AgendaUno» o «con TurnoUno», y cada producto puede
  tener su remitente (`AGENDAUNO_MAIL_FROM`, `TURNOUNO_MAIL_FROM`; sin él,
  `MAIL_FROM_ADDRESS`). Las respuestas automáticas del WhatsApp de la plataforma
  nombran el producto del negocio.
- `/yo` y `/marca` dicen el producto del negocio; la web y cada app se presentan con
  esa marca y la app de un producto no abre negocios del otro.

### Registro por producto y lista de interesados

- El registro de negocios recibe el producto desde el que se registra; el giro debe
  ser de ese producto.
- Cada producto abre o cierra su registro con un parámetro de plataforma
  (`registro.abierto_agendauno`, abierto; `registro.abierto_turnouno`, **cerrado**).
  Cerrado, el registro responde 422 y la landing ofrece dejar los datos.
- `interesados` (base central): quienes quieren usar un producto que aún no abre; una
  fila por correo y producto (`POST /api/v1/interesados`, con captcha y aceptación del
  aviso de privacidad). El superadmin los consulta en `GET /plataforma/interesados`.

### Lo que se mantiene

- Una API Laravel, una base central y una base por negocio; un superadmin; un motor de
  cobro SaaS (las tarifas ya son por modalidad: clases por alumnos activos, citas por
  plan, ADR 0107); un sistema de pagos, de usuarios y de autenticación.
- El registro de clientes sigue cerrado (ADR 0093): el sitio de un negocio no crea
  cuentas; compra y reserva en línea son para clientes invitados, más «Pedir acceso» y
  agendar citas sin cuenta. El dueño lo reafirmó el 2026-10-10.

## Implementación por fases

Cada fase va en su rama (`multiproducto/fase-N`) y entra a main por PR con CI verde.

1. **API multiproducto** (esta ADR): producto, dominios, resolución, enlaces y marca,
   registro por producto, interesados, directorio y slugs reservados.
2. **Web**: un solo código Vue con builds por producto (landing de AgendaUno, landing
   de prelanzamiento de TurnoUno con la lista de interesados) y la aplicación
   (escaparate, portal y panel) que se presenta con la marca del negocio.
3. **PWA por negocio** en los dos productos (manifest dinámico, service worker seguro).
4. **Apps oficiales**: AgendaUno y TurnoUno como dos apps (flavors) del mismo proyecto
   Flutter; white-label preparado solo para AgendaUno.
5. **Infraestructura y CI/CD**: Traefik existente, dos dominios con certificados
   comodín (DNS en Cloudflare), despliegues independientes por imagen.

Lo que cada fase deja hecho, pendiente de credenciales o preparado para después se
registra en `docs/MULTIPRODUCTO.md`.

## Consecuencias

- Los negocios de citas viven en `{slug}.turnouno.mx`: sus enlaces, su web y su app
  son de TurnoUno. Antes del lanzamiento, el superadmin puede seguir creando negocios
  de citas (demos, pilotos) abriendo el registro o con `agendauno:sembrar-demos`.
- Rutas por subdominio de productos nuevos se nombran `api.v1.sub-{producto}.*`
  (AgendaUno conserva `api.v1.sub.*`).
- Los documentos legales siguen siendo de la plataforma (uno por tipo y versión); si
  TurnoUno necesita los suyos, los documentos tendrán producto.
