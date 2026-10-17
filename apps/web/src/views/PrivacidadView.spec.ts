import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import PrivacidadView from "./PrivacidadView.vue";

/*
| Solicitudes de privacidad (ARCO): por páginas, las pendientes primero. Antes se
| cortaban en 200 sin decirlo.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: async () => true }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const solicitud = (n: number) => ({
  id: `s${n}`,
  persona: `Persona ${n}`,
  tipo: "baja",
  estado: "pendiente",
  solicitada_en: "2026-10-01T12:00:00Z",
  vence_en: "2026-10-29",
  respuesta: null,
});

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation(
    (_url: string, opciones: { params: { page: number } }) =>
      Promise.resolve({
        data: {
          data: Array.from(
            { length: opciones.params.page === 1 ? 20 : 5 },
            (_, i) => solicitud(i + (opciones.params.page - 1) * 20),
          ),
          meta: {
            total: 25,
            page: opciones.params.page,
            per_page: 20,
            ultima_pagina: 2,
            pendientes: 25,
          },
        },
      }),
  );
});

describe("solicitudes de privacidad", () => {
  it("van por páginas", async () => {
    const w = mount(PrivacidadView, {
      global: { plugins: [i18n], stubs: { EncabezadoSeccion: true } },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/solicitudes-privacidad",
      { params: { page: 1, per_page: 20 } },
    );
    expect(w.text()).toContain("1–20 de 25");

    await w
      .findAll("button")
      .find((b) => b.text() === "2")!
      .trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/solicitudes-privacidad",
      { params: { page: 2, per_page: 20 } },
    );
    expect(w.text()).toContain("Persona 24");
  });

  it("se buscan, se filtran por vencimiento y las vencidas se distinguen", async () => {
    vi.useFakeTimers();
    api.get.mockResolvedValue({
      data: {
        data: [{ ...solicitud(1), vence_en: "2026-09-01" }, solicitud(2)],
        meta: {
          total: 2,
          page: 1,
          per_page: 20,
          ultima_pagina: 1,
          pendientes: 2,
          vencidas: 1,
          por_vencer: 0,
        },
      },
    });
    const w = mount(PrivacidadView, {
      global: { plugins: [i18n], stubs: { EncabezadoSeccion: true } },
    });
    await flushPromises();
    // La que pasó su plazo lo dice; la otra, hasta cuándo responder.
    expect(w.findAll('[data-prueba="vencida"]')).toHaveLength(1);
    expect(w.get('[data-prueba="ver-vencidas"]').text()).toBe("Vencidas (1)");

    await w.get('[data-prueba="buscar-solicitud"]').setValue("vale");
    vi.advanceTimersByTime(300);
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/solicitudes-privacidad",
      { params: { page: 1, per_page: 20, q: "vale" } },
    );

    await w.get('[data-prueba="ver-vencidas"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/solicitudes-privacidad",
      { params: { page: 1, per_page: 20, q: "vale", vencimiento: "vencidas" } },
    );
    vi.useRealTimers();
  });
});
