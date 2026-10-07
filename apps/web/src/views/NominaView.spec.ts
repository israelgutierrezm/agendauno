import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import NominaView from "./NominaView.vue";

/*
| Nómina: el equipo (sin clientes) con lo que le toca en el periodo, quién no tiene
| esquema y el esquema de cada quien editable desde su fila.
*/

const mocks = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, put: mocks.put },
  fijarBearer: vi.fn(),
  mensajeDeError: () => "Error",
}));

const USUARIOS = [
  { id: "u1", nombre: "Juan Pérez", rol: "instructor" },
  { id: "u2", nombre: "Ana Torres", rol: "recepcionista" },
  { id: "u3", nombre: "Ana Alumna", rol: "miembro" },
];
const NOMINA = [
  {
    usuario_id: "u1",
    usuario: "Juan Pérez",
    tipo: "por_hora",
    unidades: 28,
    monto_minor: 2500,
    monto_total_minor: 70000,
    moneda: "MXN",
  },
];

function montar() {
  return mount(NominaView, {
    global: {
      plugins: [i18n],
      stubs: { TarjetasIndicadores: true, EstadoVacio: true },
    },
  });
}

beforeEach(() => {
  // jsdom no desplaza la página.
  Element.prototype.scrollIntoView = vi.fn();
  setActivePinia(createPinia());
  vi.clearAllMocks();
  useSesionTenantStore().slug = "demo";
  mocks.get.mockImplementation(async (url: string) =>
    url.endsWith("/usuarios")
      ? { data: { data: USUARIOS } }
      : { data: { data: NOMINA } },
  );
  mocks.put.mockResolvedValue({ data: {} });
});

describe("nómina", () => {
  it("al abrir calcula el mes y lista al equipo, sin clientes", async () => {
    const w = montar();
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/nomina",
      expect.objectContaining({ params: expect.any(Object) }),
    );
    const texto = w.get("table").text();
    expect(texto).toContain("Juan Pérez");
    expect(texto).toContain("28 horas");
    expect(texto).toContain("$700.00");
    expect(texto).toContain("Ana Torres");
    expect(texto).toContain("Sin esquema");
    expect(texto).not.toContain("Ana Alumna");
  });

  it("«Editar esquema» llena el formulario y guardarlo recalcula", async () => {
    const w = montar();
    await flushPromises();
    const editar = w
      .findAll("button")
      .find((b) => b.text() === "Editar esquema");
    await editar!.trigger("click");

    expect((w.get("#es").element as HTMLSelectElement).value).toBe("u1");
    expect((w.get("#em").element as HTMLInputElement).value).toBe("25");
    expect(
      (w.get('input[value="por_hora"]').element as HTMLInputElement).checked,
    ).toBe(true);

    await w.get("#em").setValue("30");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(mocks.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/staff/u1/esquema-pago",
      { tipo: "por_hora", monto_minor: 3000, moneda: "MXN" },
    );
    // Guardar vuelve a calcular el periodo.
    expect(
      mocks.get.mock.calls.filter(([u]) => String(u).endsWith("/nomina")),
    ).toHaveLength(2);
  });

  it("el monto y lo que toca van en la moneda del negocio, con su símbolo", async () => {
    useSesionTenantStore().estudio = {
      slug: "demo",
      nombre: "Demo",
      estado: "activo",
      moneda: "EUR",
      pais: "ES",
    };
    mocks.get.mockImplementation(async (url: string) =>
      url.endsWith("/usuarios")
        ? { data: { data: USUARIOS } }
        : { data: { data: [{ ...NOMINA[0], moneda: "EUR" }] } },
    );
    const w = montar();
    await flushPromises();

    expect(w.get("table").text().replace(/\s/g, " ")).toContain("700,00 €");
    await w
      .findAll("button")
      .find((b) => b.text() === "Editar esquema")!
      .trigger("click");
    expect(w.get('[data-prueba="simbolo-monto"]').text()).toBe("€");
  });
});
