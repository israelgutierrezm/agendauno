import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import LealtadView from "./LealtadView.vue";

/*
| Lealtad: la ayuda de «puntos por unidad de moneda» habla de la moneda del negocio,
| no de pesos.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  puede: () => true,
  moneda: "MXN",
  pais: "MX",
}));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: String }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn() }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/programa")
          ? { activa: true, puntos_por_asistencia: 10, puntos_por_moneda: 1 }
          : [],
      },
    }),
  );
});

async function ayuda(moneda: string, pais: string): Promise<string> {
  sesion.moneda = moneda;
  sesion.pais = pais;
  const w = mount(LealtadView, {
    global: { plugins: [i18n], stubs: { BuscarPersona: true, Teleport: true } },
  });
  await flushPromises();
  await w
    .findAll("button")
    .find((b) => b.text() === "Configurar programa")!
    .trigger("click");
  const texto = w
    .get('[data-prueba="ayuda-por-moneda"]')
    .text()
    .replace(/\s/g, " ");
  w.unmount();
  return texto;
}

describe("puntos por unidad de moneda", () => {
  it("separa consulta de configuración y cancelar no cambia el programa", async () => {
    const w = mount(LealtadView, {
      global: {
        plugins: [i18n],
        stubs: { BuscarPersona: true, Teleport: true },
      },
    });
    await flushPromises();
    expect(w.find("#ppa").exists()).toBe(false);
    await w
      .findAll("button")
      .find((b) => b.text() === "Configurar programa")!
      .trigger("click");
    await w.get("#ppa").setValue(99);
    await w
      .get('[role="dialog"]')
      .findAll("button")
      .find((b) => b.text() === "Cancelar")!
      .trigger("click");
    expect(api.put).not.toHaveBeenCalled();
    expect(w.text()).toContain("Puntos por asistir a una clase: 10");
    await w
      .findAll("button")
      .find((b) => b.text() === "Configurar programa")!
      .trigger("click");
    expect((w.get("#ppa").element as HTMLInputElement).value).toBe("10");
    w.unmount();
  });
  it("el alta de recompensa se abre a demanda y conserva la petición existente", async () => {
    api.post.mockResolvedValue({ data: {} });
    const w = mount(LealtadView, {
      global: {
        plugins: [i18n],
        stubs: { BuscarPersona: true, Teleport: true },
      },
    });
    await flushPromises();
    expect(w.find('[role="dialog"]').exists()).toBe(false);
    await w
      .findAll("button")
      .find((b) => b.text() === "Nueva recompensa")!
      .trigger("click");
    await w.get('[role="dialog"] input[required]').setValue("Clase de regalo");
    await w.get('[role="dialog"] input[type="number"]').setValue(200);
    await w.get('[role="dialog"] form').trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/lealtad/recompensas",
      {
        nombre: "Clase de regalo",
        descripcion: null,
        costo_puntos: 200,
      },
    );
    expect(w.find('[role="dialog"]').exists()).toBe(false);
    w.unmount();
  });
  it("en pesos mexicanos, como siempre", async () => {
    expect(await ayuda("MXN", "MX")).toBe(
      "Ej. 1 = un punto por cada $1 pagado.",
    );
  });

  it("en otra moneda, con su símbolo", async () => {
    expect(await ayuda("EUR", "ES")).toBe(
      "Ej. 1 = un punto por cada 1 € pagado.",
    );
  });
});

describe("canjes por entregar y su historial", () => {
  it("separa los pendientes del historial, con sus cuentas y por páginas", async () => {
    api.get.mockImplementation(
      (url: string, opciones?: { params?: { vista?: string } }) =>
        Promise.resolve({
          data: url.endsWith("/programa")
            ? {
                data: {
                  activa: true,
                  puntos_por_asistencia: 10,
                  puntos_por_moneda: 1,
                },
              }
            : url.endsWith("/canjes")
              ? {
                  data: [
                    {
                      id: "c1",
                      persona: "Ana",
                      recompensa: "Botella",
                      puntos: 100,
                      estado:
                        opciones?.params?.vista === "historial"
                          ? "entregado"
                          : "pendiente",
                      entregado_en: null,
                      creado_en: "2026-10-01T10:00:00Z",
                    },
                  ],
                  meta: {
                    total: 41,
                    page: 1,
                    per_page: 20,
                    ultima_pagina: 3,
                    conteos: { pendientes: 41, historial: 7 },
                    resumen: { pendientes: 41, puntos_entregados: 700 },
                  },
                }
              : { data: [] },
        }),
    );
    const w = mount(LealtadView, {
      global: {
        plugins: [i18n],
        stubs: { BuscarPersona: true, Teleport: true },
      },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/lealtad/canjes", {
      params: { vista: "pendientes", page: 1 },
    });
    expect(w.get('[data-prueba="canjes-pendientes"]').text()).toContain("41");
    expect(w.get('[data-prueba="canjes-historial"]').text()).toContain("7");
    // Los indicadores usan el resumen del negocio, no solo la página.
    expect(w.text()).toContain("700");
    expect(w.text()).toContain("Entregar");

    await w.get('[data-prueba="canjes-historial"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/lealtad/canjes",
      {
        params: { vista: "historial", page: 1 },
      },
    );
    expect(w.text()).not.toContain("Entregar");
    w.unmount();
  });
});
