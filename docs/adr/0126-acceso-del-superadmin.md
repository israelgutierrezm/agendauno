# ADR 0126 — Acceso del superadmin: cuenta, token y código por correo

Estado: Aceptado (2026-10-11). Pedido del dueño: el superadmin vuelve a `/plataforma`,
con usuario y contraseña además del token y un doble factor por correo.

## Contexto

El superadmin entraba solo con `PLATFORM_ADMIN_TOKEN`, un token compartido que da
acceso global (todos los negocios, cobros, llaves de pasarelas). Para que no se
adivinara la entrada, vivía en una dirección rara (`/splataformadm1n`). Un token
filtrado bastaba para entrar, y no quedaba quién había entrado.

## Decisión

**Operadores con cuenta.** `administradores_plataforma` guarda nombre, correo,
contraseña con hash y si está activo. Se dan de alta con
`agendauno:admin-plataforma {correo} --nombre= --password=`; sin contraseña, el
comando la pide sin mostrarla. `--desactivar` quita el acceso. En desarrollo,
`agendauno:sembrar-demos` crea `superadmin@agendauno.test` con la contraseña de los
demos.

**Entrada en dos pasos:**
1. `POST /api/v1/plataforma/entrar {email, password, token}`. Los tres deben coincidir
   y el error no dice cuál falló. Se manda al correo del operador un código de 6
   dígitos. Se guarda solo su huella (HMAC con la llave de la aplicación), vence en 10
   minutos (`PLATFORM_CODIGO_MINUTOS`) y admite 5 intentos (`PLATFORM_CODIGO_INTENTOS`).
   Un código nuevo invalida el anterior. Fuera de producción, la respuesta trae el
   código (el correo va al registro) y la pantalla lo muestra.
2. `POST /api/v1/plataforma/entrar/verificar {desafio, codigo}` abre una sesión con su
   propio token. Se guarda solo su huella SHA-256 y vence en 12 horas
   (`PLATFORM_SESION_HORAS`). `POST /plataforma/salir` la cierra; `GET /plataforma/yo`
   dice quién entró.

**El token solo.** En producción deja de abrir la API: `PLATFORM_ADMIN_TOKEN_DIRECTO`
vale `false` por omisión. Fuera de producción sigue sirviendo para pruebas y scripts.
Sin `PLATFORM_ADMIN_TOKEN` configurado, el superadmin queda deshabilitado, como antes.

**Límites.** Entrar y verificar tienen su propio límite de peticiones: 20 por minuto
por IP y 10 por correo o desafío.

**Web.** La dirección vuelve a ser `/plataforma`; la anterior redirige ahí. `plataforma`
queda reservado como slug de negocio. La puerta pide correo, contraseña y token, y
después el código. El encabezado muestra al operador y «Salir» cierra la sesión en el
servidor.

## Consecuencias

- Un token filtrado ya no basta: hacen falta la contraseña de un operador y su correo.
- Cada entrada queda con su operador: último acceso, IP y navegador de la sesión.
- **Para producción:** correr la migración del control plane y dar de alta a cada
  operador con `agendauno:admin-plataforma`. El correo de la plataforma debe estar
  configurado: sin él no llega el código.
