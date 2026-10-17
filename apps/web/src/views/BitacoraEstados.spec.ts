import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import BitacoraView from "./BitacoraView.vue";

/*
| Cargando, sin cambios, nada coincide con los filtros y no se pudo consultar son
| cuatro cosas distintas: un fallo nunca se ve como «sin cambios registrados».
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Sin conexión",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    accesoAbierto: false,
    zonaHoraria: "America/Mexico_City",
    puede: () => true,
  }),
}));

const vacia = {
  data: {
    data: [],
    meta: { ultima_pagina: 1, actores: [], categorias: [] },
  },
};
function montar() {
  return mount(BitacoraView, {
    global: { plugins: [i18n], stubs: { EncabezadoSeccion: true } },
  });
}
// Los filtros de los cambios esperan a que se deje de escribir.
const esperarFiltro = () => new Promise((r) => setTimeout(r, 350));

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue(vacia);
});

describe("estados de la bitácora de cambios", () => {
  it("con filtros y sin resultados dice que nada coincide y los limpia", async () => {
    const w = montar();
    await flushPromises();
    expect(w.text()).toContain("Sin cambios registrados.");

    await w.get('input[type="search"]').setValue("pilates");
    await esperarFiltro();
    await flushPromises();

    const sin = w.get('[data-prueba="cambios-sin-coincidencias"]');
    expect(sin.text()).toContain("No encontramos coincidencias");
    expect(w.text()).not.toContain("Sin cambios registrados.");

    await sin.get("button").trigger("click");
    await esperarFiltro();
    await flushPromises();
    expect(
      (w.get('input[type="search"]').element as HTMLInputElement).value,
    ).toBe("");
    expect(w.find('[data-prueba="cambios-sin-coincidencias"]').exists()).toBe(
      false,
    );
    expect(w.text()).toContain("Sin cambios registrados.");
  });

  it("si no se pudo consultar, lo dice y deja reintentar", async () => {
    api.get.mockRejectedValueOnce(new Error("red"));
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="error-carga-bitacora"]').text()).toContain(
      "Sin conexión",
    );
    expect(w.text()).not.toContain("Sin cambios registrados.");

    await w.get('[data-prueba="error-carga-bitacora"] button').trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="error-carga-bitacora"]').exists()).toBe(false);
    expect(w.text()).toContain("Sin cambios registrados.");
  });
});
