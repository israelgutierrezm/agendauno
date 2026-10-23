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
  put: vi.fn(),
  sucursal: { value: null as { id: string; nombre: string } | null },
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, put: mocks.put, post: vi.fn(), delete: vi.fn() },
  mensajeDeError: (e: unknown) => (e instanceof Error ? e.message : "Error"),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
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

  it("activa y desactiva un espacio; si es el único de un servicio, lo dice", async () => {
    mocks.put.mockResolvedValueOnce({
      data: {
        data: { ...recurso("1", "Cabina 1", "Roma Norte"), activo: false },
      },
    });
    const w = mount(RecursosView, { global: { plugins: [i18n] } });
    await flushPromises();

    const interruptor = w.get('[data-prueba="activo-1"]');
    expect(interruptor.attributes("aria-checked")).toBe("true");
    await interruptor.trigger("click");
    await flushPromises();
    expect(mocks.put).toHaveBeenCalledWith("/api/v1/app/demo/recursos/1", {
      activo: false,
    });
    expect(w.get('[data-prueba="activo-1"]').attributes("aria-checked")).toBe(
      "false",
    );
    // Confirma qué quedó guardado.
    expect(toast.exito).toHaveBeenCalledWith("Espacio desactivado.");

    mocks.put.mockRejectedValueOnce(
      new Error("Es el único espacio activo de «Masaje» en esta sede."),
    );
    await w.get('[data-prueba="activo-2"]').trigger("click");
    await flushPromises();
    expect(w.get('[role="alert"]').text()).toContain("único espacio activo");
    expect(w.get('[data-prueba="activo-2"]').attributes("aria-checked")).toBe(
      "true",
    );
  });

  it("corrige el nombre y el tipo de un espacio (su sede no cambia)", async () => {
    mocks.put.mockResolvedValueOnce({ data: { data: {} } });
    const w = mount(RecursosView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();
    await w.findAll('[data-prueba="editar-recurso"]')[0]!.trigger("click");
    expect(w.text()).toContain("Editar espacio");
    expect(w.text()).toContain("Sucursal: Roma Norte");
    await w.get("#rn").setValue("Cabina principal");
    await w.get("#rt").setValue("Masaje");
    // «Guardar cambios» va en el pie y envía el formulario del panel.
    expect(
      w
        .findAll("button")
        .find((b) => b.text() === "Guardar cambios")!
        .attributes("form"),
    ).toBe("form-recurso");
    await w.get("#form-recurso").trigger("submit");
    await flushPromises();
    expect(mocks.put).toHaveBeenCalledWith("/api/v1/app/demo/recursos/1", {
      nombre: "Cabina principal",
      tipo: "Masaje",
      modo: "unidad",
    });
    expect(toast.exito).toHaveBeenCalledWith(
      "«Cabina principal» quedó guardado.",
    );
  });
});
