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
      // El reporte por campaña (ADR 0144) se prueba aparte.
      stubs: { CampanasNegocio: true },
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

  it("en un negocio de citas mide cita → asistencia → cobro → siguiente visita, no planes (R11)", () => {
    const w = montar(
      {
        ...base,
        citas: {
          interes: { solicitudes: 12, con_cita: 5 },
          agendadas: { agendadas: 20, confirmadas: 18 },
          asistencia: { citas: 16, asistieron: 14 },
          cobro: { atendidas: 14, cobradas: 13 },
          siguiente: { clientes: 10, volvieron: 6 },
        },
      },
      true,
    );

    // El título lo pone Reportes (su vista «Embudo»): el embudo no repite el suyo.
    expect(w.text()).not.toContain("Del interés a la siguiente visita");
    expect(w.get('[data-paso="interes"]').text()).toContain("5 de 12");
    expect(w.get('[data-paso="confirmadas"]').text()).toContain("18 de 20");
    expect(w.get('[data-paso="asistenciaCita"]').text()).toContain("14 de 16");
    expect(w.get('[data-paso="cobro"]').text()).toContain("13 de 14");
    expect(w.get('[data-paso="siguiente"]').text()).toContain("6 de 10");
    expect(w.find('[data-paso="renovacion"]').exists()).toBe(false);
    expect(w.find('[data-paso="compra"]').exists()).toBe(false);
  });

  it("solo compara con las visitas lo comparable; lo demás va aparte y sin porcentaje", () => {
    const w = montar({
      ...base,
      sitio_a_solicitud: {
        visitas: 4,
        solicitudes: 10,
        otras: { enlace: 6, embebido: 0, sin_canal: 1, equipo: 2 },
      },
    });

    const sitio = w.get('[data-paso="sitio"]');
    // Más solicitudes que visitas medidas no es un 250 %.
    expect(sitio.text()).not.toContain("250 %");
    expect(sitio.text()).toContain("—");
    expect(sitio.find('[data-prueba="no-comparable"]').exists()).toBe(true);
    expect(sitio.get('[data-prueba="otras-solicitudes"]').text()).toBe(
      "Aparte, sin compararse con las visitas: 6 por enlace o QR · 2 registradas por tu equipo · 1 de antes de medir el canal.",
    );
  });
});
