import { describe, expect, it } from "vitest";

import { plural } from "./terminologia";

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
