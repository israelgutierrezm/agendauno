/**
 * Textos del cobro del SaaS por modalidad (renta del dueño y tarifas del
 * superadmin). Se montan bajo `cobro` en el locale es-MX.
 */
export default {
  modo: {
    titulo: "Cómo te cobramos",
    clases: "Por alumnos activos",
    citas: "Por profesionales activos",
    fijo: "Cuota fija mensual",
    ayudaClases:
      "Cuenta a quien reservó una clase o compró algo en el mes. Quien no vino ni pagó, no cuenta.",
    ayudaCitas:
      "Cuenta a cada profesional que atendió al menos una cita en el mes.",
    ayudaFijo: "Monto acordado con AgendaUno: {monto} al mes, IVA incluido.",
    pruebaHasta: "Prueba gratis hasta el {fecha}: esos días no se cobran.",
    mesVencido: "Se cobra al cerrar el mes, con lo que realmente usaste.",
  },
  actual: {
    titulo: "Mes en curso",
    siguiente: "Siguiente cobro",
    estimado: "Estimado con IVA",
    alumnos_activos: "alumnos activos",
    profesionales_activos: "profesionales activos",
    profesionales_contratados: "profesionales contratados",
    timbres: "timbres",
    fueraDeCita: "{n} personas en clases o talleres",
    sinCargo: "Sin cargo este mes por ahora.",
  },
  desglose: {
    subtotal: "Subtotal",
    iva: "IVA ({pct}%)",
    total: "Total",
    prorrateo: "Solo {dias} de {total} días (el resto fue prueba gratis).",
    prorrateoPlan:
      "Solo {dias} de {total} días: el primer mes se cobra desde que termina tu prueba.",
    ver: "Ver detalle",
    ocultar: "Ocultar detalle",
  },
  quien: {
    ver: "Ver quién cuenta",
    ocultar: "Ocultar",
    titulo: "Quién cuenta este mes",
    nadie: "Nadie cuenta todavía este mes.",
    sesiones: "{n} citas o clases",
  },
  historial: {
    uso: "Uso",
  },
  estados: {
    pendiente: "Por pagar",
    pagado: "Pagado",
    sin_cargo: "Sin cargo",
    cancelado: "Cancelado",
  },
  tarifas: {
    titulo: "Tarifas del SaaS",
    ayuda:
      "Precios mensuales sin IVA. Publicar crea una versión nueva: los cargos ya generados conservan la suya.",
    version: "Versión {n} · vigente desde {fecha}",
    clases: "Clases (por alumnos activos)",
    citas: "Citas (por plan, nivel y profesionales)",
    hasta: "Hasta",
    sinTope: "Sin tope (techo)",
    monto: "Precio mensual",
    unitario: "Precio por profesional",
    agregar: "Agregar escalón",
    quitar: "Quitar",
    diasPrueba: "Días de prueba",
    iva: "IVA %",
    incluidas: "Personas incluidas por profesional",
    tope: "Tope de personas incluidas",
    extra: "Precio por persona adicional",
    publicar: "Publicar versión nueva",
    publicando: "Publicando…",
    publicada: "Se publicó la versión {n}.",
    confirmarPublicar:
      "¿Publicar una versión nueva de la tarifa de {modalidad}? No se puede editar ni deshacer: los cobros siguientes y la landing usarán estos precios.",
  },
  modalidad: {
    clases: "Clases",
    citas: "Citas",
  },
};
