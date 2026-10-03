import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import RecursosView from "./RecursosView.vue";

/*
| Salas y equipos con el patrón de los listados: indicadores y búsqueda; con una
| sucursal elegida en la barra, solo los de esa sucursal.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  sucursal: { value: null as { id: string; nombre: string } | null },
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: vi.fn(), delete: vi.fn() },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
vi.mock("@/lib/sucursalOperativa", async () => {
  const { computed } = await import("vue");
  return {
    useSucursalOperativa: () => ({
      mostrarSelect: computed(() => mocks.sucursal.value === null),
      fija: computed(() => mocks.sucursal.value?.id ?? null),
      actual: computed(() => mocks.sucursal.value),
    }),
    useSucursales: () => ({
      varias: computed(() => true),
      actual: computed(() => mocks.sucursal.value),
    }),
  };
});

const recurso = (id: string, nombre: string, sucursal: string) => ({
  id,
  sucursal,
  nombre,
  tipo: "Cabina",
  modo: "unidad",
  capacidad: 1,
  activo: true,
});

beforeEach(() => {
  vi.clearAllMocks();
  mocks.sucursal.value = null;
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/recursos")
          ? [
              recurso("1", "Cabina 1", "Roma Norte"),
              recurso("2", "Cabina 2", "Del Valle"),
            ]
          : [{ id: "s1", nombre: "Roma Norte" }],
      },
    }),
  );
});

describe("salas y equipos", () => {
  it("busca por nombre y, con una sucursal elegida, muestra solo los suyos", async () => {
    const w = mount(RecursosView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();
    expect(w.findAll('[data-prueba="recurso"]')).toHaveLength(2);
    await w.get('[data-prueba="buscar-recurso"]').setValue("2");
    expect(w.findAll('[data-prueba="recurso"]')).toHaveLength(1);

    mocks.sucursal.value = { id: "s1", nombre: "Roma Norte" };
    const fija = mount(RecursosView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();
    const filas = fija.findAll('[data-prueba="recurso"]');
    expect(filas).toHaveLength(1);
    expect(filas[0]!.text()).toContain("Cabina 1");
  });
});
