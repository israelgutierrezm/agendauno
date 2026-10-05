import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import AgregarCalendario from "./AgregarCalendario.vue";

/*
| «Agregar a mi calendario»: cada opción con la miniatura de su app (Google
| Calendar; el .ics, Apple Calendar y Outlook) para reconocerla de un vistazo.
*/

describe("agregar a mi calendario", () => {
  it("las opciones llevan la miniatura de su app", async () => {
    const w = mount(AgregarCalendario, {
      props: {
        evento: {
          uid: "reserva-1",
          titulo: "Pole",
          inicio: "2026-10-05T16:00:00Z",
          fin: "2026-10-05T17:00:00Z",
        },
      },
      global: { plugins: [i18n], stubs: { teleport: true } },
      attachTo: document.body,
    });
    await w.get("button").trigger("click");

    const opciones = w.findAll('[role="menuitem"]');
    expect(opciones).toHaveLength(2);
    expect(opciones[0].text()).toContain("Google Calendar");
    expect(
      opciones[0]
        .findAll("svg[data-marca]")
        .map((s) => s.attributes("data-marca")),
    ).toEqual(["google"]);
    expect(
      opciones[1]
        .findAll("svg[data-marca]")
        .map((s) => s.attributes("data-marca")),
    ).toEqual(["apple", "outlook"]);
    w.unmount();
  });
});
