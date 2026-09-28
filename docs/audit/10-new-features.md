# Nuevas funciones y oportunidades de producto

## Principio de priorización

No conviene añadir funciones comerciales encima de pagos/créditos inseguros. Las oportunidades se ordenan por dependencia: primero confiabilidad y operación; luego retención/ingresos; después diferenciación.

## Tabla de oportunidades

| Oportunidad | Valor | Diferenciación | Esfuerzo | Dependencias |
|---|---:|---:|---:|---|
| Portal familiar completo | Alto | Alta para natación/infantil | M | Seguridad de tutelas, selector participante |
| Políticas configurables de reserva/no-show | Alto | Media-alta | M | Lifecycle hold/asistencia |
| Notificaciones y waitlist inteligente | Alto | Media | M | Outbox, preferencias, push/email |
| Suscripciones y cobro recurrente | Muy alto | Media | XL | Pagos/reembolsos/conciliación sólidos |
| Cobranza y recuperación de pagos | Alto | Media | L | Suscripciones, eventos PSP |
| Check-in QR seguro | Alto | Media | M | Mobile/instructor, tokens cortos, audit |
| Programación inteligente de recursos | Alto | Alta | L | Motor de disponibilidad/conflictos |
| Marketplace/booking público embebible | Alto | Alta | L | Catálogo publicable, checkout seguro |
| CRM de retención/churn | Alto | Alta con datos propios | L | Event analytics, consentimientos |
| Planes y control SaaS | Muy alto para negocio | Baja visible, esencial | L | Tenant lifecycle, metering |
| Analítica operativa/financiera | Alto | Media | L | Proyecciones reconciliadas |
| Motor vertical configurable | Muy alto | Muy alta | XL | Reglas, plantillas y feature flags |

## 1. Fundaciones convertidas en producto

### Centro de operaciones financieras

Vista unificada de órdenes, intentos, webhooks, devoluciones, fulfillment y discrepancias, con filtros por tenant/proveedor/edad. Acciones seguras: reintentar fulfillment, consultar PSP, iniciar devolución, adjuntar motivo y exportar evidencia.

**Por qué importa:** reduce soporte, evita dinero “perdido” entre estados y habilita pasarelas reales. Es una capacidad operativa, no solo técnica.

### Centro de incidencias de créditos

Detecta holds de sesiones pasadas, saldos negativos, movimientos duplicados y acuerdos cancelados con actividad. Ofrece reconciliación con preview y aprobación.

### Auditoría para el negocio

Timeline por persona/orden/sesión: quién hizo qué, desde qué canal y por qué. Un propietario puede investigar sin pedir logs al equipo técnico.

## 2. Portal familiar como ventaja vertical

Para natación infantil y academias:

- selector de dependiente;
- consentimientos y contactos de emergencia;
- restricciones por edad/nivel;
- compra por tutor y beneficiario explícito;
- agenda consolidada familiar;
- autorizados para recoger al menor;
- documentos con vencimiento y recordatorios;
- privacidad: cada tutor ve solo relaciones vigentes y campos permitidos.

Esto aprovecha el modelo `hogares/tutelas` ya existente y diferencia AgendaUno de un booking genérico.

## 3. Motor de políticas por oferta/tenant

Configurar sin código:

- ventana de reserva/cancelación;
- costo en créditos por sesión/tipo/hora;
- penalización de no-show y tolerancia;
- cupos por nivel/edad;
- límites semanales y reservas simultáneas;
- prioridad/reglas de waitlist;
- invitados, pruebas y pases;
- freeze/pausa y recuperación de clase.

Implementarlo como reglas versionadas con fecha de vigencia y explicación de decisión. Cada reserva guarda snapshot de la política aplicada; evita que un cambio retroactivo altere contratos.

## 4. Waitlist inteligente y ocupación

- promoción con ventana de aceptación;
- notificación push/email/WhatsApp opcional;
- expiración y siguiente candidato automático;
- prioridad configurable con transparencia;
- estimación de probabilidad de obtener lugar;
- overbooking controlado solo si el negocio lo habilita y con límites;
- dashboard de demanda perdida para abrir sesiones adicionales.

El diferenciador no es solo avisar: convertir demanda no atendida en decisiones de horario.

## 5. Programación y recursos

### Motor de disponibilidad

Detectar solapes de instructor, sala, carril, equipo y sucursal; buffers de limpieza/traslado; capacidad efectiva mínima de oferta/recurso; mantenimiento y bloqueos.

### Sugeridor de horarios

Usar historial agregado para proponer sesiones donde hay demanda, waitlist alta y recursos disponibles. Debe comenzar como recomendación explicable, no programación autónoma.

### Casos verticales

