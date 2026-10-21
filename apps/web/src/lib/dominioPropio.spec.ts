import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/lib/api";
import { resolverDominioPropio } from "@/lib/dominioPropio";
import { productoActual, fijarProductoDeSesion } from "@/lib/producto";
import {
  esHostAjeno,
  fijarDominioPropio,
  slugDeSubdominio,
  urlPublicaEstudio,
} from "@/lib/tenant";

/*
| El dominio propio de un negocio (ADR 0189): en un host que no es de la plataforma,
| la web pregunta de qué negocio es y ahí hace las veces del subdominio.
*/
afterEach(() => {
  fijarDominioPropio(null);
  fijarProductoDeSesion(null);
  vi.restoreAllMocks();
});

describe("dominio propio", () => {
  it("solo un host ajeno puede ser el dominio de un negocio", () => {
    expect(esHostAjeno("reservas.fluo.mx")).toBe(true);
    for (const host of [
      "localhost",
      "fluo.localhost",
      "127.0.0.1",
      "[::1]",
      "agendauno.mx",
      "fluo.agendauno.mx",
      "barberia.turnouno.mx",
    ]) {
      expect(esHostAjeno(host)).toBe(false);
    }
  });

  it("pregunta de qué negocio es y lo toma como su subdominio", async () => {
    const get = vi.spyOn(api, "get").mockResolvedValue({
      data: { data: { slug: "barberia-a", producto: "turnouno" } },
    });

    expect(await resolverDominioPropio("citas.barberia.mx")).toBe(true);
    expect(get).toHaveBeenCalledWith("/api/v1/dominio-propio");
    expect(slugDeSubdominio("citas.barberia.mx")).toBe("barberia-a");
    expect(urlPublicaEstudio("barberia-a")).toBe("citas.barberia.mx");
    expect(productoActual()).toBe("turnouno");
  });

  it("en la plataforma no pregunta, y si el dominio no es de nadie sigue igual", async () => {
    const get = vi.spyOn(api, "get").mockRejectedValue(new Error("404"));

    expect(await resolverDominioPropio("fluo.agendauno.mx")).toBe(false);
    expect(get).not.toHaveBeenCalled();

    expect(await resolverDominioPropio("otro.com")).toBe(false);
    expect(slugDeSubdominio("otro.com")).toBeNull();
  });
});
