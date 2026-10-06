import { describe, expect, it } from "vitest";

import {
  adaptarMensajes,
  adaptarTexto,
  terminoParaPersona,
  plural,
  type TerminosNegocio,
} from "./terminologia";

describe("terminologia / plural", () => {
  it("pluraliza los términos de los perfiles", () => {
    expect(plural("Barbero")).toBe("Barberos");
    expect(plural("Cliente")).toBe("Clientes");
    expect(plural("Alumna")).toBe("Alumnas");
    expect(plural("Terapeuta")).toBe("Terapeutas");
    expect(plural("Profesional")).toBe("Profesionales");
    expect(plural("Instructor")).toBe("Instructores");
    expect(plural("Coach")).toBe("Coaches");
  });

  it("respeta la z y el texto vacío", () => {
    expect(plural("Voz")).toBe("Voces");
    expect(plural("  ")).toBe("");
  });
});

const barberia: TerminosNegocio = {
  sesion: "Cita",
  sesiones: "Citas",
  miembro: "Cliente",
  miembros: "Clientes",
  instructor: "Barbero",
  instructores: "Barberos",
};

describe("terminologia / adaptarTexto", () => {
  it("cambia clases, alumnos e instructores respetando mayúsculas y plural", () => {
    expect(adaptarTexto("Próximas clases", barberia)).toBe("Próximas citas");
    expect(adaptarTexto("Clase", barberia)).toBe("Cita");
    expect(adaptarTexto("CLASES DEL DÍA", barberia)).toBe("CITAS DEL DÍA");
    expect(adaptarTexto("Agrega a tu primer alumno", barberia)).toBe(
      "Agrega a tu primer cliente",
    );
    expect(adaptarTexto("Costo instructor", barberia)).toBe("Costo barbero");
    expect(adaptarTexto("Instructores", barberia)).toBe("Barberos");
  });

  it("no toca marcadores, palabras que solo se parecen ni al personal", () => {
    expect(adaptarTexto("{clase} a las {hora}", barberia)).toBe(
      "{clase} a las {hora}",
    );
    expect(adaptarTexto("Clasificación y subclases", barberia)).toBe(
      "Clasificación y subclases",
    );
    expect(adaptarTexto("Miembro del equipo", barberia)).toBe(
      "Miembro del equipo",
    );
    expect(adaptarTexto("Nuevo miembro", barberia)).toBe("Nuevo cliente");
  });

  it("no repite: «clases o citas» queda «citas»", () => {
    expect(adaptarTexto("Ya hay {n} citas o clases ahí.", barberia)).toBe(
      "Ya hay {n} citas ahí.",
    );
    expect(adaptarTexto("Clase o cita cancelada", barberia)).toBe(
      "Cita cancelada",
    );
    expect(adaptarTexto("poco a poco y más y más", barberia)).toBe(
      "poco a poco y más y más",
    );
  });

  it("con un término femenino concuerdan el artículo y los adjetivos", () => {
    const pole: TerminosNegocio = {
      sesion: "Clase",
      miembro: "Alumna",
      instructor: "Coach",
    };
    expect(adaptarTexto("Nuevo alumno de la clase", pole)).toBe(
      "Nueva alumna de la clase",
    );
    expect(adaptarTexto("Alumnos esperados hoy", pole)).toBe(
      "Alumnas esperadas hoy",
    );
    expect(adaptarTexto("Lo que compran todos los alumnos activos", pole)).toBe(
      "Lo que compran todas las alumnas activas",
    );
    expect(adaptarTexto("Vender a un alumno", pole)).toBe(
      "Vender a una alumna",
    );
    expect(adaptarTexto("La ficha del alumno", pole)).toBe(
      "La ficha de la alumna",
    );
    expect(adaptarTexto("Miembro del equipo", pole)).toBe("Miembro del equipo");
    expect(adaptarTexto("{n} alumnos", pole)).toBe("{n} alumnas");
    expect(adaptarTexto("Instructor", pole)).toBe("Coach");
    const gym: TerminosNegocio = {
      sesion: "Clase",
      miembro: "Socia",
      instructor: "Instructor",
    };
    expect(adaptarTexto("La alumna reservó", gym)).toBe("La socia reservó");
    expect(adaptarTexto("Los alumnos nuevos", gym)).toBe("Las socias nuevas");
  });

  it("«alumna» solo pasa a términos de género común", () => {
    expect(adaptarTexto("La alumna reservó", barberia)).toBe(
      "La cliente reservó",
    );
    const gym: TerminosNegocio = {
      sesion: "Clase",
      miembro: "Socio",
      instructor: "Instructor",
    };
    expect(adaptarTexto("La alumna reservó", gym)).toBe("La alumna reservó");
    expect(adaptarTexto("Nuevo alumno", gym)).toBe("Nuevo socio");
  });

  it("adapta un árbol de mensajes salvo las secciones excluidas", () => {
    const mensajes = {
      agenda: { titulo: "Clases de hoy", lista: ["Una clase"] },
      landing: { titulo: "Para clases y citas" },
    };
    expect(adaptarMensajes(mensajes, barberia, new Set(["landing"]))).toEqual({
      agenda: { titulo: "Citas de hoy", lista: ["Una cita"] },
      landing: { titulo: "Para clases y citas" },
    });
  });
});

describe("terminologia / terminoParaPersona", () => {
  it("nombra a cada persona en su género cuando el término cambia", () => {
    expect(terminoParaPersona("Alumno", "mujer")).toBe("Alumna");
    expect(terminoParaPersona("Alumna", "hombre")).toBe("Alumno");
    expect(terminoParaPersona("Socio", "mujer")).toBe("Socia");
    expect(terminoParaPersona("Alumna", null)).toBe("Alumna");
    expect(terminoParaPersona("Alumna", "no_binario")).toBe("Alumna");
    expect(terminoParaPersona("Cliente", "mujer")).toBe("Cliente");
    expect(terminoParaPersona("Paciente", "hombre")).toBe("Paciente");
  });
});
