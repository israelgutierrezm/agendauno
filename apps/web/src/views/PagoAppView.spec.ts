import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import { pagoEnLinea } from "@/i18n/locales/gestion.es-MX";
import PagoAppView from "./PagoAppView.vue";

/*
| Regreso de un pago iniciado en la app (ADR 0211): confirma el pago con el servidor y
| ofrece volver a la app con su esquema; un esquema que no es de una app no se enlaza.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  route: { query: {} as Record<string, string> },
}));
vi.mock("@/lib/api", () => ({ api: { get: mocks.get } }));
vi.mock("vue-router", () => ({
  useRoute: () => mocks.route,
  useRouter: () => ({ replace: vi.fn() }),
}));

function montar() {
  return mount(PagoAppView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...es, pagoEnLinea } },
        }),
      ],
    },
  });
}

describe("regreso del pago a la app", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.get.mockResolvedValue({ data: { data: { estado: "confirmado" } } });
  });

  it("confirma el pago con el servidor del negocio y ofrece volver a la app", async () => {
    mocks.route.query = {
      estudio: "pilates",
      app: "agendauno",
      pago: "exito",
      ref_pago: "01PAGO",
    };
    const w = montar();
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/pilates/pagos/01PAGO/confirmacion",
    );
    expect(w.get('[data-prueba="volver-a-la-app"]').attributes("href")).toBe(
      "agendauno://pagos?ref=01PAGO&pago=confirmado",
    );
  });

  it("no enlaza un esquema que no es de una app", async () => {
    mocks.route.query = {
      estudio: "pilates",
      app: "javascript",
      pago: "exito",
      ref_pago: "01PAGO",
    };
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="volver-a-la-app"]').exists()).toBe(false);
  });
});
