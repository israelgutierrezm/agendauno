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
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
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
    w.findComponent(BuscarPersona).vm.$emit("update:modelValue", "m1");
    await w
      .findAll("button")
      .find((b) => b.text() === "Transferencia")!
      .trigger("click");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/ordenes", {
      comprador_id: "m1",
      items: [{ producto_id: "p1", cantidad: 1 }],
      codigo_promo: undefined,
    });
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/ordenes/o9/liquidar",
      { metodo: "transferencia" },
    );
  });
});
