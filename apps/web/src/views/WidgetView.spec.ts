import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import WidgetView from "./WidgetView.vue";

/*
| Un widget en la web del negocio (ADR 0141): termina en la reserva o la compra. En los
| planes, «Lo quiero»: quien ya es cliente lo compra con su cuenta (otra pestaña, con el
| plan elegido) y quien no, deja sus datos ahí mismo; en citas, cada servicio abre la
| página para agendar con el servicio y la sede ya elegidos; fuera de las webs
| registradas, no se muestra.
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
  id: "01JABCDEFGHJKMNPQRSTVWXYZ0",
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
  it("«Lo quiero»: el cliente lo compra con su cuenta y quien no, deja sus datos", async () => {
    const w = montar();
    await flushPromises();

    const planes = w.get('[data-prueba="widget-planes"]');
    expect(planes.text()).toContain("Paquete 8 clases");
    expect(planes.text()).toContain("$1,400.00");
    expect(w.get('[data-prueba="lo-quiero"]').text()).toBe("Lo quiero");
    await w.get('[data-prueba="lo-quiero"]').trigger("click");
    // Ya cliente: comprar con su cuenta, en otra pestaña y con el plan elegido.
    const comprar = w.get('[data-prueba="widget-reservar-cuenta"]');
    expect(comprar.text()).toBe("Ya soy cliente: comprar");
    expect(comprar.attributes("target")).toBe("_blank");
    expect(
      new URL(comprar.attributes("href")!, "https://x.test").searchParams.get(
        "volver",
      ),
    ).toBe(`/mi-cuenta?plan=${PLAN.id}`);
    // Aún no cliente: sus datos para ese plan.
    expect(w.get('[data-prueba="formulario"]').text()).toBe(PLAN.id);
    expect(w.text()).toContain("Déjanos tus datos para Paquete 8 clases");
    // Con el color del negocio.
    expect(w.get('[data-prueba="widget"]').attributes("style")).toContain(
      "--acento: #e0457b",
    );
  });

  it("quien ya es cliente entra sin perder el plan que eligió", async () => {
    const w = montar();
    await flushPromises();
    const entrar = () =>
      new URL(
        w.get('[data-prueba="widget-entrar"]').attributes("href")!,
        "https://x.test",
      );
    expect(entrar().searchParams.get("volver")).toBeNull();

    await w.get('[data-prueba="lo-quiero"]').trigger("click");
    expect(entrar().searchParams.get("estudio")).toBe("demo");
    expect(entrar().searchParams.get("volver")).toBe(
      `/mi-cuenta?plan=${PLAN.id}`,
    );
  });

  it("sin formulario de captación, va directo a comprar con su cuenta", async () => {
    api.get.mockImplementation((url: string) =>
      url.endsWith("/captacion")
        ? Promise.reject(new Error("404"))
        : Promise.resolve({
            data: {
              data: url.endsWith("/widgets/w1")
                ? WIDGET
                : { productos: [PLAN], estudio: { perfil_config: {} } },
            },
          }),
    );
    const abrir = vi.spyOn(window, "open").mockReturnValue(null);
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="lo-quiero"]').trigger("click");
    expect(abrir).toHaveBeenCalledWith(
      expect.stringContaining(
        `volver=${encodeURIComponent(`/mi-cuenta?plan=${PLAN.id}`)}`,
      ),
      "_blank",
      "noopener",
    );
    abrir.mockRestore();
  });

  it("en citas, cada servicio abre la página para agendar con el servicio y la sede", async () => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/widgets/w1")
            ? {
                ...WIDGET,
                tipo: "agendar",
                modalidad: "citas",
                filtros: { sucursal: "S1", actividad: null },
              }
            : url.endsWith("/citas/opciones")
              ? {
                  servicios: [
                    {
                      id: "O1",
                      nombre: "Corte clásico",
                      precio_minor: 25000,
                      moneda: "MXN",
                      duracion_minutos: 30,
                      anticipo_minor: 10000,
                    },
                  ],
                }
              : { id: "f1" },
        },
      }),
    );
    const w = montar();
    await flushPromises();

    const servicios = w.get('[data-prueba="widget-servicios"]');
    expect(servicios.text()).toContain("Corte clásico");
    expect(servicios.text()).toContain("30 min");
    expect(servicios.text()).toContain("$250.00");
    expect(servicios.text()).toContain("Apartas con $100.00");
    const agendar = w.get('[data-prueba="widget-agendar"]');
    expect(agendar.attributes("href")).toBe(
      "/agendar/demo?servicio=O1&sucursal=S1",
    );
    expect(agendar.attributes("target")).toBe("_blank");
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

  it("lleva la leyenda de la plataforma, también dentro de otra web (ADR 0167)", async () => {
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="hecho-con"]').exists()).toBe(true);
  });
});
