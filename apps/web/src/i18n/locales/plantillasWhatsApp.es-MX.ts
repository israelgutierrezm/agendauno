// Las plantillas de WhatsApp del negocio para sus campañas (ADR 0190).
export default {
  titulo: "Plantillas de WhatsApp",
  ayuda:
    "Para mandar campañas por WhatsApp. Meta revisa cada plantilla antes de que se pueda usar (suele tardar minutos, a veces un día). Solo llegan a quien aceptó tus avisos por WhatsApp.",
  nueva: "Nueva plantilla",
  editarTitulo: "Editar plantilla",
  nombre: "Nombre (solo lo ves tú)",
  categoriaLabel: "Tipo de mensaje",
  categoria: {
    utilidad: "Utilidad",
    marketing: "Marketing",
  },
  categoriaAyuda: {
    utilidad:
      "Avisos sobre algo que el alumno ya tiene: un cambio de horario, una clase cancelada. Si es una promoción, Meta la pasa a marketing.",
    marketing:
      "Promociones, descuentos, eventos o invitaciones a volver. Meta los cobra más caros.",
  },
  mensajes: "gasta {n} mensaje de tu saldo | gasta {n} mensajes de tu saldo",
  costo:
    "gasta {n} mensaje de tu saldo ({precio} + IVA) | gasta {n} mensajes de tu saldo ({precio} + IVA)",
  sinCosto: "Por ahora la plataforma paga los mensajes de WhatsApp.",
  noDisponible:
    "WhatsApp no está activo para tu negocio o la plataforma aún no puede mandar plantillas a revisión.",
  texto: "Texto",
  marcadores:
    "Usa {nombre} para el nombre de quien lo recibe y {negocio} (obligatorio) para el de tu negocio. Ninguno puede abrir ni cerrar el texto, y cada uno va una sola vez",
  pie: "Pie (opcional)",
  piePh: "Responde BAJA para no recibir más",
  guardar: "Guardar borrador",
  vacio: "Aún no tienes plantillas.",
  estado: {
    borrador: "Borrador",
    en_revision: "En revisión de Meta",
    aprobada: "Aprobada",
    rechazada: "Rechazada",
    pausada: "Pausada",
    archivada: "Archivada",
  },
  motivo: "Lo que dijo Meta: {motivo}",
  editar: "Editar",
  enviar: "Mandar a revisión",
  revisar: "Ver si ya la aprobaron",
  archivar: "Archivar plantilla",
  archivarConfirmar:
    "La plantilla ya no se podrá usar en campañas y se borra de Meta.",
};
