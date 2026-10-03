# Grecon Art House: cobertura y demo local

Revisión: 2 de octubre de 2026. Esta es una **demo no oficial**, no la operación
real del establecimiento ni una integración con ReservaClase.

## Qué significa cada producto en este caso

- **Paquete mensual:** compra de N clases para un mes calendario. Se agota al
  consumirlas o al terminar el mes; no arrastra clases y no obliga a renovar.
- **Membresía:** relación recurrente que, según las reglas comunicadas para este
  estudio, incluye clases ilimitadas y Open Training, con un compromiso mínimo
  de 12 meses. El compromiso, el periodo de cobro y los beneficios son conceptos
  independientes. Una membresía no tiene que ser anual ni ilimitada en todos los
  negocios: esas condiciones deben ser configurables.
- **Inscripción y anualidad:** cargos adicionales, no créditos ni clases. La
  anualidad vence en el aniversario del alta, no al inicio de cada año calendario.

## Cobertura verificada en código

| Regla | Estado y configuración |
| --- | --- |
| Clases con horario, instructor y cupo | Disponible: sesiones grupales y reservas con control de capacidad. Open Training también es una sesión con cupo; no es un simple pase de acceso al local. |
| N clases hasta el último día del mes | Disponible: `paquete`, N × 1000 créditos, `vigencia_tipo=fin_de_mes`, cantidad 1, `politica_reset=ninguno`, sin rollover. La fecha de compra es el inicio del derecho; su fin es el último día del mes. No concede acceso retroactivo. |
| Paquete mensual sin renovación obligatoria | Disponible: cada compra otorga un derecho independiente. No configurar reinicio mensual si cada mes requiere una nueva compra. |
| Membresía ilimitada | Disponible: derecho ilimitado; no elude el control de cupo ni las reglas de reservas. |
| Cobro recurrente/domiciliado | Hay motor, pasarelas, conciliación y mora. Requiere configuración, consentimiento y pruebas de la pasarela; esta demo no lo activa. |
| Open Training reservable solo con ilimitada | Configurable: lista explícita de clases permitidas para cada producto. Los paquetes excluyen las dos ofertas Open Training; la ilimitada las incluye. No dejar vacía la lista del paquete, porque vacío significa todas las clases. |
| Open Training visible solo con ilimitada | Pendiente: `MiTenantController::agenda` devuelve todas las sesiones grupales del periodo. Bloquear la reserva no oculta su existencia. |
| Permanencia mínima de 12 meses | Pendiente: el acuerdo no guarda plazo contractual mínimo ni un flujo de baja que lo aplique. Una vigencia de 12 meses no sustituye esa regla. |
| Baja anticipada: 50% de mensualidades restantes | Pendiente: faltan cotización, base de cálculo congelada, orden de penalización, aprobación y cierre del contrato. No confundir cancelación de una clase con terminación de una membresía. |
| Inscripción de primera alta | Pendiente como automatismo: registrar un cobro manual no equivale a exigirlo una sola vez antes de activar beneficios. |
| Anualidad por aniversario | Pendiente como cargo automático separado e idempotente. No basta con renombrar un paquete como anualidad. |

### Ajustes recomendados antes de operar exactamente como Grecon

1. Beneficios y visibilidad: política configurable por oferta para mostrarla solo
   a personas con derecho elegible. Aplicar en API, calendario del alumno, web,
   app y cualquier agenda pública; mantener validación de reserva en servidor.
   Una clase normal sin saldo puede seguir mostrándose para vender un paquete:
   no ocultar indiscriminadamente toda clase que no esté cubierta.
2. Contrato del alumno: fecha de alta, inicio y fin de permanencia, precio pactado,
   versión de condiciones aceptadas y fecha efectiva de baja. Los meses de
   beneficios, facturación y compromiso contractual deben modelarse aparte.
3. Baja anticipada: simulación transparente de meses pendientes × mensualidad
   pactada × 50%, manejo explícito del mes parcial, adeudos y descuentos; nunca
   ejecutar automáticamente un cargo sin el flujo/autorización que corresponda.
