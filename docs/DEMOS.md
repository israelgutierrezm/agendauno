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

## Una cuenta por producto para revisarlo todo

Cada demo tiene un dueño que también imparte y también es cliente: una cuenta con tres
roles (elige con cuál entrar y lo cambia sin salir, ADR 0055). Con ella se ve cada
apartado del panel, la agenda de quien imparte y «Mi cuenta» con datos.

| Producto | Negocio | Cuenta | Entrar (desarrollo) |
| --- | --- | --- | --- |
| AgendaUno (clases) | Grecon Art House (`demo`, solo con enlace) | `constantino@grecon.test` | `http://localhost:5175/entrar?estudio=demo&producto=agendauno` |
| TurnoUno (citas) | Barbería La Navaja (`barberia`) | `ramon@lanavaja.test` | `http://localhost:5175/entrar?estudio=barberia&producto=turnouno` |

Su página pública: `/demo` y `/barberia` (con `?producto=` en desarrollo).

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
- **Membresía Ilimitada** (la del estudio real, ADR 0121 y 0137): se renueva cada mes,
  pide 12 meses de permanencia y, si se da de baja antes, el 50 % de lo que falte; con
  inscripción ($500) y anualidad ($900) de prueba. Se vendió en las últimas semanas:
  - dos con pago automático (tarjeta de prueba);
  - una en mora: su renovación venció sin cobrarse;
  - una dada de baja con su penalización por cobrar;
  - el dueño, como alumno, con pago automático.
- **Lo que la historia no crea** (bloque 32):
  - prospectos en cada etapa (nuevos, en seguimiento, aprobado, descartados);
  - Valeria, que fue prospecto: ve en su Inicio la membresía y la clase que pidió;
  - aviso de privacidad del negocio (aceptado por la mayoría);
  - dos cobros por conciliar, días cerrados (feriados), un bloqueo de la sala y otro de
    una coach, y un webhook pausado con sus entregas.
- **Lo más nuevo** (octubre de 2026):
  - caja de la sede: ayer contada a ciegas (faltaron $50 y se cerró con su motivo) y hoy
    abierta (ADR 0175);
  - bandeja con una conversación de Instagram y otra de WhatsApp ligada a su alumna
    (ADR 0200);
  - dos calendarios de coaches: uno conectado a Google y otro de Outlook por reconectar,
    sin llaves reales (ADR 0199);
  - el kiosco de la entrada (ADR 0183), los widgets «Horario» y «Precios» para su web
    (ADR 0141) y cuatro videos para sus alumnas (ADR 0184);
  - Wellhub y TotalPass configurados sin conectar (no llaman a nadie) y Fitpass activo,
    con tres lugares apartados en las próximas clases y dos visitas registradas
    (ADR 0202).
- **Fuera del demo:** entradas por QR (en un estudio, entrar es tomar la clase; ADR
  0105), grupos (el giro de pole no los usa) y Familias (un módulo que se pide aparte:
  apagado, para el negocio no existe).

| Cuenta | Para revisar |
| --- | --- |
| `constantino@grecon.test` | Constantino Escobar: dueño, instructor y alumno con Membresía Ilimitada y pago automático. **La cuenta para revisar todo.** |
| `admin@grecon.test` | Administración: catálogo, agenda, miembros, ventas y configuración |
| `recepcion@grecon.test` | Recepción |
| `abril@grecon.test` | Instructora (Abril Von) |
| `valeria.rios@correo.test` | Miembro con Paquete 8 clases; fue prospecto y ve lo que le interesó («Mi cuenta») |
| `renata.soto@correo.test` | Miembro con Ilimitada, incluye Open Training («Mi cuenta») |

## Barbería La Navaja (`/app/barberia`, citas: plan por profesionales contratados)

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
- **Plan del SaaS:** Pro, el más completo de TurnoUno (lealtad, reportes avanzados y
  venta en línea a la vista).
- **Cuentas:**
  - `ramon@lanavaja.test`: el dueño, que corta y también es cliente (corte y barba con
    Toño cada dos semanas). **La cuenta para revisar todo.**
  - `jorge.pineda@correo.test`: cliente con bono y su siguiente cita; fue prospecto (ve
    el bono que pidió).
- **Lo que la historia no crea** (bloque 32): prospectos en cada etapa, aviso de
  privacidad, presupuestos (enviado, convertido en orden por cobrar y borrador), dos
  bonos apartados por cobrar, dos cobros por conciliar y un webhook pausado. Sin
  membresías ni permanencia: en citas se venden bonos (ADR 0130). Familias, apagado.
- **Lo más nuevo** (octubre de 2026):
  - presupuestos en línea: uno que el cliente aceptó desde su correo (quedó por cobrar)
    y otro que rechazó con su motivo (ADR 0127);
  - la caja de cada sede: ayer contada (en Roma Norte faltaron $50 y se cerró con su
    motivo) y hoy abierta (ADR 0175);
  - bandeja con conversaciones de WhatsApp e Instagram (ADR 0200), dos calendarios de
    barberos (uno por reconectar, ADR 0199) y la tableta de llegada de Roma Norte;
  - los widgets «Agenda en mi web» (agendar cita) y «Bonos en mi web» (ADR 0141);
  - el afeitado clásico se aparta con un anticipo del 30 % al agendar en línea
    (ADR 0161);
  - tres clientes con sus datos para facturar, dos con factura automática pedida (el
    negocio la tiene apagada, ADR 0163).
