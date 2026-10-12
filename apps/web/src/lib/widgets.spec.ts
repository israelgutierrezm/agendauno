import { describe, expect, it } from "vitest";

import {
  contrasteDe,
  hostDeQuienInserta,
  permitidoEn,
  urlWidget,
} from "./widgets";

/*
| Widgets (ADR 0141): dónde se pueden mostrar y con qué texto sobre su color.
*/

describe("widgets", () => {
  it("se muestra en las webs registradas y sus subdominios, en la plataforma y sin webs en cualquiera", () => {
    const webs = ["miestudio.com"];
    expect(permitidoEn("miestudio.com", webs, "app.agendauno.mx")).toBe(true);
    expect(permitidoEn("www.miestudio.com", webs, "app.agendauno.mx")).toBe(
      true,
    );
    expect(permitidoEn("otraweb.com", webs, "app.agendauno.mx")).toBe(false);
    expect(permitidoEn("falsomiestudio.com", webs, "app.agendauno.mx")).toBe(
      false,
    );
    expect(permitidoEn("app.agendauno.mx", webs, "app.agendauno.mx")).toBe(
      true,
    );
    expect(permitidoEn("otraweb.com", [], "app.agendauno.mx")).toBe(true);
    // Abierto directo (sin web que lo inserte).
    expect(permitidoEn(null, webs, "app.agendauno.mx")).toBe(true);
  });

  it("lee la web que lo inserta; abierto directo, ninguna", () => {
    expect(hostDeQuienInserta(window, document)).toBeNull();
    const enIframe = {
      parent: {},
      location: { ancestorOrigins: ["https://www.miestudio.com"] },
    } as unknown as Window;
    expect(hostDeQuienInserta(enIframe, document)).toBe("www.miestudio.com");
  });

  it("el texto sobre el acento se lee: oscuro sobre claros, blanco sobre oscuros", () => {
    expect(contrasteDe("#ffd84d")).toBe("#111111");
    expect(contrasteDe("#1a3c8f")).toBe("#ffffff");
  });

  it("en desarrollo la dirección es la de aquí", () => {
    expect(urlWidget("demo", "w1", "localhost", "http://localhost:5175")).toBe(
      "http://localhost:5175/w/demo/w1",
    );
  });
});
