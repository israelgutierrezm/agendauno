import { afterEach, describe, expect, it, vi } from "vitest";

import {
  claveZona,
  etiquetaZona,
  normalizarZona,
  opcionesPais,
  opcionesZona,
  paisDeZona,
  paisSugerido,
  todasLasZonas,
  ZONAS_HORARIAS,
  zonasConActual,
  zonasDelPais,
  zonaSugerida,
} from "./region";

/*
| Zonas horarias y país del negocio (ADR 0099 y 0103): todas las zonas, con los
| nombres que acepta el API (IANA) y las del país del negocio primero; y el país y la
| zona que se proponen al registrar un negocio, a partir del navegador.
*/

// El navegador «está» en una zona y habla un idioma.
function navegadorEn(zona: string, idiomas: string[] = ["es-MX"]): void {
  vi.spyOn(Intl.DateTimeFormat.prototype, "resolvedOptions").mockReturnValue({
    timeZone: zona,
  } as Intl.ResolvedDateTimeFormatOptions);
  vi.spyOn(navigator, "languages", "get").mockReturnValue(idiomas);
}

afterEach(() => {
  vi.restoreAllMocks();
});

describe("todas las zonas", () => {
  it("ofrece todas, con las de siempre primero y los nombres de IANA", () => {
    const zonas = todasLasZonas();
    expect(zonas.length).toBeGreaterThan(300);
    expect(zonas[0]).toBe("America/Mexico_City");
    expect(zonas).toContain("America/Chicago");
    expect(zonas).toContain("America/Sao_Paulo");
    expect(zonas).toContain("America/Argentina/Buenos_Aires");
    expect(zonas).not.toContain("America/Buenos_Aires");
    expect(zonas).toContain("Asia/Kolkata");
    expect(ZONAS_HORARIAS).toEqual(zonas);
  });

  it("conserva una zona que ya tenía aunque no esté en la lista", () => {
    expect(zonasConActual("Etc/GMT+6")).toContain("Etc/GMT+6");
    expect(zonasConActual("America/Bogota")).toEqual(todasLasZonas());
  });

  it("traduce los nombres viejos del navegador a los de IANA", () => {
    expect(normalizarZona("America/Buenos_Aires")).toBe(
      "America/Argentina/Buenos_Aires",
    );
    expect(normalizarZona("Asia/Calcutta")).toBe("Asia/Kolkata");
    expect(normalizarZona("America/Bogota")).toBe("America/Bogota");
  });

  it("sabe de qué país es cada zona y cuáles tiene cada país", () => {
    expect(paisDeZona("America/Bogota")).toBe("CO");
    expect(paisDeZona("America/Buenos_Aires")).toBe("AR");
    expect(paisDeZona("Europe/Madrid")).toBe("ES");
    expect(zonasDelPais("CO")).toEqual(["America/Bogota"]);
    // Las de siempre primero (la del centro de México, la primera).
    expect(zonasDelPais("MX")[0]).toBe("America/Mexico_City");
    expect(zonasDelPais("MX")).toContain("America/Ciudad_Juarez");
    // Sin frecuentes, la principal del país (no la primera por nombre).
    expect(zonasDelPais("AU")[0]).toBe("Australia/Sydney");
    expect(zonasDelPais("PT")[0]).toBe("Europe/Lisbon");
  });

  it("nombra las zonas: las conocidas por su nombre y las demás «País (Ciudad)»", () => {
    expect(etiquetaZona("America/Mexico_City")).toBe(
      "Centro de México (Ciudad de México)",
    );
    expect(etiquetaZona("America/Chicago")).toBe("Estados Unidos (Chicago)");
    expect(etiquetaZona("America/Montevideo")).toBe("Uruguay (Montevideo)");
    expect(claveZona("America/Argentina/Buenos_Aires")).toBe(
      "America__Argentina__Buenos_Aires",
    );
  });

  it("para elegir, pone primero las del país del negocio con su desfase", () => {
    const opciones = opcionesZona("US", "America/Chicago");
    const deEstados = opciones.filter((o) => o.grupo === "De Estados Unidos");
    expect(deEstados.length).toBeGreaterThan(10);
    expect(opciones[0].valor).toBe("America/New_York");
    expect(opciones.slice(0, deEstados.length)).toEqual(deEstados);
    const chicago = opciones.find((o) => o.valor === "America/Chicago");
    expect(chicago?.detalle).toMatch(/^UTC-[56]$/);
    // Cada zona, una vez.
    expect(new Set(opciones.map((o) => o.valor)).size).toBe(opciones.length);
  });

  it("los países para elegir llevan su lada y los más usados primero", () => {
    const paises = opcionesPais();
    expect(paises[0]).toMatchObject({
      valor: "MX",
      etiqueta: "México",
      detalle: "+52",
      grupo: "Más usados",
    });
    expect(paises.find((p) => p.valor === "BR")).toMatchObject({
      detalle: "+55",
      grupo: "Todos los países",
    });
  });
});

describe("país y zona al registrar un negocio", () => {
  it("propone el país de la zona del navegador", () => {
    navegadorEn("America/Bogota", ["en-US"]);
    expect(paisSugerido()).toBe("CO");
  });

  it("sin zona útil, el de su idioma; si no, México", () => {
    navegadorEn("UTC", ["es-CL", "es"]);
    expect(paisSugerido()).toBe("CL");
    navegadorEn("UTC", ["es", "es-419"]);
    expect(paisSugerido()).toBe("MX");
  });

  it("la zona: la del navegador si es del país; si no, la primera del país", () => {
    navegadorEn("America/Buenos_Aires");
    expect(zonaSugerida("AR")).toBe("America/Argentina/Buenos_Aires");
    expect(zonaSugerida("CO")).toBe("America/Bogota");
    // Un país con varias zonas y el navegador en otro: nunca la de otro país.
    expect(zonaSugerida("US")).toBe("America/New_York");
    navegadorEn("America/Los_Angeles");
    expect(zonaSugerida("MX")).toBe("America/Mexico_City");
    expect(zonaSugerida("US")).toBe("America/Los_Angeles");
    // Sin zona en el navegador, también la primera del país.
    navegadorEn("UTC");
    expect(zonaSugerida("ES")).toBe("Europe/Madrid");
    expect(zonaSugerida("AU")).toBe("Australia/Sydney");
    expect(zonaSugerida("BR")).toBe("America/Sao_Paulo");
  });
});
