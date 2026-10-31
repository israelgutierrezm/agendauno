import { mount, RouterLinkStub } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import RespuestaPresupuesto from "./RespuestaPresupuesto.vue";

/*
| Un presupuesto pagado se agenda donde se sabe lo prepagado: desde la cuenta, dentro de
| ella; el enlace público solo desde la página en línea.
*/

vi.mock("@/lib/api", () => ({ api: {}, mensajeDeError: () => "Error" }));

const pagado = {
  estado: "convertido",
  orden: { estado: "pagada" },
  sucursal: { id: "S1", nombre: "Centro" },
  servicios: [{ id: "OF1", nombre: "Corte" }],
} as never;

function montar(enCuenta: boolean) {
  return mount(RespuestaPresupuesto, {
    props: { presupuesto: pagado, url: "/x", slug: "barberia", enCuenta },
    global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } },
  });
}

describe("agendar lo ya pagado", () => {
  it("desde la cuenta se queda en Mis reservas, con el servicio y la sede", () => {
    const w = montar(true);

    expect(w.findComponent(RouterLinkStub).props("to")).toEqual({
      name: "mis-reservas",
      query: { servicio: "OF1", sucursal: "S1" },
    });
    expect(w.find('a[href^="/agendar/"]').exists()).toBe(false);
  });

  it("desde la página en línea va a la página pública", () => {
    const w = montar(false);

    expect(
      w.get('[data-prueba="presupuesto-agendar"]').attributes("href"),
    ).toBe("/agendar/barberia?servicio=OF1&sucursal=S1");
  });
});
