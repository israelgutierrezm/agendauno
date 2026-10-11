import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import EmbudoNegocio, { type Embudo } from "./EmbudoNegocio.vue";

const base: Embudo = {
  desde: "2026-09-02",
  hasta: "2026-10-01",
  sitio_a_solicitud: { visitas: 40, solicitudes: 4 },
  solicitud_a_compra: { solicitudes: 4, compraron: 1 },
  compra_a_asistencia: { compradores_nuevos: 3, asistieron: 2 },
  renovacion: { por_renovar: 0, renovadas: 0 },
  tiempo_respuesta: { solicitudes: 4, atendidas: 3, mediana_horas: 2.5 },
};

function montar(embudo: Embudo, citas = false) {
  return mount(EmbudoNegocio, {
    props: { embudo, citas },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, operacion } },
        }),
      ],
    },
  });
}

describe("el embudo del negocio", () => {
  it("cada paso con su cantidad, su denominador y el porcentaje", () => {
    const w = montar(base);

    const sitio = w.get('[data-paso="sitio"]').text();
    expect(sitio).toContain("10 %");
    expect(sitio).toContain("4 de 40");
    expect(w.get('[data-paso="compra"]').text()).toContain("1 de 4");
    expect(w.get('[data-paso="asistencia"]').text()).toContain("67 %");
    expect(w.get('[data-paso="asistencia"]').text()).toContain(
      "vinieron a clase",
    );
  });

  it("sin base no inventa un porcentaje y el tiempo de respuesta dice la mediana y cuántas faltan", () => {
    const w = montar(base);

    expect(w.get('[data-paso="renovacion"]').text()).toContain("—");
    expect(w.get('[data-paso="renovacion"]').text()).not.toContain("0 %");
    expect(w.get('[data-prueba="embudo-mediana"]').text()).toBe("2.5 h");
    expect(w.text()).toContain("Solicitudes sin atender, de 4");
  });

  it("en citas habla de citas y una respuesta en menos de una hora va en minutos", () => {
    const w = montar(
      {
        ...base,
        tiempo_respuesta: { solicitudes: 1, atendidas: 1, mediana_horas: 0.5 },
      },
      true,
    );

    expect(w.get('[data-paso="asistencia"]').text()).toContain("su cita");
    expect(w.get('[data-prueba="embudo-mediana"]').text()).toBe("30 min");
  });
});
