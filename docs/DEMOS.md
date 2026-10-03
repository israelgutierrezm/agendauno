# Demos con historia (solo desarrollo)

`php artisan agendauno:sembrar-demos` siembra los dos negocios de muestra, uno por cada
modalidad de cobro, con meses de historia coherente. Cada uno trae solo lo que le
corresponde: el de clases no tiene citas y el de citas no tiene clases grupales ni
planes. Los datos son ficticios: los nombres de clientes y miembros salen al azar con
semilla fija, los correos terminan en `.test` y nada de la historia dispara avisos.

```
php artisan agendauno:sembrar-demos --rehacer             # los dos, 120 días
php artisan agendauno:sembrar-demos --solo=grecon --rehacer --dias=60
php artisan agendauno:sembrar-demos --solo=barberia --rehacer
```

`--rehacer` borra la base del negocio y lo siembra desde cero; sin él, un demo que ya
existe no se toca. Todas las cuentas usan la contraseña de `--password` (por defecto,
la del demo). El comando imprime al terminar las cuentas y lo que se sembró.

## Cómo se siembra

Cada día de la historia pasa por los servicios del dominio con el reloj en ese
momento: agendar, reservar, cancelar, reprogramar, marcar asistencia, cobrar en caja y
vender planes y productos. Así órdenes, pagos, créditos, nómina, reportes y bitácora
quedan como si hubiera ocurrido. Ningún registro queda después de la hora real en
que corre el comando. Hacia adelante quedan dos semanas de citas y reservas.

## Grecon Art House (`/app/demo`, clases: cobra por alumnos activos)

Demo con lo que da `docs/DEMO_GRECON.md`: profesores, clases, horario y precios de
prueba. Es un negocio real, así que queda **solo con enlace** (fuera del directorio) y
su nombre dice «(demo)».

- **Sede:** una, sin dirección (la real no está confirmada).
- **Equipo:**
  - Constantino Escobar, el dueño, que también da clases y toma clases con otros
    profesores: una cuenta con los roles dueño, instructor y alumno (elige con cuál
    entrar) y su ficha de alumno con Ilimitada;
  - administración y recepción;
  - los otros 10 profesores con el nombre que publica el estudio. Sus cuentas son de
    prueba: `nombre@grecon.test`.
- **Clases:** las 26 publicadas, en cinco categorías: Pole, Exotic, Flexibilidad, Danza y
  Open Training. Cupo de 8; Open Training, 12.
- **Horario:** el semanal de 44 clases. Octubre de 2026 es exactamente la agenda
  publicada: suplencias, clases sin profesor publicado, semanas sin cierta clase y la
  sesión suspendida. Los meses anteriores repiten el horario semanal.
- **Planes**, todos hasta fin de mes:
  - Paquete 4 clases $800; Paquete 8 clases $1,400; Paquete 12 clases $1,800 (sin Open
    Training);
  - Ilimitada $2,200 (incluye Open Training).
- **Cancelación:** con 6 horas; tarde o sin asistir consume la clase.
- **Miembros:** 80 inventados (62 que ya venían y 18 que llegan durante la historia),
  cada uno con su estilo (pole por nivel, exotic o mixto), su horario y su plan; más el
  dueño como alumno.
- **Historia:**
  - compran su plan al empezar el mes o cuando se les acaba (efectivo, tarjeta o
    transferencia) y algunos cambian de plan o se van;
  - reservas, cancelaciones a tiempo o tarde y lista de espera (entra quien esperaba);
  - asistencia y faltas, y quien empieza en Level 1 sube a Level 2;
  - reseñas.
- **Al día de hoy:** clases apartadas para las próximas dos semanas, órdenes del mes por
  cobrar en recepción y algunos adeudos de quienes dejaron de venir.
- **Fuera del demo**, porque el sistema aún no lo aplica o el documento no lo da:
  - permanencia, penalización, inscripción y anualidad;
  - inventario y nómina.

| Cuenta | Para revisar |
| --- | --- |
| `constantino@grecon.test` | Constantino Escobar: dueño, instructor y alumno (elige con qué rol entrar) |
| `admin@grecon.test` | Administración: catálogo, agenda, miembros, ventas y configuración |
| `recepcion@grecon.test` | Recepción |
| `abril@grecon.test` | Instructora (Abril Von) |
| `valeria.rios@correo.test` | Miembro con Paquete 8 clases («Mi cuenta») |
| `renata.soto@correo.test` | Miembro con Ilimitada, incluye Open Training («Mi cuenta») |

## Barbería La Navaja (`/app/barberia`, citas: cobra por profesionales activos)

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
