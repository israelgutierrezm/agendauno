import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import QuienCuentaRenta from "./QuienCuentaRenta.vue";

/*
| Quién cuenta en el cobro del mes: en un diálogo, una tabla por páginas con por qué
| cuenta cada quien; buscar acota la lista, no lo que se cobra.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ esCitas: false, puede: () => true }),
}));

const respuesta = (quienes: object[], total: number, page = 1) => ({
  data: {
    data: { metrica: "alumnos_activos", cantidad: 3, quienes },
    meta: { total, page, per_page: 25, ultima_pagina: Math.ceil(total / 25) },
  },
});

beforeEach(() => {
  vi.useFakeTimers();
  vi.clearAllMocks();
  api.get.mockImplementation((_url: string, { params }) =>
    Promise.resolve(
      params.q
        ? respuesta([{ id: "c", nombre: "Caro", reservas: 0, compras: 1 }], 1)
        : respuesta(
            [
              { id: "a", nombre: "Ana", reservas: 2, compras: 1 },
              { id: "b", nombre: "Beto", reservas: 0, compras: 1 },
              { id: "c", nombre: "Caro", reservas: 0, compras: 1 },
            ],
            3,
          ),
    ),
  );
});
afterEach(() => {
  vi.useRealTimers();
  document.body.style.overflow = "";
});

function montar() {
  return mount(QuienCuentaRenta, {
    props: { abierto: true, base: "/api/v1/app/demo", titulo: "Quién cuenta" },
    global: {
      plugins: [i18n],
      stubs: { Teleport: true, RouterLink: { template: "<a><slot /></a>" } },
    },
  });
}

describe("quién cuenta en la renta", () => {
  it("muestra una tabla con por qué cuenta cada quien, por páginas", async () => {
    const w = montar();
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/renta/quien-cuenta",
      {
        params: { page: 1, per_page: 25 },
      },
    );
    expect(w.get('[data-prueba="resumen-quien"]').text()).toBe(
      "Cuentan 3 alumnos",
    );
    const filas = w.findAll('[data-prueba="fila-quien"]');
    expect(filas).toHaveLength(3);
    expect(filas[0]!.text()).toContain("Ana");
    expect(filas[0]!.findAll("td").map((c) => c.text())).toEqual([
      "Ana",
      "2",
      "1",
    ]);
    // Sin reservas en el mes se ve «—», no un cero que confunda.
    expect(filas[1]!.findAll("td")[1]!.text()).toBe("—");
    w.unmount();
  });

  it("buscar acota la lista sin cambiar cuántos cuentan", async () => {
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="buscar-quien"]').setValue("car");
    vi.advanceTimersByTime(300);
    await flushPromises();

    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/renta/quien-cuenta",
      { params: { page: 1, per_page: 25, q: "car" } },
    );
    expect(w.findAll('[data-prueba="fila-quien"]')).toHaveLength(1);
    expect(w.get('[data-prueba="resumen-quien"]').text()).toBe(
      "Cuentan 3 alumnos",
    );
    w.unmount();
  });
});
