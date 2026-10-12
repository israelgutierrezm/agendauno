import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import WidgetView from "./WidgetView.vue";

/*
| Un widget en la web del negocio (ADR 0141): los planes con su botón para comprar,
| que pide sus datos ahí mismo (el formulario de captación de siempre); y fuera de las
| webs registradas, no se muestra.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const ruta = vi.hoisted(() => ({ query: {} as Record<string, string> }));
vi.mock("vue-router", () => ({ useRoute: () => ruta }));
const inserta = vi.hoisted(() => ({ host: null as string | null }));
vi.mock("@/lib/widgets", async (original) => ({
  ...(await original<typeof import("@/lib/widgets")>()),
  hostDeQuienInserta: () => inserta.host,
}));

const WIDGET = {
  id: "w1",
  tipo: "planes",
  nombre: "Precios",
  filtros: { sucursal: null, actividad: null },
  tema: "claro",
  acento: "#e0457b",
  dominios: ["miestudio.com"],
  activo: true,
  negocio: "Grecon",
  modalidad: "clases",
};
const PLAN = {
  id: "p1",
  nombre: "Paquete 8 clases",
  tipo: "paquete",
  precio_minor: 140000,
  moneda: "MXN",
  ilimitado: false,
  creditos_incluidos: 8000,
  vence_si_compra_hoy: "2026-10-31",
};

function montar() {
  return mount(WidgetView, {
    props: { slug: "demo", widget: "w1" },
    global: {
      plugins: [i18n],
      stubs: {
        CalendarioPublico: true,
        FormularioCaptacion: {
          props: ["interes"],
          template:
            '<div data-prueba="formulario">{{ interes?.producto }}</div>',
        },
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  ruta.query = {};
  inserta.host = "www.miestudio.com";
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/widgets/w1")
          ? WIDGET
          : url.endsWith("/captacion")
            ? { id: "f1" }
            : { productos: [PLAN], estudio: { perfil_config: {} } },
      },
    }),
  );
});

describe("widget de planes", () => {
  it("muestra los planes y al comprar pide sus datos ahí mismo", async () => {
    const w = montar();
    await flushPromises();

    const planes = w.get('[data-prueba="widget-planes"]');
    expect(planes.text()).toContain("Paquete 8 clases");
    expect(planes.text()).toContain("$1,400.00");
    await w.get('[data-prueba="lo-quiero"]').trigger("click");
    expect(w.get('[data-prueba="formulario"]').text()).toBe("p1");
    expect(w.text()).toContain("Déjanos tus datos para Paquete 8 clases");
    // Con el color del negocio.
    expect(w.get('[data-prueba="widget"]').attributes("style")).toContain(
      "--acento: #e0457b",
    );
  });

  it("en una web que no registró, no se muestra", async () => {
    inserta.host = "otraweb.com";
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="widget-no-autorizado"]').exists()).toBe(true);
    expect(w.find('[data-prueba="widget-planes"]').exists()).toBe(false);
  });

  it("apagado o sin página, no está disponible", async () => {
    api.get.mockRejectedValue(new Error("404"));
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="widget-no-disponible"]').exists()).toBe(true);
  });
});
