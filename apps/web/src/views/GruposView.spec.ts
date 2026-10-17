import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import GruposView from "./GruposView.vue";

/*
| Grupos: un grupo no tiene tope de lugares, así que sus inscritos se piden por páginas,
| se cuentan todos y, si son muchos, se buscan.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));

enableAutoUnmount(afterEach);

const inscrito = (n: number) => ({
  id: `i${n}`,
  persona: `Persona ${n}`,
  persona_id: `p${n}`,
  activo: n !== 3,
});

beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers();
  api.get.mockImplementation(
    (url: string, opciones?: { params?: { page?: number; q?: string } }) => {
      if (url.endsWith("/grupos")) {
        return Promise.resolve({
          data: {
            data: [
              {
                id: "g1",
                nombre: "Natación niños",
                oferta: "Natación",
                inscritos: 25,
                activo: true,
              },
            ],
          },
        });
      }
      if (url.endsWith("/grupos/g1/inscripciones")) {
        const p = opciones?.params ?? {};
        const lista = p.q
          ? [inscrito(7)]
          : p.page === 2
            ? Array.from({ length: 5 }, (_, i) => inscrito(21 + i))
            : Array.from({ length: 20 }, (_, i) => inscrito(1 + i));
        return Promise.resolve({
          data: { data: lista, meta: { total: p.q ? 1 : 25 } },
        });
      }
      return Promise.resolve({ data: { data: [] } });
    },
  );
});
afterEach(() => {
  vi.useRealTimers();
});

describe("inscritos de un grupo", () => {
  it("se cuentan todos, van por páginas y se buscan", async () => {
    const w = mount(GruposView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    await w.get('[data-prueba="grupo"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/grupos/g1/inscripciones",
      { params: { page: 1, per_page: 20 } },
    );
    expect(w.get('[data-prueba="total-inscritos"]').text()).toBe(
      "25 inscritos",
    );
    expect(w.findAll(".gr-inscritos > li")).toHaveLength(20);
    // Quien se dio de baja se distingue.
    expect(w.findAll(".gr-inscritos > li")[2]!.text()).toContain("Baja");

    await w.get('[data-prueba="mas-inscritos"]').trigger("click");
    await flushPromises();
    expect(w.findAll(".gr-inscritos > li")).toHaveLength(25);
    expect(w.find('[data-prueba="mas-inscritos"]').exists()).toBe(false);

    await w.get('[data-prueba="buscar-inscrito"]').setValue("persona 7");
    vi.advanceTimersByTime(300);
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/grupos/g1/inscripciones",
      { params: { page: 1, per_page: 20, q: "persona 7" } },
    );
    expect(w.findAll(".gr-inscritos > li")).toHaveLength(1);
  });
});