4. Cuotas: inscripción inicial y anualidad por aniversario con historial de
   pagos, vencimiento, exenciones, reingresos y regla de bloqueo configurable.
   Cada vencimiento debe poder generarse una sola vez y admitir pago en caja.
5. Pruebas de cambio de mes, año bisiesto, alta a mitad de mes, mora, cancelación,
   reintegro de clases ya vencidas y reservas anticipadas para el siguiente mes.

Las condiciones contractuales descritas son requisitos del usuario: esta revisión
no valida su legalidad ni redacta el contrato del estudio.

## Fuente y datos conservados

Agenda pública de octubre de 2026 extraída previamente, recorriendo las semanas:
https://reservaclase.com/greconarthouse/index.php?menu=inicio

Instantánea: `apps/api/database/demo/grecon-octubre-2026.json`.

- 191 sesiones: 190 programadas y 1 suspendida, representada como cancelada.
- 26 nombres de clase y 11 instructores con sus nombres publicados.
- 44 sesiones Open Training sin instructor, más 2 clases sin instructor publicado.
- Horarios y duraciones conservados, incluida la actividad de 90 minutos.
- No se extrapolan semanas; se conservan variaciones de instructor y omisiones.
- No se copian alumnos, reservas, fotos, teléfonos ni correos personales.
- El indicador público de “lleno” permanece como evidencia en el archivo; no se
  inventan reservas reales para reproducirlo. La demo inicia con cupos libres.

## Valores ficticios autorizados para pruebas

| Concepto | Valor de prueba, no tarifa real |
| --- | --- |
| Paquete 4 clases | $800 MXN, hasta fin del mes de compra |
| Paquete 8 clases | $1,400 MXN, hasta fin del mes de compra |
| Paquete 12 clases | $1,800 MXN, hasta fin del mes de compra |
| Ilimitada | $2,200 MXN, hasta fin del mes de compra; incluye Open Training. Sin contrato ni renovación automática |
| Cupo regular / Open Training | 8 / 12 lugares |
| Sede y zona horaria | Una sede de prueba, America/Mexico_City, pendiente de confirmación real |
| Cancelación de clase | 6 horas; tarde o inasistencia consume clase. Política ficticia, independiente del contrato. |

En la demo, los 80 miembros (inventados) compran estos planes en recepción y tienen su
historia de pagos, reservas y asistencia (ver `docs/DEMOS.md`). No hay tarjetas,
domiciliaciones ni pasarelas configuradas. Inscripción, anualidad y penalización no se
inventan como productos sin efecto: quedan pendientes.

## Crear y acceder

Esta demo es el negocio `demo`, el de la modalidad de cobro por alumnos activos. Se
siembra con los dos demos, desde `apps/api`:

```sh
php artisan agendauno:sembrar-demos --solo=grecon --rehacer
```

`--rehacer` borra la base del negocio y lo siembra desde cero. Admite `--password=...`
y `--dias=...` (por defecto, 120 días de historia). Nunca corre en producción.

Enlace local: `http://localhost:5175/entrar?estudio=demo`. Queda **solo con enlace**:
fuera del directorio público, y su nombre dice «(demo)».

| Cuenta ficticia | Escenario |
| --- | --- |
| constantino@grecon.test | Constantino Escobar: dueño, instructor y alumno con Ilimitada. Al entrar elige con qué rol |
| admin@grecon.test | Administración: catálogo, agenda, miembros, ventas y configuración |
| recepcion@grecon.test | Recepción: cobrar planes, pasar lista y lo por cobrar |
| abril@grecon.test | Vista de instructora: Abril Von |
| valeria.rios@correo.test | Miembro con Paquete 8 clases del mes; no puede reservar Open Training |
| renata.soto@correo.test | Miembro con Ilimitada del mes; puede reservar Open Training sujeto a cupo |

Los demás profesores tienen cuentas de prueba `nombre@grecon.test` (tabla siguiente);
no son sus correos reales. Los meses anteriores a octubre repiten el horario semanal;
octubre es exactamente la agenda publicada.

## Pruebas