- natación: carril, alberca, nivel, ratio instructor/alumnos;
- pole: aparato por participante, sala y nivel;
- gym: zona/equipo, clase grupal y aforo.

## 6. Suscripciones y cobranza

Después de estabilizar pagos:

- payment methods tokenizados en PSP;
- renovación recurrente y prorrateo;
- dunning con reintentos seguros y comunicaciones;
- periodo de gracia configurable;
- pausa/cancelación y cambio de plan;
- factura/recibo fiscal mediante integración local, si el mercado lo exige;
- refunds parciales y créditos promocionales separados de dinero;
- conciliación y payout reports.

No almacenar datos de tarjeta; usar SDK/hosted fields del proveedor.

## 7. Booking público y crecimiento

- micrositio de horarios/productos publicado por tenant;
- widget embebible y link por sucursal/instructor;
- clase de prueba/lead capture;
- códigos de campaña/referral;
- gift cards y pases corporativos;
- disponibilidad en tiempo real sin exponer PII;
- checkout guest con conversión posterior a cuenta.

Requiere catálogo `draft/published/archived`, rate limit, antifraude y checkout seguro.

## 8. Experiencia instructor

- agenda del día limitada a asignaciones propias;
- roster mínimo, notas autorizadas y alertas relevantes;
- check-in QR rotatorio o lista offline limitada;
- marcar presente/ausente con confirmación y auditoría;
- sustituciones y disponibilidad;
- métricas de asistencia/ocupación, sin ranking invasivo.

El modo offline debe resolver conflictos y nunca permitir acceso indefinido a roster descargado.

## 9. CRM y retención

Señales útiles:

- miembro con saldo pero sin reserva;
- caída de frecuencia;
- muchas cancelaciones/no-show;
- derecho próximo a expirar;
- pago fallido o membresía por vencer;
- primera visita sin segunda reserva.

Crear segmentos y playbooks medibles, con consentimiento/preferencias y límites de comunicación. Empezar con reglas transparentes; modelos predictivos solo cuando haya datos, evaluación de sesgo y capacidad de explicación.

## 10. Analítica

Dashboards accionables:

- ocupación, waitlist, cancelación/no-show por oferta/hora;
- ingreso cobrado/reembolsado/pendiente y discrepancias;
- MRR/churn/retención para suscripciones;
- utilización de recurso e instructor;
- consumo, expiración y pasivo de créditos;
- cohortes por canal/primera actividad;
- salud operativa por sucursal.

Usar eventos/proyecciones; no ejecutar agregados pesados en el request OLTP.

## 11. Control plane SaaS y monetización

### Capacidades

- alta y trial de tenant;
- plan, límites y addons;
- suspensión/read-only/cierre;
- branding, dominio y configuración regional;
- metering de miembros activos, sucursales, staff, mensajes y storage;
- soporte con acceso temporal aprobado/auditado;
- feature flags y rollout gradual.

### Paquetes sugeridos

- **Starter:** una sucursal, reservas/membresías, límites de staff/miembros.
- **Growth:** varias sucursales, waitlist/notificaciones, reportes y widgets.
- **Pro:** automatizaciones, API/webhooks, roles avanzados, analítica y SSO opcional.
- Addons: mensajería, facturación, storage, marketplace, white-label.

Evitar cobrar por transacción si desincentiva registrar pagos; preferir una métrica de valor comprensible con límites transparentes.

## 12. Moat: motor vertical configurable

La oportunidad más defendible es convertir el core común en un motor que modele recursos y políticas específicas sin forks:

```text
Actividad + modalidad + nivel + recurso + capacidad efectiva
+ política de elegibilidad/reserva/crédito/no-show
+ journey familiar o individual
+ métricas/automatizaciones verticales
```

Con configuración versionada y plantillas por vertical, AgendaUno puede lanzar gimnasios, estudios y escuelas acuáticas sobre el mismo producto, acumulando conocimiento operativo. El moat proviene de datos limpios, workflows y configurabilidad, no de clonar pantallas.

## Priorización recomendada

### 0–6 semanas

Seguridad financiera, lifecycle de holds, reembolsos, auditoría, selector tenant, policies por sucursal y observabilidad.

### 6–12 semanas

Portal familiar, notificaciones, políticas configurables, waitlist robusta, check-in instructor y reporting básico.

### 3–6 meses

Suscripciones/dunning, booking público, control plane SaaS, analítica de ocupación/retención.

### 6–12 meses

Sugeridor de horarios, motor vertical avanzado, API/ecosistema e integraciones regionales.

## Métrica norte sugerida

**Visitas completadas y correctamente liquidadas por sede por semana**, acompañada de tasa de ocupación, retención a 8/12 semanas y discrepancias financieras. Une valor para negocio y miembro sin premiar reservas fantasma.
