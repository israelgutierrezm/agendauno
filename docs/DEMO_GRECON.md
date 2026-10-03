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
| Ilimitada | $2,200 MXN; la concesión inicial cubre octubre, sin contrato ni renovación automática |
| Cupo regular / Open Training | 8 / 12 lugares |
| Sede y zona horaria | Una sede de prueba, America/Mexico_City, pendiente de confirmación real |
| Cancelación de clase | 6 horas; tarde o inasistencia consume clase. Política ficticia, independiente del contrato. |

Los derechos iniciales se conceden directamente para probar reservas: **no son
ventas pagadas**, no generan ingresos ni simulan movimientos bancarios. No hay
tarjetas, domiciliaciones ni pasarelas configuradas. Inscripción, anualidad y
penalización no se inventan como productos sin efecto: quedan pendientes.

## Crear y acceder

Desde `apps/api`, con la base central de desarrollo disponible:

```sh
php artisan agendauno:sembrar-demo-grecon
```

Admite `--password=...`; por defecto usa `password`, exclusivamente para pruebas
locales. Solo admite ambientes `local` y `testing`. Crea `grecon-demo`; no ejecuta
los otros sembradores, no borra ni reemplaza negocios existentes. Si ese slug ya
existe, no lo toca. Si falla a mitad, conserva el registro oculto en aprovisionamiento
para diagnóstico; no lo reintentes esperando un reinicio destructivo.

Enlace local: `http://localhost:5175/entrar?estudio=grecon-demo`.
La demo queda fuera del directorio público; el acceso se hace por enlace directo.

| Cuenta ficticia | Escenario |
| --- | --- |
| admin@grecon.test | Propietario: catálogo, agenda, alumnos, ventas y configuración |
| paquete@grecon.test | Paquete de 8 clases de octubre; no puede reservar Open Training |
| ilimitada@grecon.test | Plan ilimitado de octubre; puede reservar Open Training sujeto a cupo |
| vencido@grecon.test | Paquete de septiembre vencido |
| sinplan@grecon.test | Alumna sin compra |
| instructor-1@grecon.test | Vista de instructor: CONSTANTINO ESCOBAR |

Los demás instructores tienen cuentas técnicas `instructor-2@grecon.test` hasta
`instructor-11@grecon.test`; no son sus correos reales. Consultar **octubre de 2026**
en la agenda: no se modifica el reloj ni se desplaza el horario a otro mes. Después
de octubre las sesiones serán históricas y los derechos habrán vencido.

## Pruebas

`tests/Feature/ControlPlane/DemoGreconTest.php` comprueba fidelidad de cada sesión,
aislamiento, repetición no destructiva, login, ausencia de pagos/eventos al sembrar,
restricción de Open Training, descuento de créditos y vencimiento al fin de mes.
También documenta mediante una prueba que hoy Open Training sigue siendo visible
al paquete, aunque no reservable: es un pendiente, no una prestación terminada.