`tests/Feature/ControlPlane/DemoGreconHistoriaTest.php` siembra la demo con el reloj
fijo y comprueba que octubre sea la agenda publicada (fecha, clase, profesor, duración
y la sesión suspendida), los precios y que los paquetes no incluyan Open Training, los
80 miembros con su historia (pagos, órdenes por cobrar, asistencia y clases apartadas)
y que nada de la historia quede por avisar.

## Referencia completa para probar la demo

Referencia añadida el 3 de octubre de 2026, a partir de la misma instantánea utilizada para crear la demo. **No es una consulta en vivo ni garantiza que el estudio real mantenga hoy este horario.**

Los nombres se conservan tal como se publicaron, incluidas diferencias como FLEXY LAB / FLEX LAB y EXOTIC TICKS. No se corrigen ni se fusionan clases por suposición.

### Cómo leer los datos

- **Clase/actividad:** en esta demo cada uno de los 26 nombres publicados tiene una actividad y una oferta de clase con el mismo nombre. Una sesión es su ocurrencia concreta en una fecha y hora.
- **Nivel:** solo se reconoce cuando el nombre dice LEVEL 1, LEVEL 2, LEVEL 3 o Multinivel. Los demás no tienen un nivel publicado; no significa que sean de principiantes. El texto de nivel no establece por sí mismo requisitos de acceso en el sistema.
- **Horario:** se reproduce la hora publicada, interpretada en la demo como America/Mexico_City. Esta zona es una hipótesis de prueba, no un dato confirmado por la fuente.
- **Profesor sin publicar:** no se inventa un instructor. Open Training es práctica libre con reserva y cupo, no una clase impartida por un profesor.
- **Estado:** la única sesión suspendida se creó como cancelada. “Lleno en la fuente” refleja la extracción original; la demo no copia esa ocupación ni reservas de personas reales.
- **Cupo de prueba:** 8 lugares en clases regulares y 12 en Open Training, valores ficticios autorizados. Todos los conteos siguientes incluyen la sesión suspendida.

### Profesores e instructores

Las cuentas son ficticias y exclusivas de la demo; no son datos de contacto de los profesores. Contraseña local de prueba: `password`.

| Profesor publicado | Cuenta de demo | Sesiones publicadas | Clases/actividades asignadas en octubre |
| --- | --- | ---: | --- |
| CONSTANTINO ESCOBAR | `constantino@grecon.test` | 39 | EXOTIC Matutino; POLE Multinivel Matutino; HANDSPRING LAB; EXOTIC CHOREO; EXO BASE LAB; POLE LEVEL 1; ACRO POLE MULTINIVEL; LOW FLOW |
| ABRIL VON | `abril@grecon.test` | 26 | EXOTIC LAB; EXOTIC CHOREO; HEELS COMBOS; FLEX LAB Matutino; EXOTIC CHOREO Matutino |
| LEO ARELLANO | `leo@grecon.test` | 35 | POLE LEVEL 2; POLE SHAPES MULTINIVEL; FLYING POLE Multinivel; POLE FITNESS Multinivel; POLE LEVEL 3; FLEX LAB Matutino; POLE STRAPS Matutino; ACRO POLE MULTINIVEL; HANDSPRING LAB |
| ROCK GUARDIOLA | `rock@grecon.test` | 15 | FLEXY LAB; POLE Multinivel Matutino; EXOTIC CHOREO; RUSSIAN EXOTIC Matutino; EXOTIC TICKS Matutino |
| MANU MARTINEZ | `manu@grecon.test` | 5 | JAZZ FUNK |
| CRIS MARTINEZ | `cris@grecon.test` | 5 | ESPIRAL Multinivel |
| ALLA ENSO | `alla@grecon.test` | 6 | RUSSIAN EXOTIC Matutino; EXOTIC TICKS Matutino |
| JAVI CAMPUZANO | `javi@grecon.test` | 4 | ACRO MOVING |
| ANDREA SALHER | `andrea@grecon.test` | 3 | POLE LEVEL 3 |
| DIANA HERNANDEZ | `diana@grecon.test` | 3 | POLE LEVEL 1; EXOTIC CHOREO |
| KARLA GOMEZ | `karla@grecon.test` | 4 | POLE LEVEL 3; POLE STRAPS Matutino; POLE Multinivel Matutino |

