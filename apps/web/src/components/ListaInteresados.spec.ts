import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ListaInteresados from "./ListaInteresados.vue";

/*
| Lista de interesados de TurnoUno antes de su lanzamiento (ADR 0108): manda los datos
| con el producto y agradece; sin aceptar el aviso de privacidad no se puede enviar.
*/

const api = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  camposConError: () => [],
  mensajeDeError: () => "Error",
}));
vi.mock("vue-router", () => ({
  RouterLink: { template: "<a><slot /></a>" },
}));

describe("ListaInteresados", () => {
  it("deja los datos con el producto y agradece", async () => {
    api.post.mockResolvedValueOnce({ data: { data: { registrado: true } } });
    const w = mount(ListaInteresados, {
      props: { producto: "turnouno" },
      global: { plugins: [i18n] },
    });

    expect(w.text()).toContain("TurnoUno abre pronto");
    // Solo los giros de citas.
    const giros = w.findAll("select option").map((o) => o.element.value);
    expect(giros).toContain("barberia");
    expect(giros).not.toContain("pilates");

    const enviar = w.get('button[type="submit"]');
    expect(enviar.attributes("disabled")).toBeDefined();

    await w.findAll("input")[0]!.setValue("Ana");
    await w.get('input[type="email"]').setValue("ana@barberia.mx");
    await w.get("select").setValue("barberia");
    await w.get('input[type="checkbox"]').setValue(true);
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/interesados",
      expect.objectContaining({
        producto: "turnouno",
        nombre: "Ana",
        correo: "ana@barberia.mx",
        giro: "barberia",
        acepta_aviso: true,
      }),
    );
    expect(w.text()).toContain("Listo, te avisaremos");
  });
});
