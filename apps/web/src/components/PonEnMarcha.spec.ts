import { mount, RouterLinkStub } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import configuracionInicial from "@/i18n/locales/configuracionInicial.es-MX";
import PonEnMarcha, { type Quickstart } from "./PonEnMarcha.vue";

/*
| «Pon tu negocio en marcha» muestra los mismos pasos que la configuración guiada
| (ADR 0090): cada «Ir» abre ese paso, y el estado dice si está configurado,
| publicado y si recibe reservas.
*/

const quickstart: Quickstart = {
  tareas: [
    { clave: "negocio", hecho: true, requerido: true, ruta: "onboarding" },
    { clave: "servicios", hecho: true, requerido: true, ruta: "onboarding" },
    { clave: "equipo", hecho: true, requerido: true, ruta: "onboarding" },
    { clave: "reglas", hecho: false, requerido: true, ruta: "onboarding" },
    { clave: "publicacion", hecho: false, requerido: true, ruta: "onboarding" },
    { clave: "cobro", hecho: false, requerido: false, ruta: "pasarelas" },
    { clave: "miembros", hecho: true, requerido: false, ruta: "miembros" },
  ],
  progreso: { hechas: 3, total: 5 },
  listo: false,
  estado: {
    configurado: false,
    publicado: true,
    reservable: true,
    listo: false,
    primera_fecha: {
      inicia_en: "2026-10-01T16:00:00+00:00",
      zona_horaria: "America/Mexico_City",
      sucursal: "Roma Norte",
      que: "Corte de cabello",
    },
    motivo: null,
  },
};

function montar(q: Quickstart = quickstart) {
  return mount(PonEnMarcha, {
    props: { quickstart: q },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { quickstart: es.quickstart, configuracionInicial } },
        }),
      ],
      stubs: { RouterLink: RouterLinkStub },
    },
  });
}

describe("Pon tu negocio en marcha", () => {
  it("los pasos son los de la configuración guiada y cada «Ir» abre ese paso", () => {
    const w = montar();

    expect(w.get('[data-prueba="avance"]').text()).toBe("3/5");
    expect(w.findAll(".pm-paso")).toHaveLength(5);
    expect(w.get('[data-prueba="paso-reglas"]').text()).toContain("Reglas");
    const irs = w
      .findAll('[data-prueba="ir"]')
      .map((l) => l.findComponent(RouterLinkStub).props("to"));
    expect(irs).toEqual([
      { name: "onboarding", query: { paso: "reglas" } },
      { name: "onboarding", query: { paso: "publicacion" } },
    ]);
    expect(w.get('[data-prueba="paso-negocio"]').text()).not.toContain("Ir");
  });

  it("dice si está configurado, publicado y si recibe reservas (con la primera fecha)", () => {
    const w = montar();

    expect(
      w.get('[data-prueba="estado-configurado"]').attributes("data-si"),
    ).toBe("false");
    expect(
      w.get('[data-prueba="estado-reservable"]').attributes("data-si"),
    ).toBe("true");
    expect(w.get('[data-prueba="estados"]').text()).toContain(
      "Corte de cabello · Roma Norte",
    );

    const sinFecha = montar({
      ...quickstart,
      estado: {
        ...quickstart.estado!,
        reservable: false,
        primera_fecha: null,
        motivo: "sin_horario",
      },
    });
    expect(sinFecha.get('[data-prueba="estados"]').text()).toContain(
      "Aún no hay un horario de atención.",
    );
  });

  it("lo opcional va aparte; lo pendiente lleva a donde se hace", () => {
    const w = montar();
    const opcionales = w.get(".pm-opcionales");

    expect(opcionales.text()).toContain("Opcionales:");
    const enlaces = opcionales.findAllComponents(RouterLinkStub);
    expect(enlaces).toHaveLength(1);
    expect(enlaces[0]!.props("to")).toEqual({ name: "pasarelas" });
    expect(w.get('[data-prueba="opcional-miembros"]').text()).toContain(
      "(hecho)",
    );
  });
});
