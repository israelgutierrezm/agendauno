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
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: String,
  erroresPorCampo: (e: {
    response?: { data?: { meta?: { errors?: Record<string, string[]> } } };
  }) =>
    Object.fromEntries(
      Object.entries(e.response?.data?.meta?.errors ?? {}).map(([c, m]) => [
        c,
        m[0]!,
      ]),
    ),
}));
const confirmar = vi.hoisted(() => vi.fn());
vi.mock("@/lib/confirmar", () => ({ confirmar }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
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

describe("configurar el programa, aparte de operar los canjes", () => {
  const botella = {
    id: "r1",
    nombre: "Botella",
    descripcion: null,
    costo_puntos: 300,
    activa: true,
  };
  beforeEach(() => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/programa")
            ? { activa: true, puntos_por_asistencia: 10, puntos_por_moneda: 1 }
            : url.endsWith("/recompensas")
              ? [botella]
              : [],
        },
      }),
    );
  });
  function montarLealtad() {
    return mount(LealtadView, {
      global: {
        plugins: [i18n],
        stubs: { BuscarPersona: true, Teleport: true },
      },
    });
  }

  it("el programa y sus recompensas van en una sección; los puntos y canjes, en otra", async () => {
    const w = montarLealtad();
    await flushPromises();
    const programa = w.get('[data-prueba="seccion-programa"]');
    const canjes = w.get('[data-prueba="seccion-canjes"]');
    expect(programa.get("h2").text()).toBe("Configuración del programa");
    expect(canjes.get("h2").text()).toBe("Canjes y puntos");

    // Configurar: reglas de puntos y catálogo de recompensas.
    expect(programa.text()).toContain("Configurar programa");
    expect(programa.text()).toContain("Nueva recompensa");
    expect(programa.find('[data-prueba="recompensa"]').exists()).toBe(true);
    expect(programa.find('[data-prueba="vista-canjes"]').exists()).toBe(false);
    // Operar: saldo de un miembro y los canjes por entregar.
    expect(canjes.find("buscar-persona-stub").exists()).toBe(true);
    expect(canjes.find('[data-prueba="vista-canjes"]').exists()).toBe(true);
    expect(canjes.text()).not.toContain("Configurar programa");
    // El estado, con punto y texto.
    expect(w.get('[data-prueba="estado-programa"]').classes()).toContain(
      "tu-estado",
    );
    // Editar y desactivar una recompensa son botones (desactivar, de peligro).
    expect(w.get('[data-prueba="editar-recompensa-r1"]').classes()).toContain(
      "tu-btn-secundario",
    );
    expect(w.get('[data-prueba="alternar-recompensa-r1"]').classes()).toContain(
      "tu-btn-peligro",
    );
    w.unmount();
  });

  it("edita una recompensa: abre con lo guardado, la guarda con PUT y lo canjeado no cambia", async () => {
    api.put.mockResolvedValue({ data: { data: {} } });
    const w = montarLealtad();
    await flushPromises();
    await w.get('[data-prueba="editar-recompensa-r1"]').trigger("click");

    const dialogo = w.get('[role="dialog"]');
    expect(dialogo.text()).toContain("Editar recompensa: Botella");
    expect(dialogo.text()).toContain(
      "Los canjes ya hechos conservan el nombre y los puntos",
    );
    expect((w.get("#lr-nombre").element as HTMLInputElement).value).toBe(
      "Botella",
    );
    expect((w.get("#lr-costo").element as HTMLInputElement).value).toBe("300");

    await w.get("#lr-nombre").setValue("Botella de agua");
    await w.get("#lr-costo").setValue(250);
    await w.get("#lr-desc").setValue("De 600 ml");
    await w.get('[data-prueba="recompensa-activa"]').trigger("click");
    await w.get("#form-recompensa").trigger("submit");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/lealtad/recompensas/r1",
      {
        nombre: "Botella de agua",
        descripcion: "De 600 ml",
        costo_puntos: 250,
        activa: false,
      },
    );
    expect(api.post).not.toHaveBeenCalled();
    expect(toast.exito).toHaveBeenCalledWith(
      "Recompensa «Botella de agua» guardada.",
    );
    expect(w.find('[role="dialog"]').exists()).toBe(false);
    w.unmount();
  });

  it("el error de un campo queda junto a él y no se descarta sin preguntar", async () => {
    api.put.mockRejectedValueOnce({
      response: {
        data: {
          meta: { errors: { costo_puntos: ["Debe ser al menos 1."] } },
        },
      },
    });
    confirmar.mockResolvedValue(false);
    const w = montarLealtad();
    await flushPromises();
    await w.get('[data-prueba="editar-recompensa-r1"]').trigger("click");
    await w.get("#lr-costo").setValue(0);
    await w.get("#form-recompensa").trigger("submit");
    await flushPromises();

    expect(w.get("#lr-costo-error").text()).toBe("Debe ser al menos 1.");
    expect(w.get("#lr-costo").attributes("aria-invalid")).toBe("true");
    expect(w.text()).toContain("Revisa los campos marcados");

    await w
      .get('[role="dialog"]')
      .findAll("button")
      .find((b) => b.text() === "Cancelar")!
      .trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(w.find("#lr-costo").exists()).toBe(true);
    w.unmount();
  });
});
