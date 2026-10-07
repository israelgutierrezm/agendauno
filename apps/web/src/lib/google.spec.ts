import { afterEach, describe, expect, it, vi } from "vitest";

import { DOMINIO_PUBLICO } from "./tenant";
import {
  googleEnDominioRaiz,
  googleEnEsteSitio,
  renderizarBotonGoogle,
} from "./google";

/*
| Google solo acepta los orígenes registrados uno por uno (sin comodines como
| *.agendauno.mx): el botón vive en el dominio raíz y en el subdominio de un negocio
| se manda allá.
*/

// El host «actual» de la página (jsdom no deja cambiar window.location).
const pagina = vi.hoisted(() => ({ host: "localhost" }));
vi.mock("./tenant", async (importOriginal) => {
  const real = await importOriginal<typeof import("./tenant")>();
  return {
    ...real,
    enSubdominioDeEstudio: (host?: string) =>
      real.enSubdominioDeEstudio(host ?? pagina.host),
  };
});

afterEach(() => {
  vi.unstubAllEnvs();
  pagina.host = "localhost";
  document.head
    .querySelectorAll("script[src*='accounts.google.com']")
    .forEach((s) => s.remove());
});

describe("Google por dominio", () => {
  it("sin Client ID no se ofrece en ningún lado", () => {
    vi.stubEnv("VITE_GOOGLE_CLIENT_ID", "");
    expect(googleEnEsteSitio(DOMINIO_PUBLICO)).toBe(false);
    expect(googleEnDominioRaiz(`barberia.${DOMINIO_PUBLICO}`)).toBe(false);
  });

  it("con Client ID, el botón solo en el dominio raíz", () => {
    vi.stubEnv("VITE_GOOGLE_CLIENT_ID", "cliente.apps.googleusercontent.com");
    expect(googleEnEsteSitio(DOMINIO_PUBLICO)).toBe(true);
    expect(googleEnEsteSitio("localhost")).toBe(true);
    expect(googleEnDominioRaiz(DOMINIO_PUBLICO)).toBe(false);

    expect(googleEnEsteSitio(`barberia.${DOMINIO_PUBLICO}`)).toBe(false);
    expect(googleEnEsteSitio("barberia.localhost")).toBe(false);
    expect(googleEnDominioRaiz(`barberia.${DOMINIO_PUBLICO}`)).toBe(true);
  });

  it("no carga Google en el subdominio de un negocio", async () => {
    vi.stubEnv("VITE_GOOGLE_CLIENT_ID", "cliente.apps.googleusercontent.com");
    pagina.host = "barberia.localhost";

    await renderizarBotonGoogle(document.createElement("div"), vi.fn());

    expect(
      document.head.querySelector("script[src*='accounts.google.com']"),
    ).toBeNull();
  });
});
