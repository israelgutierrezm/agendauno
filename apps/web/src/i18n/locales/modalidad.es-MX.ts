// Modalidad del negocio (ADR 0104): solo clases o solo citas. Habla de clases y citas
// en general, así que no se adapta a la terminología del negocio.
export default {
  nombres: {
    clases: "Clases con cupo",
    citas: "Citas 1 a 1",
  },
  // Al registrarse: el giro elige si trabaja con clases o con citas.
  registro:
    "Con él eliges si trabajas con clases o con citas; después, cambiar entre ellas lo hace AgendaUno.",
  // Tipo de negocio (giro): Configuración y configuración inicial.
  giro: {
    titulo: "Tipo de negocio",
    etiqueta: "Tipo de negocio",
    ayuda: {
      clases:
        "Tu negocio trabaja con clases. Puedes elegir otro tipo de negocio con clases: cambia cómo se llaman las cosas. Pasar a citas lo hace AgendaUno.",
      citas:
        "Tu negocio trabaja con citas. Puedes elegir otro tipo de negocio con citas: cambia cómo se llaman las cosas. Pasar a clases lo hace AgendaUno.",
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