Además hay **46 sesiones sin instructor publicado**: 44 Open Training y 2 de otras clases el 29 de octubre. No se asignaron a un profesor ficticio.

### Catálogo de clases, niveles y duración

“Acceso” describe la configuración de los productos de esta demo; los paquetes solo reservan mientras tengan saldo y vigencia, y ambos planes están sujetos a cupo y reglas de reserva.

| Clase / actividad publicada | Nivel explícito | Duración | Sesiones en octubre | Acceso en la demo |
| --- | --- | --- | ---: | --- |
| EXOTIC Matutino | No publicado | 60 min | 5 | Paquete o ilimitada |
| POLE Multinivel Matutino | Multinivel | 60 min | 10 | Paquete o ilimitada |
| OPEN TRAINING Matutino | No publicado | 60 min | 22 | Solo ilimitada |
| OPEN TRAINING | No publicado | 60 min | 22 | Solo ilimitada |
| EXOTIC LAB | No publicado | 60 min | 5 | Paquete o ilimitada |
| HANDSPRING LAB | No publicado | 60 min | 10 | Paquete o ilimitada |
| POLE LEVEL 2 | Nivel 2 | 60 min | 9 | Paquete o ilimitada |
| FLEXY LAB | No publicado | 60 min | 5 | Paquete o ilimitada |
| JAZZ FUNK | No publicado | 60 min | 5 | Paquete o ilimitada |
| EXOTIC CHOREO | No publicado | 60 min | 21 | Paquete o ilimitada |
| ESPIRAL Multinivel | Multinivel | 60 min | 5 | Paquete o ilimitada |
| POLE SHAPES MULTINIVEL | Multinivel | 60 min | 5 | Paquete o ilimitada |
| EXO BASE LAB | No publicado | 60 min | 4 | Paquete o ilimitada |
| FLYING POLE Multinivel | Multinivel | 60 min | 4 | Paquete o ilimitada |
| POLE FITNESS Multinivel | Multinivel | 60 min | 4 | Paquete o ilimitada |
| HEELS COMBOS | No publicado | 60 min | 4 | Paquete o ilimitada |
| RUSSIAN EXOTIC Matutino | No publicado | 60 min | 4 | Paquete o ilimitada |
| EXOTIC TICKS Matutino | No publicado | 60 min | 4 | Paquete o ilimitada |
| POLE LEVEL 1 | Nivel 1 | 60 min | 8 | Paquete o ilimitada |
| ACRO MOVING | No publicado | 90 min | 4 | Paquete o ilimitada |
| POLE LEVEL 3 | Nivel 3 | 60 min | 8 | Paquete o ilimitada |
| FLEX LAB Matutino | No publicado | 60 min | 8 | Paquete o ilimitada |
| EXOTIC CHOREO Matutino | No publicado | 60 min | 4 | Paquete o ilimitada |
| ACRO POLE MULTINIVEL | Multinivel | 60 min | 4 | Paquete o ilimitada |
| POLE STRAPS Matutino | No publicado | 60 min | 4 | Paquete o ilimitada |
| LOW FLOW | No publicado | 60 min | 3 | Paquete o ilimitada |

### Agenda por fecha: octubre de 2026

Se incluyen las **191 sesiones de los 31 días**. Esta es la referencia para comparar en la agenda; no una plantilla semanal repetida. Las variaciones de profesores y las sesiones que faltan en ciertas semanas se conservan.

#### 2026-10-01 · jueves

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | EXOTIC Matutino | CONSTANTINO ESCOBAR | Programada |
| 11:00–12:00 | POLE Multinivel Matutino | CONSTANTINO ESCOBAR | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC LAB | ABRIL VON | Programada |
| 19:00–20:00 | HANDSPRING LAB | CONSTANTINO ESCOBAR | Programada |
| 20:00–21:00 | POLE LEVEL 2 | LEO ARELLANO | Programada |
| 21:00–22:00 | FLEXY LAB | ROCK GUARDIOLA | Programada |

#### 2026-10-02 · viernes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 11:00–12:00 | POLE Multinivel Matutino | ROCK GUARDIOLA | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | JAZZ FUNK | MANU MARTINEZ | Programada |
| 19:30–20:30 | EXOTIC CHOREO | CONSTANTINO ESCOBAR | Programada |

