# Alcance de la V1

Lo que la V1 incluye y lo que queda fuera. El estado de cada pieza está en los
ADRs; la verificación antes de abrir, en `docs/VERIFICACION-V1.md`.

## Incluye

Plataforma:
- registro público de negocios con prueba, directorio y subdominio por negocio;
- una base de datos por negocio, respaldos y simulacro de restauración;
- superadmin: negocios, tarifas, cobros del SaaS, parámetros, documentos legales;
- cobro del SaaS por alumnos o profesionales activos.

Negocio:
- onboarding guiado, sucursales, organizaciones, horario de atención;
- equipo con roles de sistema y roles propios, rol activo por sesión, alcance por
  sucursal y por profesional;
- alumnos y clientes: ficha, expediente, documentos, formularios, responsivas;
- catálogo de clases y servicios, recursos, agenda con series, bloqueos y
  reprogramación;
- membresías, paquetes, pases y extras con ledger de créditos;
- reservas con lista de espera, cancelación, tolerancia de inasistencias, pase de
  lista, check-in y QR;
- ventas, órdenes, pagos en línea (Stripe, Mercado Pago, OpenPay), efectivo y
  depósito, pago automático, reembolsos, conciliación, corte de caja;
- punto de venta e inventario, promociones, lealtad;
- comunicaciones: correos transaccionales, recordatorios, difusiones, push;
- reportes, bitácora, parámetros configurables, terminología por negocio;
- API de integración de solo lectura y webhooks salientes.

Alumno o cliente:
- portal web y app: reservar, agendar, comprar, pagar, reprogramar, pase QR,
  documentos, privacidad (ARCO), calendario iCal, reseñas.

## Fuera de la V1

- marketplace con compras entre negocios;
- IA;
- acceso biométrico o torniquetes;
- nómina completa;
- constructor de reportes a la medida;
- apps de marca blanca por negocio;
- modo sin conexión en la app;
- familias y tutores (ADR 0059).
