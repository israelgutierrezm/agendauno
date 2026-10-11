import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import { plataformaAdmin } from "@/i18n/locales/gestion.es-MX";
import EmbudoPlataforma from "./EmbudoPlataforma.vue";

const get = vi.hoisted(() => vi.fn());
vi.mock("axios", () => ({ default: { create: () => ({ get }) } }));
vi.mock("@/lib/api", () => ({ mensajeDeError: (e: unknown) => String(e) }));

describe("embudo de la plataforma (C08)", () => {
  it("por producto: cada paso con cuántos y de cuántos registros, y los días medianos", async () => {
    get.mockResolvedValue({
      data: {
        data: {
          productos: [
            {
              producto: "agendauno",
              visitas_landing: 200,
              registros: 10,
              verificados: 8,
              publicados: 5,
              primera_operacion: 3,
              primer_pago: 1,
              dias_a_publicar: 2,
              dias_a_operar: null,
            },
          ],
        },
      },
    });
    const w = mount(EmbudoPlataforma, {
      props: { apiUrl: "http://api", token: "tok" },
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: "es",
            missingWarn: false,
            fallbackWarn: false,
            messages: { es: { ...es, plataformaAdmin } },
          }),
        ],
      },
    });
    await flushPromises();

    expect(get.mock.calls[0]![1].headers.Authorization).toBe("Bearer tok");
    const tarjeta = w.get('[data-producto="agendauno"]').text();
    expect(tarjeta).toContain("AgendaUno");
    expect(tarjeta).toContain("10 · 5 %");
    expect(tarjeta).toContain("5 de 10 · 50 %");
    expect(tarjeta).toContain("2 d");
    expect(tarjeta).toContain("—");
  });
});
