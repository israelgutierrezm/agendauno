import { describe, expect, it } from "vitest";

import {
  DOMINIO_PUBLICO,
  slugDeContexto,
  slugDeSubdominio,
  urlPublicaEstudio,
} from "./tenant";

describe("tenant / subdominio", () => {
  it("deriva el slug de un subdominio de estudio (prod y dev)", () => {
    expect(slugDeSubdominio(`barberia.${DOMINIO_PUBLICO}`)).toBe("barberia");
    expect(slugDeSubdominio(`barberia.${DOMINIO_PUBLICO}:443`)).toBe(
      "barberia",
    );
    expect(slugDeSubdominio("mi-estudio.localhost")).toBe("mi-estudio");
  });

  it("el dominio raíz y los subdominios reservados no son un estudio", () => {
    expect(slugDeSubdominio(DOMINIO_PUBLICO)).toBeNull();
    expect(slugDeSubdominio(`www.${DOMINIO_PUBLICO}`)).toBeNull();
    expect(slugDeSubdominio(`app.${DOMINIO_PUBLICO}`)).toBeNull();
    expect(slugDeSubdominio("localhost")).toBeNull();
    // Solo un nivel de subdominio (evita hosts anidados inesperados).
    expect(slugDeSubdominio(`a.b.${DOMINIO_PUBLICO}`)).toBeNull();
  });

  it("el contexto admite el subdominio o el fallback dev ?estudio=", () => {
    expect(slugDeContexto(`barberia.${DOMINIO_PUBLICO}`, "")).toBe("barberia");
    // En localhost sin subdominio, ?estudio= simula el estudio en contexto.
    expect(slugDeContexto("localhost", "?estudio=barberia")).toBe("barberia");
    expect(slugDeContexto("localhost", "")).toBeNull();
    // El subdominio real tiene prioridad sobre el query.
    expect(slugDeContexto(`otra.${DOMINIO_PUBLICO}`, "?estudio=barberia")).toBe(
      "otra",
    );
  });

  it("construye la URL pública corta del estudio", () => {
    expect(urlPublicaEstudio("barberia")).toBe(`barberia.${DOMINIO_PUBLICO}`);
  });
});
