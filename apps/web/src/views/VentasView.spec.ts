import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import BuscarPersona from "@/components/BuscarPersona.vue";
import { i18n } from "@/i18n";
import VentasView from "./VentasView.vue";

/*
| Vender un plan con el patrón del Mostrador: los planes como tarjetas, la venta a
| la derecha (cliente, forma de pago y total) y las ventas recientes con su estado.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  confirmar: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post },
  mensajeDeError: () => "Error",
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: mocks.confirmar }));
vi.mock("@/lib/acceso", () => ({ puedeEntrar: () => false }));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  puede: () => true,
  moneda: "MXN",
  pais: "MX",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
}));

const plan = (id: string, nombre: string, tipo: string, precio: number) => ({
  id,
  nombre,
  tipo,
  precio_minor: precio,
  moneda: "MXN",
  ilimitado: false,
  creditos_incluidos: 8000,
  unidades_por_ciclo: null,
  archivado: false,
  ofertas: [],
});

beforeEach(() => {
  vi.clearAllMocks();
  sesion.moneda = "MXN";
  sesion.pais = "MX";
  mocks.confirmar.mockResolvedValue(true);
  mocks.post.mockImplementation((url: string) =>
    Promise.resolve({
      data: { data: url.endsWith("/ordenes") ? { id: "o9" } : {} },
    }),
  );
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/miembros")
          ? [{ id: "m1", nombre: "Ana", nombre_completo: "Ana López" }]
          : url.endsWith("/productos")
            ? [
                plan("p1", "Paquete 8 clases", "paquete", 120000),
                plan("p2", "Mensualidad", "membresia", 150000),
              ]
            : url.endsWith("/ordenes")
              ? [
                  {
                    id: "o1",
                    comprador: "Bea",
                    estado: "pendiente",
                    total_minor: 150000,
                    moneda: "MXN",
                    metodo_pago: null,
                    pagada_en: null,
                    lineas: [{ producto: "Mensualidad", cantidad: 1 }],
                  },
                ]
              : [],
      },
    }),
  );
});

describe("vender un plan", () => {
  it("elige el plan en tarjetas y cobra con la forma de pago elegida", async () => {
    const w = mount(VentasView, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(w.text()).toContain("Órdenes por cobrar");
    expect(w.text()).toContain("Pendiente");
    const cobrar = w.get('[data-prueba="cobrar-plan"]');
    expect(cobrar.attributes("disabled")).toBeDefined();

    await w.findAll('[data-prueba="plan"]')[0]!.trigger("click");
    expect(w.get('[data-prueba="venta-plan"]').text()).toContain(
      "Paquete 8 clases",
    );
    // La persona se elige en el buscador (busca en el servidor).
    const buscador = w.findComponent(BuscarPersona);
    buscador.vm.$emit("elegir", { id: "m1", nombre: "Ana López" });
    buscador.vm.$emit("update:modelValue", "m1");
    await w
      .findAll("button")
      .find((b) => b.text() === "Transferencia")!
      .trigger("click");
    await w.get("form").trigger("submit");
    await flushPromises();

    // Se vende y se cobra de una vez (si el cobro no pasa, no queda la orden).
    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/ordenes", {
      comprador_id: "m1",
      items: [{ producto_id: "p1", cantidad: 1 }],
      codigo_promo: undefined,
      liquidar: { metodo: "transferencia", clave: expect.any(String) },
    });
    // La venta siguiente no queda a nombre de la anterior: se vuelve a elegir a
    // quién (el formulario sigue a la vista mientras se actualiza).
    expect(w.findComponent(BuscarPersona).props("modelValue")).toBe("");
    expect(
      w.get('[data-prueba="cobrar-plan"]').attributes("disabled"),
    ).toBeDefined();
  });

  it("un código escrito pero no aplicado no se manda: se cobra lo que se confirmó", async () => {
    const w = mount(VentasView, { global: { plugins: [i18n] } });
    await flushPromises();
    await w.findAll('[data-prueba="plan"]')[0]!.trigger("click");
    const buscador = w.findComponent(BuscarPersona);
    buscador.vm.$emit("elegir", { id: "m1", nombre: "Ana López" });
    buscador.vm.$emit("update:modelValue", "m1");
    await w.get("#vpromo").setValue("VERANO20");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/ordenes",
      expect.objectContaining({ codigo_promo: undefined }),
    );
  });

  it("si no se pudo saber si le toca inscripción, no se cobra hasta reintentar", async () => {
    const base = mocks.get.getMockImplementation()!;
    let falla = true;
    mocks.get.mockImplementation((url: string) =>
      url.endsWith("/productos")
        ? Promise.resolve({
            data: {
              data: [
                {
                  ...plan("p2", "Mensualidad", "membresia", 150000),
                  cobra_inscripcion: true,
                },
              ],
            },
          })
        : url.endsWith("/miembros/m1/cuotas")
          ? falla
            ? Promise.reject(new Error("sin red"))
            : Promise.resolve({ data: { data: { inscripcion_minor: 50000 } } })
          : base(url),
    );
    const w = mount(VentasView, { global: { plugins: [i18n] } });
    await flushPromises();
    await w.findAll('[data-prueba="plan"]')[0]!.trigger("click");
    const buscador = w.findComponent(BuscarPersona);
    buscador.vm.$emit("elegir", { id: "m1", nombre: "Ana López" });
    buscador.vm.$emit("update:modelValue", "m1");
    await flushPromises();

    expect(w.find('[data-prueba="cuotas-error"]').exists()).toBe(true);
    expect(
      w.get('[data-prueba="cobrar-plan"]').attributes("disabled"),
    ).toBeDefined();

    falla = false;
    await w.get('[data-prueba="cuotas-error"]').get("button").trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="cuotas-error"]').exists()).toBe(false);
    expect(w.get(".vv-total").text()).toContain("$2,000.00");
    expect(
      w.get('[data-prueba="cobrar-plan"]').attributes("disabled"),
    ).toBeUndefined();
  });

  it("la inscripción se suma al total antes de cobrar; quien puede la exenta con motivo", async () => {
    const base = mocks.get.getMockImplementation()!;
    mocks.get.mockImplementation((url: string) =>
      url.endsWith("/productos")
        ? Promise.resolve({
            data: {
              data: [
                {
                  ...plan("p2", "Mensualidad", "membresia", 150000),
                  cobra_inscripcion: true,
                },
              ],
            },
          })
        : url.endsWith("/miembros/m1/cuotas")
          ? Promise.resolve({ data: { data: { inscripcion_minor: 50000 } } })
          : base(url),
    );
    const w = mount(VentasView, { global: { plugins: [i18n] } });
    await flushPromises();
    await w.findAll('[data-prueba="plan"]')[0]!.trigger("click");
    const buscador = w.findComponent(BuscarPersona);
    buscador.vm.$emit("elegir", { id: "m1", nombre: "Ana López" });
    buscador.vm.$emit("update:modelValue", "m1");
    await flushPromises();

    expect(w.get('[data-prueba="inscripcion-venta"]').text()).toContain(
      "$500.00",
    );
    expect(w.get(".vv-total").text()).toContain("$2,000.00");

    // Exentarla pide un motivo; el total vuelve al del plan.
    await w
      .get('[data-prueba="inscripcion-venta"] input[type="checkbox"]')
      .setValue(true);
    expect(w.get(".vv-total").text()).toContain("$1,500.00");
    expect(
      w.get('[data-prueba="cobrar-plan"]').attributes("disabled"),
    ).toBeDefined();
    await w
      .get('[data-prueba="motivo-exencion"]')
      .setValue("Reingreso pactado");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/ordenes", {
      comprador_id: "m1",
      items: [{ producto_id: "p2", cantidad: 1 }],
      codigo_promo: undefined,
      exentar_inscripcion: true,
      motivo_exencion: "Reingreso pactado",
      liquidar: { metodo: "efectivo", clave: expect.any(String) },
    });
  });

  it("un plan con permanencia pide que el cliente acepte sus condiciones", async () => {
    const base = mocks.get.getMockImplementation()!;
    mocks.get.mockImplementation((url: string) =>
      url.endsWith("/productos")
        ? Promise.resolve({
            data: {
              data: [
                {
                  ...plan("p2", "Mensualidad", "membresia", 150000),
                  permanencia_meses: 6,
                  condiciones: "Permanencia mínima de 6 meses.",
                },
              ],
            },
          })
        : base(url),
    );
    const w = mount(VentasView, { global: { plugins: [i18n] } });
    await flushPromises();
    await w.findAll('[data-prueba="plan"]')[0]!.trigger("click");
    const buscador = w.findComponent(BuscarPersona);
    buscador.vm.$emit("elegir", { id: "m1", nombre: "Ana López" });
    buscador.vm.$emit("update:modelValue", "m1");
    await flushPromises();

    expect(w.get('[data-prueba="condiciones-venta"]').text()).toContain(
      "Permanencia mínima de 6 meses.",
    );
    expect(
      w.get('[data-prueba="cobrar-plan"]').attributes("disabled"),
    ).toBeDefined();
    await w
      .get('[data-prueba="condiciones-venta"] input[type="checkbox"]')
      .setValue(true);
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/ordenes",
      expect.objectContaining({ acepta_condiciones: true }),
    );
  });

  it("«Vendido hoy» va en la moneda del negocio, no en pesos", async () => {
    sesion.moneda = "EUR";
    sesion.pais = "ES";
    const w = mount(VentasView, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(w.text().replace(/\s/g, " ")).toContain("0,00 €");
    expect(w.text()).not.toContain("$0.00");
  });

  it("los indicadores son los de hoy en el negocio, del API, y solo pide las ventas recientes", async () => {
    const base = mocks.get.getMockImplementation()!;
    mocks.get.mockImplementation((url: string, config?: unknown) =>
      url.endsWith("/ordenes")
        ? Promise.resolve({
            data: {
              data: [],
              meta: {
                total: 57,
                resumen: {
                  ventas_hoy: 3,
                  vendido_hoy: [{ moneda: "MXN", total_minor: 450000 }],
                  por_cobrar: 9,
                },
              },
            },
          })
        : base(url, config),
    );
    const w = mount(VentasView, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith("/api/v1/app/demo/ordenes", {
      params: { per_page: 20 },
    });
    const texto = w.text().replace(/\s/g, " ");
    expect(texto).toContain("$4,500.00");
    expect(texto).toContain("9");
  });

  it("si no se pudo consultar, lo dice y deja reintentar (no «Primero crea un producto»)", async () => {
    const base = mocks.get.getMockImplementation()!;
    mocks.get.mockImplementation(() => Promise.reject(new Error("caído")));
    const w = mount(VentasView, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(w.get('[data-prueba="error-carga-ventas"]').text()).toContain(
      "No se pudieron cargar los planes y las ventas.",
    );
    expect(w.text()).not.toContain("Primero crea un producto.");
    expect(w.text()).not.toContain("Sin ventas aún.");

    mocks.get.mockImplementation(base);
    await w.get('[data-prueba="error-carga-ventas"] button').trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="error-carga-ventas"]').exists()).toBe(false);
    expect(w.findAll('[data-prueba="plan"]').length).toBe(2);
  });
});
