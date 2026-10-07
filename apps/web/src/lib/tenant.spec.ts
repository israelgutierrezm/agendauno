import { describe, expect, it } from "vitest";

import {
  DOMINIO_PUBLICO,
  origenDominioRaiz,
  slugDeContexto,
  slugDeSubdominio,
  urlEntrarEnDominioRaiz,
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

describe("dominio raíz desde el subdominio de un negocio", () => {
  const prod = {
    protocol: "https:",
    hostname: `barberia.${DOMINIO_PUBLICO}`,
    port: "",
  };
  const dev = {
    protocol: "http:",
    hostname: "barberia.localhost",
    port: "5175",
  };

  it("quita el subdominio y conserva protocolo y puerto", () => {
    expect(origenDominioRaiz(prod)).toBe(`https://${DOMINIO_PUBLICO}`);
    expect(origenDominioRaiz(dev)).toBe("http://localhost:5175");
    // Fuera de un subdominio de negocio, el origen actual.
    expect(
      origenDominioRaiz({
        protocol: "https:",
        hostname: DOMINIO_PUBLICO,
        port: "",
      }),
    ).toBe(`https://${DOMINIO_PUBLICO}`);
  });

  it("arma «Entrar» de ese negocio en el dominio raíz, con la ruta interna a la que volver", () => {
    expect(urlEntrarEnDominioRaiz("barberia", null, prod)).toBe(
      `https://${DOMINIO_PUBLICO}/entrar?estudio=barberia`,
    );
    expect(urlEntrarEnDominioRaiz("barberia", "/mi-perfil", dev)).toBe(
      "http://localhost:5175/entrar?estudio=barberia&volver=%2Fmi-perfil",
    );
    // Solo rutas internas: nunca otro sitio.
    expect(urlEntrarEnDominioRaiz("barberia", "//otro.sitio", prod)).toBe(
      `https://${DOMINIO_PUBLICO}/entrar?estudio=barberia`,
    );
  });
});
