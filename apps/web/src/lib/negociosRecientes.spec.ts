import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  leerNegociosRecientes,
  olvidarNegocio,
  recordarNegocio,
} from "./negociosRecientes";

describe("negocios recientes", () => {
  beforeEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
  });

  it("recuerda el más reciente primero sin duplicar tenants", () => {
    vi.spyOn(Date, "now").mockReturnValueOnce(10).mockReturnValueOnce(20);

    recordarNegocio({
      slug: "pilates-centro",
      nombre: "Pilates Centro",
      logo_url: null,
      ciudad: "Puebla",
      pais: "México",
    });
    recordarNegocio({
      slug: "pilates-centro",
      nombre: "Pilates Centro Renovado",
      logo_url: "/logo.webp",
      ciudad: "Puebla",
      pais: "México",
    });

    expect(leerNegociosRecientes()).toEqual([
      expect.objectContaining({
        slug: "pilates-centro",
        nombre: "Pilates Centro Renovado",
        visitado_en: 20,
      }),
    ]);
  });

  it("limita la memoria pública a cinco negocios", () => {
    for (let i = 0; i < 7; i += 1) {
      recordarNegocio({
        slug: `negocio-${i}`,
        nombre: `Negocio ${i}`,
        logo_url: null,
        ciudad: null,
        pais: null,
      });
    }

    const recientes = leerNegociosRecientes();
    expect(recientes).toHaveLength(5);
    expect(recientes[0]?.slug).toBe("negocio-6");
    expect(recientes.some((item) => item.slug === "negocio-0")).toBe(false);
  });

  it("permite olvidar un acceso sin afectar los demás", () => {
    recordarNegocio({
      slug: "uno",
      nombre: "Uno",
      logo_url: null,
      ciudad: null,
      pais: null,
    });
    recordarNegocio({
      slug: "dos",
      nombre: "Dos",
      logo_url: null,
      ciudad: null,
      pais: null,
    });

    expect(olvidarNegocio("uno").map((item) => item.slug)).toEqual(["dos"]);
  });
});