#### 2026-10-03 · sábado

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 08:00–09:00 | ESPIRAL Multinivel | CRIS MARTINEZ | Programada |
| 09:00–10:00 | POLE SHAPES MULTINIVEL | LEO ARELLANO | Programada |
| 10:00–11:00 | EXOTIC CHOREO | ABRIL VON | Programada; lleno en la fuente, sin copiar ocupación |
| 11:00–12:00 | HANDSPRING LAB | CONSTANTINO ESCOBAR | Programada; lleno en la fuente, sin copiar ocupación |
| 12:00–13:00 | EXO BASE LAB | CONSTANTINO ESCOBAR | Programada; lleno en la fuente, sin copiar ocupación |

#### 2026-10-04 · domingo

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 08:00–09:00 | FLYING POLE Multinivel | LEO ARELLANO | Programada |
| 09:00–10:00 | POLE FITNESS Multinivel | LEO ARELLANO | Programada |
| 10:00–11:00 | EXOTIC CHOREO | ABRIL VON | Programada |
| 11:00–12:00 | HEELS COMBOS | ABRIL VON | Suspendida en fuente → cancelada en demo |

#### 2026-10-05 · lunes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | RUSSIAN EXOTIC Matutino | ALLA ENSO | Programada |
| 11:00–12:00 | EXOTIC TICKS Matutino | ALLA ENSO | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | POLE LEVEL 1 | CONSTANTINO ESCOBAR | Programada |
| 19:00–20:30 | ACRO MOVING | JAVI CAMPUZANO | Programada |
| 20:30–21:30 | POLE LEVEL 3 | LEO ARELLANO | Programada |

#### 2026-10-06 · martes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | FLEX LAB Matutino | ABRIL VON | Programada |
| 11:00–12:00 | EXOTIC CHOREO Matutino | ABRIL VON | Programada; lleno en la fuente, sin copiar ocupación |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC CHOREO | CONSTANTINO ESCOBAR | Programada; lleno en la fuente, sin copiar ocupación |
| 19:00–20:00 | ACRO POLE MULTINIVEL | CONSTANTINO ESCOBAR | Programada |
| 20:00–21:00 | POLE LEVEL 2 | LEO ARELLANO | Programada |
| 21:00–22:00 | FLEX LAB Matutino | LEO ARELLANO | Programada |

#### 2026-10-07 · miércoles

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | POLE STRAPS Matutino | LEO ARELLANO | Programada |
| 11:00–12:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC CHOREO | ROCK GUARDIOLA | Programada |
| 19:00–20:00 | POLE LEVEL 1 | CONSTANTINO ESCOBAR | Programada |
| 20:00–21:00 | POLE LEVEL 3 | ANDREA SALHER | Programada |
| 21:00–22:00 | LOW FLOW | CONSTANTINO ESCOBAR | Programada |

#### 2026-10-08 · jueves

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | EXOTIC Matutino | CONSTANTINO ESCOBAR | Programada |
| 11:00–12:00 | POLE Multinivel Matutino | CONSTANTINO ESCOBAR | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC LAB | ABRIL VON | Programada; lleno en la fuente, sin copiar ocupación |
| 19:00–20:00 | HANDSPRING LAB | CONSTANTINO ESCOBAR | Programada; lleno en la fuente, sin copiar ocupación |
| 20:00–21:00 | POLE LEVEL 2 | LEO ARELLANO | Programada |
| 21:00–22:00 | FLEXY LAB | ROCK GUARDIOLA | Programada |

#### 2026-10-09 · viernes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 11:00–12:00 | POLE Multinivel Matutino | ROCK GUARDIOLA | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | JAZZ FUNK | MANU MARTINEZ | Programada |
| 19:30–20:30 | EXOTIC CHOREO | CONSTANTINO ESCOBAR | Programada; lleno en la fuente, sin copiar ocupación |

