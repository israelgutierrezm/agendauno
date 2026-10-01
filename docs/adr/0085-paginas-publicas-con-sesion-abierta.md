# ADR 0085 — Páginas públicas con una sesión abierta

Estado: Aceptado (2026-10-01).

## Contexto

La web decidía su marco por la sesión: con sesión, todo se pintaba dentro del panel
(barra lateral, encabezado y el tema del usuario). Al revisar los recorridos del
documento de reformulación apareció el efecto:

- Un dueño con sesión que abría un enlace público, como el de agendar de otro negocio,
  lo veía embebido en su panel y con sus colores.
- Pasaba lo mismo con la página del negocio, sus sucursales, su página de enlaces, el
  directorio y el aviso de privacidad.
- «Ya soy cliente · Entrar» en la página de otro negocio llevaba al panel del negocio
  con sesión, no al acceso del negocio visitado.

## Decisión

- **El marco lo decide la ruta, no la sesión.**
  - El panel envuelve solo las pantallas privadas (`meta.requiereSesion`).
  - Las públicas usan el marco público aunque haya sesión: se ven como las ve
    cualquier visitante.
- **El tema del usuario se pausa fuera del panel.**
  - Sus variables CSS y su modo oscuro se aplican sobre `<html>`.
  - En una página pública se quitan y rige la apariencia pública.
  - Al volver al panel se aplican otra vez (`useAparienciaStore().pausar`).
- **El encabezado público ofrece volver al panel.** Con sesión dice «Ir a mi panel»
  en lugar de «Iniciar sesión».
- **Se puede entrar a otro negocio con una sesión abierta.**
  - El acceso de su propio negocio, o uno sin negocio, sigue llevando a su inicio.
  - El de otro negocio (por `?estudio=` o su subdominio) se muestra, con un aviso: la
    sesión es de un solo negocio y entrar al otro la cambia en este navegador.

- **Las palabras del negocio en sesión también se pausan.**
  - La terminología (ADR 0049) adapta todos los textos y los errores de la API.
  - En una página pública rigen los textos base: la página de otro negocio no habla
    con las palabras del negocio en sesión (`pausarTerminologia`).
- **El token solo viaja a su negocio.**
  - El cliente HTTP lo agrega únicamente a `/api/v1/app/{slug de la sesión}/…`.
  - Nunca va a las rutas de otro negocio ni a la plataforma.
  - Tampoco pisa una credencial que la petición ya traiga: antes reemplazaba la del
    superadmin si había sesión de un negocio.
  - El backend ya rechaza un token de otro negocio (cada token se valida en la base
    del negocio de la URL); esto evita mandarlo.
- **Agendar con una sesión del equipo del mismo negocio.**
  - Si alguien del equipo (no como cliente) agenda en la página pública, no se le
    ofrece «¿Ya tienes cuenta? Entra», que solo lo llevaba a su panel sin explicar.
  - En su lugar se le dice con qué sesión está y se enlaza la agenda del panel.
  - Si llega al acceso de su propio negocio con `volver`, regresa a esa página.

## Consecuencias

- Un dueño puede revisar su enlace de agendar o su página tal como la ven sus
  clientes.
- Las páginas públicas no usan datos de la sesión salvo en el mismo negocio. Agendar
  ya lo comprueba: usa la cuenta solo si la sesión es de ese negocio y de un cliente.
- Entrar a otro negocio no revoca el token anterior en el servidor. Solo se reemplaza
  en este navegador.
