import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

import { bloquesLegales } from "./documentoLegal";

describe("documento legal en bloques", () => {
  it("títulos numerados, párrafos y listas; el encabezado se omite", () => {
    const bloques = bloquesLegales(
      "AVISO DE PRIVACIDAD\n\n1. Quiénes somos\n\nTexto del responsable.\n\nDe quien registra:\n• Nombre y correo.\n• Teléfono\ncon WhatsApp.\n\nSEGUNDA PARTE: CLIENTES\n\n2. Datos",
    );
    expect(bloques).toEqual([
      { tipo: "titulo", texto: "1. Quiénes somos" },
      { tipo: "parrafo", texto: "Texto del responsable." },
      { tipo: "parrafo", texto: "De quien registra:" },
      {
        tipo: "lista",
        elementos: ["Nombre y correo.", "Teléfono con WhatsApp."],
      },
      { tipo: "parte", texto: "SEGUNDA PARTE: CLIENTES" },
      { tipo: "titulo", texto: "2. Datos" },
    ]);
  });

  it("el borrador de desarrollo es el mismo texto base del servidor", () => {
    for (const archivo of ["aviso-privacidad.txt", "terminos.txt"]) {
      const web = readFileSync(
        resolve(__dirname, "../marketing/legales", archivo),
        "utf8",
      );
      const api = readFileSync(
        resolve(__dirname, "../../../api/resources/legales", archivo),
        "utf8",
      );
      expect(web, archivo).toBe(api);
    }
  });
});