#### 2026-10-10 · sábado

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 08:00–09:00 | ESPIRAL Multinivel | CRIS MARTINEZ | Programada |
| 09:00–10:00 | POLE SHAPES MULTINIVEL | LEO ARELLANO | Programada |
| 10:00–11:00 | EXOTIC CHOREO | ABRIL VON | Programada; lleno en la fuente, sin copiar ocupación |
| 11:00–12:00 | HANDSPRING LAB | CONSTANTINO ESCOBAR | Programada |
| 12:00–13:00 | EXO BASE LAB | CONSTANTINO ESCOBAR | Programada; lleno en la fuente, sin copiar ocupación |

#### 2026-10-11 · domingo

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 08:00–09:00 | FLYING POLE Multinivel | LEO ARELLANO | Programada |
| 09:00–10:00 | POLE FITNESS Multinivel | LEO ARELLANO | Programada |
| 10:00–11:00 | EXOTIC CHOREO | ABRIL VON | Programada; lleno en la fuente, sin copiar ocupación |
| 11:00–12:00 | HEELS COMBOS | ABRIL VON | Programada |

#### 2026-10-12 · lunes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | RUSSIAN EXOTIC Matutino | ROCK GUARDIOLA | Programada |
| 11:00–12:00 | EXOTIC TICKS Matutino | ROCK GUARDIOLA | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | POLE LEVEL 1 | CONSTANTINO ESCOBAR | Programada |
| 19:00–20:30 | ACRO MOVING | JAVI CAMPUZANO | Programada |
| 20:30–21:30 | POLE LEVEL 3 | LEO ARELLANO | Programada |

#### 2026-10-13 · martes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | FLEX LAB Matutino | ABRIL VON | Programada |
| 11:00–12:00 | EXOTIC CHOREO Matutino | ABRIL VON | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC CHOREO | CONSTANTINO ESCOBAR | Programada |
| 19:00–20:00 | ACRO POLE MULTINIVEL | CONSTANTINO ESCOBAR | Programada |
| 20:00–21:00 | POLE LEVEL 2 | LEO ARELLANO | Programada |
| 21:00–22:00 | FLEX LAB Matutino | LEO ARELLANO | Programada |

#### 2026-10-14 · miércoles

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | POLE STRAPS Matutino | LEO ARELLANO | Programada |
| 11:00–12:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC CHOREO | ROCK GUARDIOLA | Programada |
| 19:00–20:00 | POLE LEVEL 1 | CONSTANTINO ESCOBAR | Programada |
| 20:00–21:00 | POLE LEVEL 3 | ANDREA SALHER | Programada |
| 21:00–22:00 | LOW FLOW | CONSTANTINO ESCOBAR | Programada |

#### 2026-10-15 · jueves

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | EXOTIC Matutino | CONSTANTINO ESCOBAR | Programada |
| 11:00–12:00 | POLE Multinivel Matutino | CONSTANTINO ESCOBAR | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC LAB | ABRIL VON | Programada |
| 19:00–20:00 | HANDSPRING LAB | CONSTANTINO ESCOBAR | Programada |
| 20:00–21:00 | POLE LEVEL 2 | LEO ARELLANO | Programada |
| 21:00–22:00 | FLEXY LAB | ROCK GUARDIOLA | Programada |

#### 2026-10-16 · viernes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 11:00–12:00 | POLE Multinivel Matutino | ROCK GUARDIOLA | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | JAZZ FUNK | MANU MARTINEZ | Programada |
| 19:30–20:30 | EXOTIC CHOREO | CONSTANTINO ESCOBAR | Programada; lleno en la fuente, sin copiar ocupación |

#### 2026-10-17 · sábado

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 08:00–09:00 | ESPIRAL Multinivel | CRIS MARTINEZ | Programada |
| 09:00–10:00 | POLE SHAPES MULTINIVEL | LEO ARELLANO | Programada |
| 10:00–11:00 | EXOTIC CHOREO | ABRIL VON | Programada |
| 11:00–12:00 | HANDSPRING LAB | CONSTANTINO ESCOBAR | Programada |
| 12:00–13:00 | EXO BASE LAB | CONSTANTINO ESCOBAR | Programada |

