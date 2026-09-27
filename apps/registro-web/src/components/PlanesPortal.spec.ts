import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import planes from "@/i18n/locales/planes.es-MX";
import { vigenteHasta } from "@/lib/planes";
import CortePlanes from "./CortePlanes.vue";
import EditorPlan from "./EditorPlan.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));

function montar<T>(componente: T, props: Record<string, unknown>) {
  return mount(componente as never, {
    props,
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, planes } },
        }),
      ],
    },
  });
}

describe("vigencia de un plan (igual que la API)", () => {
  it("días, meses a la misma fecha y hasta fin de mes; el último día cuenta", () => {
    const compra = new Date(2026, 9, 14); // 14 de octubre
    expect(vigenteHasta("dias", 30, compra)).toBe("2026-11-13");
    expect(vigenteHasta("meses", 1, compra)).toBe("2026-11-14");
    expect(vigenteHasta("fin_de_mes", 1, compra)).toBe("2026-10-31");
    expect(vigenteHasta("fin_de_mes", 2, compra)).toBe("2026-11-30");
    // Sin desbordar febrero.
    expect(vigenteHasta("meses", 1, new Date(2027, 0, 31))).toBe("2027-02-28");
  });
});

describe("editor de planes", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date(2026, 9, 14, 12));
    vi.clearAllMocks();
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/ofertas")
            ? [
                { id: "o1", nombre: "Nivel 1", actividad: "Pole" },
                { id: "o4", nombre: "Nivel 4", actividad: "Pole" },
              ]
            : [],
        },
      }),
    );
    api.post.mockResolvedValue({ data: { data: {} } });
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("un paquete de un mes solo para Nivel 1: muestra hasta cuándo y lo manda así", async () => {
    const w = montar(EditorPlan, { plan: null });
    await flushPromises();

    await w.find("#ep-nombre").setValue("Paquete 8 clases");
    await w.find("#ep-precio").setValue("899");
    await w.find("#ep-clases").setValue("8");
    // Por defecto: meses desde la compra, 1.
    expect(w.text()).toContain(
      "Si se compra hoy (14 de octubre), se puede usar hasta el 14 de noviembre.",
    );

    const [todas, algunas] = w
      .findAll('input[type="radio"][value]')
      .filter((i) => ["true", "false"].includes(i.attributes("value") ?? ""));
    expect(todas).toBeDefined();
    await algunas.setValue(true);
    await w.findAll('input[type="checkbox"]')[0].setValue(true); // Nivel 1

    await w.find("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/productos",
      expect.objectContaining({
        nombre: "Paquete 8 clases",
        tipo: "paquete",
        precio_minor: 89900,
        creditos_incluidos: 8000,
        vigencia_tipo: "meses",
        vigencia_cantidad: 1,
        ofertas: ["o1"],
      }),
    );
    expect(w.emitted("guardado")).toBeTruthy();
  });

  it("las clases extra no llevan vigencia ni clases propias", async () => {
    const w = montar(EditorPlan, { plan: null });
    await flushPromises();
    await w
      .findAll(".ep-tipo")
      .find((b) => b.text().includes("Clases extra"))!
      .trigger("click");
    expect(w.text()).toContain("vencen el mismo día");

    await w.find("#ep-nombre").setValue("2 clases extra");
    await w.find("#ep-precio").setValue("250");
    await w.find("#ep-clases").setValue("2");
    await w.find("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/productos",
      expect.objectContaining({
        tipo: "add_on",
        creditos_incluidos: 2000,
        vigencia_tipo: null,
        ofertas: [],
      }),
    );
  });
});

describe("corte de planes", () => {
  it("muestra lo incluido, las extras y cómo se usó", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          {
            id: "a1",
            derecho_id: "d1",
            producto: "3 clases",
            tipo: "paquete",
            comprado: "2026-10-14",
            desde: "2026-10-14",
            hasta: "2026-11-14",
            estado: "vigente",
            ilimitado: false,
            aplica_a: ["Nivel 1"],
            unidades: {
              incluidas: 3000,
              extras: 1000,
              usadas: 2000,
              devueltas: 0,
              vencidas: 0,
              ajustes: 0,
              apartadas: 2000,
              disponibles: 0,
            },
            extras: [
              {
                producto: "Clase extra",
                comprado: "2026-10-20",
                unidades: 1000,
                usadas: 0,
              },
            ],
            usos: [
              {
                clase: "Nivel 1",
                inicia_en: "2026-10-22T01:00:00Z",
                zona_horaria: "America/Mexico_City",
                estado: "asistio",
                unidades: 1000,
                extra: false,
              },
              {
                clase: "Nivel 1",
                inicia_en: "2026-10-26T01:00:00Z",
                zona_horaria: "America/Mexico_City",
                estado: "proxima",
                unidades: 0,
                extra: true,
              },
            ],
          },
        ],
      },
    });
    const w = montar(CortePlanes, { url: "/api/v1/app/demo/mi/planes" });
    await flushPromises();

    const texto = w.text();
    expect(texto).toContain("3 clases");
    expect(texto).toContain("Vigente");
    expect(texto).toContain("Sirve para: Nivel 1");
    expect(texto).toContain("Incluidas");
    expect(texto).toContain("Clase extra");

    await w.find("button").trigger("click");
    expect(w.text()).toContain("Asistió");
    expect(w.text()).toContain("Próxima");
    expect(w.text()).toContain("(extra)");
  });
});
