import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import FuncionesLanding from "./FuncionesLanding.vue";

function montar() {
  return mount(FuncionesLanding, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
      stubs: { RouterLink: { template: "<a><slot /></a>" } },
    },
  });
}
describe("tarjetas de funcionalidades", () => {
  it("presenta seis funciones con iconos y ejemplos ilustrativos", () => {
    const vista = montar();
    expect(vista.findAll(".funcion")).toHaveLength(6);
    expect(vista.findAll(".funcion-icono svg")).toHaveLength(6);
    expect(vista.findAll(".funcion-visual")).toHaveLength(6);
    expect(vista.text()).toContain("Ejemplos ilustrativos");
    expect(
      vista.findAll(".funcion-detalle").every((item) => !item.isVisible()),
    ).toBe(true);
    vista.unmount();
  });
  it("abre, cambia y cierra los detalles sin navegación inesperada", async () => {
    const vista = montar();
    const botones = vista.findAll(".funcion-abrir");
    await botones[0]!.trigger("click");
    expect(botones[0]!.attributes("aria-expanded")).toBe("true");
    expect(vista.get("#detalle-funcion-agenda").isVisible()).toBe(true);
    expect(vista.get("#detalle-funcion-agenda").text()).toContain(
      "Asigna horarios",
    );
    await botones[1]!.trigger("click");
    expect(botones[0]!.attributes("aria-expanded")).toBe("false");
    expect(vista.get("#detalle-funcion-reservas").isVisible()).toBe(true);
    await botones[1]!.trigger("click");
    expect(botones[1]!.attributes("aria-expanded")).toBe("false");
    await vi.waitFor(() =>
      expect(
        vista.get("#detalle-funcion-reservas").attributes("style"),
      ).toContain("display: none"),
    );
    vista.unmount();
  });
  it("usa nombres de disciplinas sin mezclar el tipo de negocio", () => {
    expect(es.landing.paraQuien.negocios.pilates.nombre).toBe("Pilates");
    expect(es.landing.paraQuien.negocios.pole.nombre).toBe("Pole dance");
  });
});
