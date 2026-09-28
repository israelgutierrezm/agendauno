import { describe, expect, it } from "vitest";

import {
  adaptarMensajes,
  adaptarTexto,
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

  it("con una terminología femenina no cambia «alumno» (el artículo no cuadraría)", () => {
    const pole: TerminosNegocio = {
      sesion: "Clase",
      miembro: "Alumna",
      instructor: "Coach",
    };
    expect(adaptarTexto("Nuevo alumno de la clase", pole)).toBe(
      "Nuevo alumno de la clase",
    );
    expect(adaptarTexto("Instructor", pole)).toBe("Coach");
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
