// La demo de 15 minutos agendable desde la landing (ADR 0193).
export const demosPlataforma = {
  titulo: "Demos agendadas",
  ayuda:
    "Las que se agendaron desde la landing, las próximas primero. Los horarios que se ofrecen se fijan en Parámetros → Demos.",
  vacio: "Aún no hay demos agendadas.",
  cuando: "Cuándo",
  quien: "Quién",
  contacto: "Contacto",
  estado: "Estado",
  estados: {
    agendada: "Agendada",
    atendida: "Atendida",
    no_asistio: "No contestó",
    cancelada: "Cancelada",
  },
  atendida: "Atendida",
  noAsistio: "No contestó",
  cancelar: "Cancelar",
};

export default {
  titulo: "Agenda una demo de {minutos} minutos",
  texto:
    "Te llamamos a la hora que elijas y te mostramos el sistema con tu negocio en mente.",
  dia: "Día",
  hora: "Hora",
  zona: "Hora del centro de México.",
  nombre: "Tu nombre",
  negocio: "Nombre de tu negocio",
  correo: "Correo",
  telefono: "Teléfono (10 dígitos)",
  giro: "Tipo de negocio (opcional)",
  whatsapp: "Confírmame la demo por WhatsApp a este número.",
  agendar: "Agendar demo",
  agendando: "Agendando…",
  listo: "¡Listo! Tu demo quedó agendada",
  listoTexto:
    "{cuando} (hora del centro de México). Te mandamos la confirmación a tu correo, con el enlace para cancelarla si no puedes, y te la recordamos antes por correo.",
  cancelar: {
    cancelando: "Cancelando tu demo…",
    cancelada: "Cancelamos tu demo",
    canceladaTexto: "Cuando quieras, agenda otra desde nuestra página.",
    error: "No pudimos cancelar la demo",
    errorTexto:
      "El enlace no es válido o la demo ya no estaba agendada. Si necesitas ayuda, escríbenos.",
    volver: "Ir al inicio",
  },
};
