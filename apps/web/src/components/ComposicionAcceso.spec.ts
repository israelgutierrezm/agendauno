import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import ComposicionAcceso from "./ComposicionAcceso.vue";

describe("Composición pública de acceso", () => {
  it.each(["agendauno", "turnouno"] as const)(
    "muestra imagen y ejemplos propios de %s",
    (producto) => {
      const vista = mount(ComposicionAcceso, {
        props: { producto },
        global: {
          plugins: [
            createI18n({ legacy: false, locale: "es", messages: { es } }),
          ],
        },
      });
      expect(vista.get("img").attributes("src")).toBe(
        `/assets/landing/acceso-${producto}-v2.webp`,
      );
      expect(vista.get("h2").text()).toBe(es.entrar.panelTitulo);
      expect(vista.findAll(".acceso-escena-cita")).toHaveLength(3);
      expect(vista.text()).toContain(
        producto === "agendauno" ? "Pole dance básico" : "Corte y peinado",
      );
      expect(vista.text()).not.toContain(
        producto === "agendauno" ? "Masaje relajante" : "Yoga matutino",
      );
      vista.unmount();
    },
  );
  it("registro cambia el titular sin duplicar el encabezado del formulario", () => {
    const vista = mount(ComposicionAcceso, {
      props: { producto: "turnouno", registro: true },
      global: {
        plugins: [
          createI18n({ legacy: false, locale: "es", messages: { es } }),
        ],
      },
    });
    expect(vista.get("h2").text()).toBe(es.entrar.visualRegistroTitulo);
    expect(vista.find("h1").exists()).toBe(false);
    vista.unmount();
  });
});