#### 2026-10-18 · domingo

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 08:00–09:00 | FLYING POLE Multinivel | LEO ARELLANO | Programada |
| 09:00–10:00 | POLE FITNESS Multinivel | LEO ARELLANO | Programada |
| 10:00–11:00 | EXOTIC CHOREO | ABRIL VON | Programada |
| 11:00–12:00 | HEELS COMBOS | ABRIL VON | Programada |

#### 2026-10-19 · lunes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | RUSSIAN EXOTIC Matutino | ALLA ENSO | Programada |
| 11:00–12:00 | EXOTIC TICKS Matutino | ALLA ENSO | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | POLE LEVEL 1 | CONSTANTINO ESCOBAR | Programada |
| 19:00–20:30 | ACRO MOVING | JAVI CAMPUZANO | Programada |
| 20:30–21:30 | POLE LEVEL 3 | LEO ARELLANO | Programada |

#### 2026-10-20 · martes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | FLEX LAB Matutino | ABRIL VON | Programada |
| 11:00–12:00 | EXOTIC CHOREO Matutino | ABRIL VON | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC CHOREO | CONSTANTINO ESCOBAR | Programada |
| 19:00–20:00 | ACRO POLE MULTINIVEL | CONSTANTINO ESCOBAR | Programada |
| 20:00–21:00 | POLE LEVEL 2 | LEO ARELLANO | Programada |
| 21:00–22:00 | FLEX LAB Matutino | LEO ARELLANO | Programada |

#### 2026-10-21 · miércoles

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | POLE STRAPS Matutino | LEO ARELLANO | Programada |
| 11:00–12:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC CHOREO | ROCK GUARDIOLA | Programada |
| 19:00–20:00 | POLE LEVEL 1 | CONSTANTINO ESCOBAR | Programada |
| 20:00–21:00 | POLE LEVEL 3 | ANDREA SALHER | Programada |
| 21:00–22:00 | LOW FLOW | CONSTANTINO ESCOBAR | Programada |

#### 2026-10-22 · jueves

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | EXOTIC Matutino | CONSTANTINO ESCOBAR | Programada |
| 11:00–12:00 | POLE Multinivel Matutino | CONSTANTINO ESCOBAR | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC LAB | ABRIL VON | Programada |
| 19:00–20:00 | HANDSPRING LAB | CONSTANTINO ESCOBAR | Programada |
| 20:00–21:00 | POLE LEVEL 2 | LEO ARELLANO | Programada |
| 21:00–22:00 | FLEXY LAB | ROCK GUARDIOLA | Programada |

#### 2026-10-23 · viernes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 11:00–12:00 | POLE Multinivel Matutino | ROCK GUARDIOLA | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | JAZZ FUNK | MANU MARTINEZ | Programada |
| 19:30–20:30 | EXOTIC CHOREO | CONSTANTINO ESCOBAR | Programada; lleno en la fuente, sin copiar ocupación |

#### 2026-10-24 · sábado

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 08:00–09:00 | ESPIRAL Multinivel | CRIS MARTINEZ | Programada |
| 09:00–10:00 | POLE SHAPES MULTINIVEL | LEO ARELLANO | Programada |
| 10:00–11:00 | EXOTIC CHOREO | ABRIL VON | Programada |
| 11:00–12:00 | HANDSPRING LAB | CONSTANTINO ESCOBAR | Programada |
| 12:00–13:00 | EXO BASE LAB | CONSTANTINO ESCOBAR | Programada |

#### 2026-10-25 · domingo

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 08:00–09:00 | FLYING POLE Multinivel | LEO ARELLANO | Programada |
| 09:00–10:00 | POLE FITNESS Multinivel | LEO ARELLANO | Programada |
| 10:00–11:00 | EXOTIC CHOREO | ABRIL VON | Programada |
| 11:00–12:00 | HEELS COMBOS | ABRIL VON | Programada |

#### 2026-10-26 · lunes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | RUSSIAN EXOTIC Matutino | ALLA ENSO | Programada |
| 11:00–12:00 | EXOTIC TICKS Matutino | ALLA ENSO | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | POLE LEVEL 1 | DIANA HERNANDEZ | Programada |
| 19:00–20:30 | ACRO MOVING | JAVI CAMPUZANO | Programada |
| 20:30–21:30 | POLE LEVEL 3 | KARLA GOMEZ | Programada |

