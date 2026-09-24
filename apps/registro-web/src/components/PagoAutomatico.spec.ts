import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import { createI18n } from "vue-i18n";

import { pagoAutomatico } from "@/i18n/locales/gestion.es-MX";
import PagoAutomatico from "./PagoAutomatico.vue";

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/lib/retornoPago", () => ({ useRetornoPago: () => ref(null) }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "estudio-a" }),
}));

function respuesta(automatico: boolean, error: string | null = null) {
  return {
    data: {
      data: {
        disponible: true,
        tarjeta: automatico
          ? { marca: "visa", ultimos4: "4242", expira: "08/30" }
          : null,
        membresias: [
          {
            id: "a1",
            producto: "Mensualidad",
            monto_minor: 129900,
            moneda: "MXN",
            proxima_cobro_en: "2026-10-24",
            estado: "activo",
            automatico,
            error,
          },
        ],
      },
    },
  };
}

function montar() {
  return mount(PagoAutomatico, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { pagoAutomatico } },
        }),
      ],
    },
  });
}

describe("pago automático del alumno", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("muestra la tarjeta, qué se cobra solo y el último rechazo", async () => {
    api.get.mockResolvedValue(
      respuesta(true, "La tarjeta no tiene fondos suficientes."),
    );
    const vista = montar();
    await flushPromises();

    expect(vista.text()).toContain("Visa terminación 4242");
    expect(vista.text()).toContain("vence 08/30");
    expect(vista.text()).toContain("Cobro automático");
    expect(vista.text()).toContain("La tarjeta no tiene fondos suficientes.");
    expect(vista.text()).toContain("Quitar");
  });

  it("con tarjeta ya autorizada, activar no sale de la página y recarga", async () => {
    api.get
      .mockResolvedValueOnce(respuesta(false))
      .mockResolvedValueOnce(respuesta(true));
    api.post.mockResolvedValue({
      data: { data: { estado: "activa", checkout: null } },
    });
    const vista = montar();
    await flushPromises();
    expect(vista.text()).toContain("Te avisamos para pagar");

    await vista.get("button.tu-btn-primario").trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/estudio-a/mi/pago-automatico/a1",
      {},
    );
    expect(vista.text()).toContain("Cobro automático");
  });

  it("no aparece si el negocio no ofrece pago automático", async () => {
    const r = respuesta(false);
    r.data.data.disponible = false;
    api.get.mockResolvedValue(r);
    const vista = montar();
    await flushPromises();

    expect(vista.text()).toBe("");
  });
});
