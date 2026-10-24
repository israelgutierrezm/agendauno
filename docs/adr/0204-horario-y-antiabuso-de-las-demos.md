# ADR 0204 — Horario semanal y antiabuso de las demos

Estado: Aceptado (2026-10-24). Ajusta el ADR 0193.

## Contexto

La demo de la landing (ADR 0193) se ofrecía en el llamado final, con un horario de
parámetros (de tal hora a tal hora, de lunes a viernes y, si se encendía, los sábados).
El dueño pidió:

- que la landing ofrezca agendar la demo como la competencia;
- un formulario y validaciones que eviten los envíos en masa, tomando a Fitune como
  ejemplo;
- cargar los horarios desde el superadmin y ver ahí cuántas demos hay agendadas.

Fitune no tiene formulario propio: usa uno de HubSpot en una página aparte, con botones
en el encabezado, el hero y el pie. Son dos pasos:

- su negocio: tipo, nombre, Instagram, si ya opera, cuántos clientes, ubicaciones y dos
  objetivos;
- su contacto: WhatsApp con lada y correo.

Luego sigue un calendario. No usa captcha ni campo trampa: confía en la validación del
servidor de HubSpot y en límites por intento.

## Decisión

- **Página propia** (`/demo`), como la de Fitune. Llevan a ella:
  - el botón «Agendar una demo» del hero, en lugar de «Ver la agenda» mientras haya
    demos;
  - el llamado final, que reemplaza el formulario incrustado;
  - las páginas por giro, que mandan su giro ya elegido (`?giro=`).

  Sin demos (apagadas o sin horarios), no aparecen los botones y la página ofrece crear
  la cuenta.
- **Formulario en tres pasos**:
  1. **Tu negocio**: giro (u «Otro» con cuál), nombre, si ya opera, cuántos alumnos o
     clientes o cuándo abre, sucursales, hasta dos cosas que quiere resolver e
     Instagram o página.
  2. **Día y hora**: los horarios libres.
  3. **Tus datos**: nombre, WhatsApp con lada (`CampoCelular`) y correo.

  Cada paso pide lo suyo antes de seguir. Si el servidor rechaza algo, se vuelve al paso
  donde se corrige. Lo que cuenta del negocio se guarda en `demos_agendadas.calificacion`
  (JSON; migración de plataforma 000412), y ventas lo ve en su correo y en la lista.
- **Antiabuso**, más que la competencia:
  - el captcha y el campo trampa que ya había;
  - un sello de tiempo cifrado que entrega `GET /demos`: un envío en menos de 3 s es de
    un programa y uno con la página abierta más de 6 h trae horarios viejos;
  - límites (`throttle:demos`): 5 por IP en 10 minutos y 15 al día, y 5 al día por
    correo y por teléfono. Cuentan también los rechazados, con margen para corregir;
  - una sola demo próxima por correo o teléfono: para cambiarla, se cancela con el
    enlace del correo. El mensaje no dice cuándo es;
  - sin enlaces en nombre y negocio;
  - sin correos de usar y tirar, con una lista que amplía `DEMOS_DOMINIOS_DESECHABLES`;
  - correo con formato RFC.
- **Horario desde el superadmin**: una sección propia, «Demos», en el grupo Negocios.
  Arriba muestra cuántas hay:
  - hoy, en los próximos 7 días y todas las próximas;
  - atendidas, sin contestar y canceladas en los últimos 30 días;
  - la cuenta de las próximas también va junto a «Demos» en el menú.

  Debajo tiene dos partes:
  - **Agendadas**: por páginas, con filtros (próximas, pasadas, canceladas; producto;
    búsqueda) y lo que contó cada quien. Una que ya empezó se marca atendida o «no
    contestó». Una que no ha empezado se cancela como acción de peligro, con
    confirmación.
  - **Horario**:
    - si se agendan;
    - duración, demos a la misma hora, días hacia adelante y anticipación;
    - uno o varios rangos por día de la semana (con «Copiar a martes–viernes»);
    - los días bloqueados con su motivo;
    - cuántas demos caben a la semana.

  El horario se guarda en la configuración de la plataforma (`demos.horario`, JSON).
  Mientras no se fije uno, rige de lunes a viernes de 10 a 18 h. Se retiraron los
  parámetros `demos.hora_inicio`, `demos.hora_fin` y `demos.sabados`. Los demás
  parámetros de demos se guardan igual, pero se editan aquí y no en Parámetros.

## Consecuencias

- Una página vieja del navegador (sin sello) recibe «Recarga la página» al agendar.
- Quien quiere otro horario cancela con el enlace de su correo y agenda de nuevo.
- Los horarios siguen en la zona de ventas (`VENTAS_ZONA`). Quien visita desde otra zona
  ve el nombre de esa zona junto a las horas.
- La cabecera y el pie de la parte pública los está cambiando otra sesión: el botón de
  demo en esos lugares queda para cuando se integren esos cambios.