#### 2026-10-27 · martes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | FLEX LAB Matutino | ABRIL VON | Programada |
| 11:00–12:00 | EXOTIC CHOREO Matutino | ABRIL VON | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC CHOREO | DIANA HERNANDEZ | Programada |
| 19:00–20:00 | ACRO POLE MULTINIVEL | LEO ARELLANO | Programada |
| 20:00–21:00 | POLE LEVEL 2 | LEO ARELLANO | Programada |
| 21:00–22:00 | FLEX LAB Matutino | LEO ARELLANO | Programada |

#### 2026-10-28 · miércoles

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | POLE STRAPS Matutino | KARLA GOMEZ | Programada |
| 11:00–12:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC CHOREO | ROCK GUARDIOLA | Programada |
| 19:00–20:00 | POLE LEVEL 1 | DIANA HERNANDEZ | Programada |
| 20:00–21:00 | POLE LEVEL 3 | KARLA GOMEZ | Programada |

#### 2026-10-29 · jueves

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 10:00–11:00 | EXOTIC Matutino | Sin instructor publicado | Programada |
| 11:00–12:00 | POLE Multinivel Matutino | Sin instructor publicado | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | EXOTIC LAB | ABRIL VON | Programada |
| 19:00–20:00 | HANDSPRING LAB | LEO ARELLANO | Programada |
| 20:00–21:00 | POLE LEVEL 2 | LEO ARELLANO | Programada |
| 21:00–22:00 | FLEXY LAB | ROCK GUARDIOLA | Programada |

#### 2026-10-30 · viernes

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 11:00–12:00 | POLE Multinivel Matutino | KARLA GOMEZ | Programada |
| 12:00–13:00 | OPEN TRAINING Matutino | Sin instructor publicado | Programada |
| 17:00–18:00 | OPEN TRAINING | Sin instructor publicado | Programada |
| 18:00–19:00 | JAZZ FUNK | MANU MARTINEZ | Programada |

#### 2026-10-31 · sábado

| Hora | Clase / actividad | Profesor publicado | Estado y observaciones |
| --- | --- | --- | --- |
| 08:00–09:00 | ESPIRAL Multinivel | CRIS MARTINEZ | Programada |
| 09:00–10:00 | POLE SHAPES MULTINIVEL | LEO ARELLANO | Programada |
| 10:00–11:00 | EXOTIC CHOREO | ABRIL VON | Programada |
| 11:00–12:00 | HANDSPRING LAB | LEO ARELLANO | Programada |

### Casos concretos para comprobar

1. **Fidelidad de la agenda:** con administración, abrir octubre de 2026 y comparar fecha, nombre, instructor, inicio y fin con estas tablas; usar las fechas, no solo el día de la semana.
2. **Suspensión:** HEELS COMBOS, 2026-10-04, 11:00–12:00, ABRIL VON debe figurar cancelada, no reservable.
3. **Duración distinta:** las sesiones ACRO MOVING duran 90 minutos; comprobar su hora de término.
4. **Profesor no publicado:** localizar las dos clases del 29 de octubre con instructor vacío y comprobar que no se les asignó un nombre por extrapolación.
5. **Paquete:** entrar como `valeria.rios@correo.test`, reservar una clase regular futura de octubre y comprobar que baja su saldo disponible en una clase.
6. **Open Training:** comparar la misma sesión futura con `valeria.rios@correo.test` (rechazo por falta de derecho elegible) y `renata.soto@correo.test` (reserva si hay cupo). La sesión todavía es visible para el paquete: ese filtrado sigue pendiente.
7. **Vigencia:** un miembro con su paquete de septiembre no puede reservar octubre con él; quien no tiene plan tampoco puede reservar sin comprar uno. En Miembros hay de ambos.
8. **Fin de mes:** un paquete de octubre no debe dar acceso a una sesión de noviembre. La instantánea no incluye noviembre: crear una sesión adicional de prueba si se desea comprobar ese límite desde la interfaz.

Las pruebas de reserva deben usar sesiones futuras respecto al reloj real. La agenda histórica permanece consultable para administración, aunque una sesión pasada ya no aparezca como reservable para el alumno.
