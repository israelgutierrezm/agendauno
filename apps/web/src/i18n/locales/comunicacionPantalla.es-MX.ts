/**
 * Comunicación: qué es cada pestaña y cómo se llama cada aviso del negocio. Una
 * difusión (la escribes y se envía una vez), un mensaje automático (una plantilla que
 * se envía sola con un evento) y la bandeja de salida (lo que ya salió) son cosas
 * distintas; las reglas que crean tareas están en Tareas › Automatización.
 */
export default {
  pestanas: {
    difusion: {
      titulo: "Difusiones",
      ayuda:
        "Un mensaje que escribes tú y se envía una sola vez a un grupo de alumnos.",
    },
    automaticos: {
      titulo: "Mensajes automáticos",
      ayuda:
        "Plantillas que se envían solas cuando pasa algo en el negocio: una reserva, un pago, un recordatorio. Aquí eliges a quién le llega cada aviso, por qué canal y con qué texto.",
    },
    whatsapp: {
      titulo: "Plantillas de WhatsApp",
      ayuda:
        "Tus propios textos para campañas por WhatsApp. Meta los revisa antes de que se puedan usar.",
    },
    salida: {
      titulo: "Bandeja de salida",
      ayuda:
        "Lo que ya salió o está por salir, de las difusiones y de los mensajes automáticos, con su estado.",
    },
  },
  // Las reglas de Tareas no envían mensajes: crean tareas para el equipo.
  // Campañas por WhatsApp con una plantilla aprobada (ADR 0190).
  whatsapp: {
    plantilla: "Plantilla de WhatsApp",
    elegir: "Elige una plantilla aprobada",
    sinAprobadas:
      "Aún no tienes plantillas aprobadas por Meta. Escríbelas en la pestaña Plantillas de WhatsApp.",
    sinWhatsApp:
      "{n} no aceptó tus avisos por WhatsApp o no tiene celular | {n} no aceptaron tus avisos por WhatsApp o no tienen celular",
    gasto: "Gasta {mensajes} mensajes de tu saldo de WhatsApp ({costo} + IVA).",
    gastoSinCosto: "Gasta {mensajes} mensajes de tu saldo de WhatsApp.",
  },
  reglasTareas:
    "¿Quieres que se cree una tarea para el equipo cuando pase algo? Eso se configura en Tareas › Automatización.",
  irATareas: "Ir a Tareas",
  errorAutomaticos: "No se pudieron cargar los mensajes automáticos.",
  // Un mensaje configurado pero apagado (no se envía).
  apagado: "apagado",
  // Para ordenar la lista de avisos.
  areas: {
    reservas: "Reservas y asistencia",
    pagos: "Pagos y membresías",
    cuenta: "Cuenta, privacidad y reseñas",
    prospectos: "Prospectos",
    otros: "Otros avisos",
  },
  // Cómo se llama cada aviso: el evento del negocio que lo dispara
  // (EventoDeDominioTenant::TIPOS). Anidados: «reserva.creada» se usa tal cual.
  eventos: {
    reserva: {
      creada: "Nueva reserva",
      apartada: "Lugar apartado, pendiente de pago",
      confirmada: "Reserva confirmada",
      ofrecida: "Se liberó un lugar (lista de espera)",
      recordatorio_24h: "Recordatorio un día antes de la clase",
      recordatorio_2h: "Recordatorio dos horas antes de la clase",
      cancelada: "Reserva cancelada",
      sesion_cancelada: "El negocio canceló la clase",
      reprogramada: "Reserva cambiada de horario",
    },
    asistencia: { marcada: "Asistencia registrada" },
    acceso: { registrado: "Entrada registrada" },
    orden: { pagada: "Pago recibido (recibo)" },
    pago: {
      reembolsado: "Reembolso hecho",
      anulado: "Cobro anulado",
      tardio: "Pago después de que venció el apartado",
      duplicado: "Cobro doble detectado",
    },
    cobro: { fallido: "No se pudo hacer el cobro automático" },
    membresia: {
      suspendida: "Membresía suspendida por falta de pago",
      regularizada: "Membresía al corriente de nuevo",
      pausada: "Membresía en pausa",
      reanudada: "Membresía reanudada",
      renovacion_proxima: "Renovación próxima",
    },
    factura: { timbrada: "Factura emitida" },
    cuenta: { creada: "Bienvenida (cuenta creada)" },
    privacidad: { baja_solicitada: "Solicitud de baja de datos" },
    resena: { creada: "Reseña recibida" },
    pago_automatico: { solicitado: "Invitación al pago automático" },
    prospecto: {
      creado: "Prospecto nuevo (dejó sus datos)",
      convertido: "Prospecto que pasó a ser alumno",
      sin_atender: "Prospecto sin atender",
    },
    bandeja: { mensaje_recibido: "Mensaje nuevo en la bandeja" },
  },
};
