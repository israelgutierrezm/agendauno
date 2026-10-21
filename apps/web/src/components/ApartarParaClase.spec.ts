import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ApartarParaClase from "./ApartarParaClase.vue";

/*
| Productos al reservar (ADR 0194): el cliente ve lo que se ofrece para su clase, lo
| aparta con su cantidad y lo quita antes de la clase.
*/

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ pais: "MX" }),
}));

const BASE = "/api/v1/app/fluo";
const global = { plugins: [i18n] };

beforeEach(() => {
  vi.clearAllMocks();
});

describe("ApartarParaClase", () => {
  it("aparta lo que elige con su cantidad y lo quita", async () => {
    api.get
      .mockResolvedValueOnce({
        data: {
          data: {
            articulos: [
              {
                id: "a1",
                nombre: "Agua 600ml",
                precio_minor: 2500,
                moneda: "MXN",
                foto_url: null,
                disponibles: 3,
              },
            ],
            apartados: [],
          },
        },
      })
      .mockResolvedValue({
        data: {
          data: {
            articulos: [],
            apartados: [
              {
                id: "p1",
                articulo: { id: "a1", nombre: "Agua 600ml" },
                cantidad: 2,
                total_minor: 5000,
                moneda: "MXN",
              },
            ],
          },
        },
      });
    api.post.mockResolvedValue({ data: { data: {} } });
    api.delete.mockResolvedValue({ data: { data: {} } });
    const w = mount(ApartarParaClase, {
      props: { base: BASE, sesionId: "s1" },
      global,
    });
    await flushPromises();

    expect(w.text()).toContain("Agua 600ml");
    await w.get("select").setValue(2);
    await w.get('[data-prueba="apartar"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(`${BASE}/mi/sesiones/s1/articulos`, {
      articulo_id: "a1",
      cantidad: 2,
    });
    expect(w.get('[data-prueba="mi-apartado"]').text()).toContain(
      "2 × Agua 600ml",
    );

    await w.get('[data-prueba="mi-apartado"] button').trigger("click");
    await flushPromises();
    expect(api.delete).toHaveBeenCalledWith(`${BASE}/mi/apartados/p1`);
  });
});
