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
      stubs: {
        TarjetasIndicadores: true,
        EstadoVacio: true,
        Teleport: true,
        CortesNomina: true,
      },
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
  it("muestra el nombre del rol personalizado y no su clave técnica", async () => {
    mocks.get.mockImplementation(async (url: string) =>
      url.endsWith("/usuarios")
        ? {
            data: {
              data: [{ id: "c1", nombre: "Daniel", rol: "rol_caja" }],
              roles_detalle: [{ clave: "rol_caja", nombre: "Caja" }],
            },
          }
        : { data: { data: [] } },
    );
    const w = montar();
    await flushPromises();
    expect(w.get("table").text()).toContain("Caja");
    expect(w.get("table").text()).not.toContain("rol_caja");
    w.unmount();
  });
  it("al abrir calcula el mes y lista al equipo, sin clientes", async () => {
    const w = montar();
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/nomina",
      expect.objectContaining({ params: expect.any(Object) }),
    );
    // Solo pide al equipo, no todas las cuentas de clientes.
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/app/demo/usuarios", {
      params: { alcance: "equipo" },
    });
    const texto = w.get("table").text();
    expect(w.find("#es").exists()).toBe(false);
    expect(texto).toContain("Juan Pérez");
    expect(texto).toContain("28 horas");
    expect(texto).toContain("$700.00");
    expect(texto).toContain("Ana Torres");
    expect(texto).toContain("Sin esquema");
    expect(texto).not.toContain("Ana Alumna");
  });

  it("sin nadie con esquema, «Exportar CSV» deshabilitado dice por qué", async () => {
    const con = montar();
    await flushPromises();
    const habilitado = con.get('[data-prueba="exportar-nomina"]');
    expect(habilitado.attributes("disabled")).toBeUndefined();
    expect(habilitado.attributes("title")).toBeUndefined();
    con.unmount();

    mocks.get.mockImplementation(async (url: string) =>
      url.endsWith("/usuarios")
        ? { data: { data: USUARIOS } }
        : { data: { data: [] } },
    );
    const w = montar();
    await flushPromises();
    const exportar = w.get('[data-prueba="exportar-nomina"]');
    expect(exportar.attributes("disabled")).toBeDefined();
    expect(exportar.attributes("title")).toBe(
      "Nadie del equipo tiene esquema de pago: define uno para exportar la nómina.",
    );
    w.unmount();
  });

  it("«Editar esquema» llena el formulario y guardarlo recalcula", async () => {
    const w = montar();
    await flushPromises();
    const editar = w
      .findAll("button")
      .find((b) => b.text() === "Editar esquema");
    await editar!.trigger("click");
    expect(w.get('[role="dialog"]').attributes("aria-label")).toBe(
      "Esquema de pago de Juan Pérez",
    );

    expect((w.get("#es").element as HTMLSelectElement).value).toBe("u1");
    expect((w.get("#em").element as HTMLInputElement).value).toBe("25");
    expect(
      (w.get('input[value="por_hora"]').element as HTMLInputElement).checked,
    ).toBe(true);

    await w.get("#em").setValue("30");
    await w.get('[data-prueba="editor-esquema"]').trigger("submit");
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
  it("separa lo devengado de lo por venir y avisa si la tarifa cambió", async () => {
    mocks.get.mockImplementation(async (url: string) =>
      url.endsWith("/usuarios")
        ? { data: { data: USUARIOS } }
        : {
            data: {
              data: [
                {
                  usuario_id: "u1",
                  usuario: "Juan Pérez",
                  tipo: "por_clase",
                  unidades: 2,
                  monto_minor: 20000,
                  monto_total_minor: 40000,
                  unidades_por_venir: 0,
                  por_venir_minor: 0,
                  vigente_desde: null,
                  moneda: "MXN",
                },
                {
                  usuario_id: "u1",
                  usuario: "Juan Pérez",
                  tipo: "por_clase",
                  unidades: 1,
                  monto_minor: 30000,
                  monto_total_minor: 30000,
                  unidades_por_venir: 3,
                  por_venir_minor: 90000,
                  vigente_desde: "2026-10-10",
                  moneda: "MXN",
                },
              ],
            },
          },
    );
    const w = montar();
    await flushPromises();

    const fila = w.findAll("tbody tr")[0]!;
    // Una sola fila por persona: lo devengado suma las dos tarifas.
    expect(w.findAll("tbody tr")).toHaveLength(2);
    expect(fila.text()).toContain("$700.00");
    expect(fila.text()).toContain("$900.00");
    expect(fila.get('[data-prueba="cambio-tarifa"]').text()).toBe(
      "Tarifa nueva desde el 10/10/2026",
    );
    expect(w.get("tfoot").text()).toContain("$700.00");
    w.unmount();
  });
});
