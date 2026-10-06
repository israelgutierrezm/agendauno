import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import DocumentosView from "./DocumentosView.vue";

/*
| Expedientes: el listado de documentos va por páginas. Antes se cortaba en los
| últimos 200 sin página siguiente y los anteriores quedaban fuera; ahora se llega a
| todos.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => true,
  }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn() }),
}));

const documento = (n: number) => ({
  id: `d${n}`,
  persona: `Persona ${n}`,
  tipo: "INE",
  nombre: `doc-${n}.jpg`,
  estado: "pendiente",
  motivo: null,
  subido_en: "2026-10-01T12:00:00Z",
});

describe("documentos por páginas", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    api.get.mockReset();
  });

  it("muestra el total y lleva a la página siguiente", async () => {
    api.get.mockImplementation(
      (url: string, opciones?: { params?: { page?: number } }) => {
        if (url.endsWith("/documentos")) {
          const pagina = opciones?.params?.page ?? 1;
          return Promise.resolve({
            data: {
              data:
                pagina === 1
                  ? [documento(30), documento(29)]
                  : [documento(5), documento(4)],
              meta: { total: 30, page: pagina, per_page: 25, ultima_pagina: 2 },
            },
          });
        }
        return Promise.resolve({ data: { data: [] } });
      },
    );
    const w = mount(DocumentosView, {
      global: {
        plugins: [i18n],
        stubs: {
          EncabezadoSeccion: true,
          BuscarPersona: true,
          PanelLateral: true,
          ZonaArchivo: true,
          RouterLink: true,
        },
      },
    });
    await flushPromises();

    expect(w.text()).toContain("Persona 30");
    expect(w.text()).toContain("30");
    // Ir a la página 2 vuelve a pedir el listado de esa página.
    const siguiente = w.findAll("button").find((b) => b.text().trim() === "2");
    expect(siguiente).toBeDefined();
    await siguiente!.trigger("click");
    await flushPromises();

    const pedidos = api.get.mock.calls.filter(([url]) =>
      String(url).endsWith("/documentos"),
    );
    expect(pedidos.at(-1)?.[1]?.params).toMatchObject({
      page: 2,
      per_page: 25,
    });
    expect(w.text()).toContain("Persona 5");
  });
});
