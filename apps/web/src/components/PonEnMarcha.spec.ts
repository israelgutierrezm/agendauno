import { mount, RouterLinkStub } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import PonEnMarcha, { type Quickstart } from "./PonEnMarcha.vue";

const quickstart: Quickstart = {
  tareas: [
    { clave: "sucursal", hecho: true, requerido: true, ruta: "onboarding" },
    { clave: "catalogo", hecho: true, requerido: true, ruta: "onboarding" },
    { clave: "horarios", hecho: true, requerido: true, ruta: "horarios" },
    { clave: "politica", hecho: false, requerido: true, ruta: "onboarding" },
    { clave: "productos", hecho: false, requerido: false, ruta: "ventas" },
    { clave: "miembros", hecho: true, requerido: false, ruta: "miembros" },
  ],
  progreso: { hechas: 3, total: 4 },
  listo: false,
};

function montar() {
  return mount(PonEnMarcha, {
    props: { quickstart },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { quickstart: es.quickstart } },
        }),
      ],
      stubs: { RouterLink: RouterLinkStub },
    },
  });
}

describe("Pon tu negocio en marcha", () => {
  it("muestra el avance y lleva a completar solo lo que falta", () => {
    const w = montar();

    expect(w.get('[data-prueba="avance"]').text()).toBe("3/4");
    expect(w.get('[role="progressbar"]').attributes("aria-valuenow")).toBe("3");
    // Lo esencial en columnas; solo el pendiente tiene «Ir».
    expect(w.findAll(".pm-paso")).toHaveLength(4);
    const irs = w.findAll('[data-prueba="ir"]');
    expect(irs).toHaveLength(1);
    expect(w.get('[data-prueba="paso-politica"]').text()).toContain("Ir");
    expect(w.get('[data-prueba="paso-sucursal"]').text()).not.toContain("Ir");
  });

  it("lo opcional va aparte; lo pendiente lleva a donde se hace", () => {
    const w = montar();
    const opcionales = w.get(".pm-opcionales");

    expect(opcionales.text()).toContain("Opcionales:");
    const enlaces = opcionales.findAllComponents(RouterLinkStub);
    expect(enlaces).toHaveLength(1);
    expect(enlaces[0].props("to")).toEqual({ name: "ventas" });
    expect(w.get('[data-prueba="opcional-miembros"]').text()).toContain(
      "(hecho)",
    );
  });
});
