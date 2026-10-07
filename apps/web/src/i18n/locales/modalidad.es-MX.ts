// Modalidad del negocio (ADR 0104): solo clases o solo citas. Habla de clases y citas
// en general, así que no se adapta a la terminología del negocio.
export default {
  nombres: {
    clases: "Clases con cupo",
    citas: "Citas 1 a 1",
  },
  // Al registrarse el giro da la modalidad. Que después solo la cambia el superadmin,
  // y solo mientras el negocio no tenga sesiones ni reservas (ADR 0104), se explica en
  // las preguntas frecuentes de la portada, /clases y /citas.
  registro: "Con el tipo de negocio eliges tu modalidad, clases o citas.",
  // Registro desde /clases o /citas (`?modo=`) o desde un giro (`?giro=`): solo se ven
  // los giros de esa modalidad, y este enlace muestra los de la otra (`otra.{modo}`)
  // sin perder lo escrito.
  registroModo: {
    otra: {
      clases: { pregunta: "¿Das clases?", enlace: "Ver giros de clases" },
      citas: { pregunta: "¿Atiendes con cita?", enlace: "Ver giros de citas" },
    },
  },
  // Paso 3 del registro: lo que se va a crear, antes de confirmar. Quién cambia la
  // modalidad después va en las preguntas frecuentes, no en el registro.
  resumen: {
    etiqueta: "Tipo de negocio · Modalidad",
    ayuda: "Revísalo antes de crear tu negocio.",
    cambiar: "Cambiar",
    cambiarEtiqueta: "Cambiar el tipo de negocio",
  },
  // Tipo de negocio (giro): Configuración y configuración inicial.
  giro: {
    titulo: "Tipo de negocio",
    etiqueta: "Tipo de negocio",
    ayuda: {
      clases:
        "Tu negocio trabaja con clases. Puedes elegir otro tipo de negocio con clases: cambia cómo se llaman las cosas. Pasar a citas solo lo hace AgendaUno, y solo mientras no tengas clases ni reservas.",
      citas:
        "Tu negocio trabaja con citas. Puedes elegir otro tipo de negocio con citas: cambia cómo se llaman las cosas. Pasar a clases solo lo hace AgendaUno, y solo mientras no tengas citas ni reservas.",
    },
    guardar: "Guardar",
    guardado: "Guardado",
  },
  // Ficha del negocio en el súper admin.
  plataforma: {
    etiqueta: "Modalidad",
    cambiar: "Cambiar a {modalidad}",
    confirmar:
      "¿Cambiar {estudio} a {modalidad}? Cambian su agenda, su menú, lo que ofrece su página y cómo se le cobra la renta. Su tipo de negocio pasa a uno de esa modalidad; el negocio lo ajusta en Configuración.",
    aceptar: "Cambiar modalidad",
    cambiada: "Modalidad cambiada.",
    ayuda:
      "Aún no tiene sesiones ni reservas: su modalidad todavía se puede cambiar.",
    yaOpera:
      "Ya tiene sesiones o reservas: su modalidad ya no se puede cambiar.",
  },
};
