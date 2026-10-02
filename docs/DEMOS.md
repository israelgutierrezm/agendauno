# Demos con historia (solo desarrollo)

`php artisan agendauno:sembrar-demos` siembra desde cero dos negocios de muestra con
meses de historia coherente, para revisar todo el sistema con datos creíbles. Los
datos son ficticios: los nombres salen al azar con semilla fija, los correos terminan
en `.test` y nada de la historia dispara avisos.

```
php artisan agendauno:sembrar-demos                       # los dos, 120 días
php artisan agendauno:sembrar-demos --solo=barberia --dias=60
php artisan agendauno:sembrar-demos --rehacer             # borra su BD y los siembra otra vez
```

Todas las cuentas usan la contraseña de `--password` (por defecto, la del demo). El
comando imprime al terminar las cuentas y lo que se sembró.

## Cómo se siembra

Cada día de la historia pasa por los servicios del dominio con el reloj en ese
momento: agendar, reservar, cancelar, reprogramar, marcar asistencia, cobrar en caja,
vender planes y productos. Así órdenes, pagos, créditos, nómina, reportes y bitácora
quedan como si hubiera ocurrido. Ningún registro queda después de la hora real en
que corre el comando. Hacia adelante quedan dos semanas de citas y reservas.

## Barbería La Navaja (`/app/la-navaja`, citas)

- **Sucursales:** Roma Norte y Del Valle, con dirección, horario y ubicación.
- **Equipo:**
  - el dueño, que también corta;
  - administradora y recepción;
  - cuatro barberos con su horario, su comida diaria y unas vacaciones;
  - una cajera con el rol propio «Caja»;
  - un contador con el rol propio «Pagos en línea» (solo pasarelas).
- **Servicios:** corte, corte y barba, arreglo de barba, afeitado y corte infantil, con
  duración y precio. Productos de mostrador con inventario por sucursal.
- **Clientes:** unos 450 que vuelven cada dos a cinco semanas con su barbero, más los
  que llegan sin cita (algunos se quedan).
- **Historia:**
  - citas agendadas con días de anticipación;
  - cancelaciones (del cliente o del negocio) y reprogramaciones;
  - inasistencias y cobros en efectivo, tarjeta o transferencia;
  - reseñas y días feriados cerrados.
- **Cliente con cuenta:** Jorge Pineda («Mi cuenta»).

## Aura Pole Studio (`/app/aura-pole`, clases)

- **Sede:** Del Valle, con sala de tubos y sala de flexibilidad.
- **Equipo:**
  - la dueña, que también es alumna (roles dueña y alumna);
  - administradora y recepción;
  - una coordinadora con el rol propio «Coordinación» (permisos limitados);
  - cuatro instructoras con su esquema de nómina.
- **Clases:** Pole Nivel 1 a 3, Pole Heels, Flexibilidad y Acondicionamiento, en un
  horario semanal fijo (clases recurrentes).
- **Planes:** clase muestra, clase suelta, paquetes de 4, 8 y 12 clases al mes,
  mensualidad ilimitada y clases extra.
- **Alumnas:**
  - unas 120, con sus días, horario, nivel y plan;
  - las nuevas llegan con clase muestra y casi dos de cada tres se quedan;
  - suben de nivel con el tiempo y algunas se van al acabarse su plan.
- **Historia:**
  - reservas y cancelaciones a tiempo o tarde;
  - lista de espera (entra quien esperaba);
  - asistencia y faltas;
  - compras de planes y productos, y reseñas.
- **Alumna con cuenta:** Sofía Herrera («Mi cuenta»).
