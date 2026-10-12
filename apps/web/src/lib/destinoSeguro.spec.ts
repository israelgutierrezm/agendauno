import { describe, expect, it } from "vitest";

import {
  volverConInteres,
  volverSeguro,
  type RutaResuelta,
} from "./destinoSeguro";

/*
| A dónde volver después de entrar (ADR 0142): solo rutas de la app y del negocio al
| que se entra; lo que eligió en su página viaja en la dirección de regreso.
*/

// Un router mínimo: /estudio/:slug, /agendar/:slug y /mi-cuenta existen.
function resolver(ruta: string): RutaResuelta {
  const url = new URL(ruta, "https://app.test");
  const query = Object.fromEntries(url.searchParams);
  const conSlug = url.pathname.match(/^\/(?:estudio|agendar)\/([^/]+)/);
  if (conSlug) return { matched: [1], params: { slug: conSlug[1] }, query };
  if (url.pathname === "/mi-cuenta") return { matched: [1], params: {}, query };
  return { matched: [], params: {}, query };
}

describe("destino seguro", () => {
  it("vuelve a una ruta interna del mismo negocio", () => {
    expect(volverSeguro("/mi-cuenta?plan=x", "grecon", resolver)).toBe(
      "/mi-cuenta?plan=x",
    );
    expect(volverSeguro("/agendar/grecon", "grecon", resolver)).toBe(
      "/agendar/grecon",
    );
  });

  it("no sale a otro sitio", () => {
    for (const malo of [
      "https://otro.com",
      "//otro.com",
      "/\\otro.com",
      "/%0d%0a//otro.com".replace("%0d%0a", "\r\n"),
      "javascript:alert(1)",
      `/${"a".repeat(600)}`,
    ]) {
      expect(volverSeguro(malo, "grecon", resolver)).toBeNull();
    }
    expect(volverSeguro(42, "grecon", resolver)).toBeNull();
  });

  it("no pasa a otro negocio ni a una ruta que no existe", () => {
    expect(volverSeguro("/estudio/otro", "grecon", resolver)).toBeNull();
    expect(
      volverSeguro("/mi-cuenta?estudio=otro", "grecon", resolver),
    ).toBeNull();
    expect(volverSeguro("/no-existe", "grecon", resolver)).toBeNull();
  });

  it("lleva el plan o la clase que eligió a su cuenta", () => {
    const plan = "01J9Z8X7W6V5T4S3R2Q1P0N9M8";
    expect(volverConInteres({ producto: plan })).toBe(
      `/mi-cuenta?plan=${plan}`,
    );
    expect(volverConInteres({ sesion: plan })).toBe(`/mi-cuenta?clase=${plan}`);
    expect(volverConInteres({ producto: "../otro" })).toBeNull();
    expect(volverConInteres(null)).toBeNull();
  });
});
