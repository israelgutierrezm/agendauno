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
    tituloMes: "Quién cuenta en {mes}",
    nadie: "Nadie cuenta todavía este mes.",
    sinResultados: "Nadie con ese nombre cuenta este mes.",
    buscar: "Buscar por nombre",
    reglaAlumnos:
      "Cuenta cada alumno con al menos una reserva confirmada en una clase no cancelada o una compra pagada en el mes. Estar registrado no basta.",
    reglaProfesionales:
      "Cuenta cada profesional que atendió al menos una cita no cancelada en el mes, completo sin importar sus horas.",
    cuentanAlumnos: "Cuenta 1 alumno | Cuentan {n} alumnos",
    cuentanProfesionales: "Cuenta 1 profesional | Cuentan {n} profesionales",
    colAlumno: "Alumno",
    colProfesional: "Profesional",
    colReservas: "Clases reservadas",
    colCompras: "Compras pagadas",
    colCitas: "Citas atendidas",
  },
  historial: {
    uso: "Uso",
  },
  // El pago pendiente arriba del panel, para quien ve la facturación (ADR 0152).
  aviso: {
    porVencer: "Tu pago de {monto} vence el {vence}",
    porVencerTexto: "Págalo a tiempo para seguir sin interrupciones.",
    rechazado: "No pudimos cobrar {monto} a tu tarjeta",
    rechazadoTexto:
      "El banco rechazó el cobro. Págalo con otra tarjeta o cámbiala en Mi suscripción.",
    rechazadoTextoReintento:
      "El banco rechazó el cobro. Lo intentaremos de nuevo el {reintento}, o págalo ahora con otra tarjeta.",
    vencido: "Tu pago de {monto} venció el {vence}",
    vencidoTexto:
      "Si no se paga, tu negocio se suspenderá el {suspende} (mañana) y nadie podrá agendar ni entrar al panel. | Si no se paga, tu negocio se suspenderá el {suspende} (en {dias} días) y nadie podrá agendar ni entrar al panel.",
    vencidoSinSuspension: "Págalo para mantener tu suscripción al corriente.",
    porSuspender: "Tu negocio se suspenderá el {suspende}",
    porSuspenderHoy: "Tu negocio se suspenderá hoy",
    porSuspenderTexto:
      "Tu pago de {monto} está pendiente desde el {vence}. Suspendido, nadie podrá agendar ni entrar al panel hasta que se pague; tu información queda a salvo.",
    pagar: "Pagar ahora",
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
    // Con su producto (ADR 0132); la marca va como valor, no en el texto.
    clases: "{marca} · clases, por alumnos activos",
    citas: "{marca} · citas, por plan, nivel y profesionales",
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
    incompleta:
      "Completa los campos marcados: un precio vacío no se publica como 0.",
    publicada: "Se publicó la versión {n}.",
    confirmarPublicar:
      "¿Publicar una versión nueva de la tarifa de {modalidad}? No se puede editar ni deshacer: los cobros siguientes y la landing usarán estos precios.",
  },
  modalidad: {
    clases: "Clases",
    citas: "Citas",
  },
};
