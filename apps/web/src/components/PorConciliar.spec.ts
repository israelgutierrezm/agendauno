import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { porConciliar } from "@/i18n/locales/gestion.es-MX";
import PorConciliar from "./PorConciliar.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "estudio-a",
    puede: () => true,
    moneda: "PEN",
    pais: "PE",
  }),
}));

const devolucionSinConfirmar = {
  id: "i1",
  tipo: "reembolso_incierto",
  detalle: "La pasarela no confirmó la devolución.",
  fecha: "2026-09-25T18:00:00Z",
  persona: "Ana López",
  monto_minor: 89900,
  moneda: "MXN",
  reembolso: {
    estado: "incierto",
    monto_minor: 89900,
    moneda: "MXN",
    proveedor: "stripe",
    motivo_fallo: "La pasarela no respondió.",
  },
};

function montar() {
  return mount(PorConciliar, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { porConciliar } },
        }),
      ],
    },
  });
}

describe("por conciliar", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("no se muestra si no hay nada pendiente", async () => {
    api.get.mockResolvedValue({ data: { data: [] } });
    const w = montar();
    await flushPromises();

    expect(w.text()).toBe("");
  });

  it("una devolución sin confirmar se resuelve diciendo si se hizo, con una nota", async () => {
    api.get.mockResolvedValueOnce({ data: { data: [devolucionSinConfirmar] } });
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    api.post.mockResolvedValue({ data: { data: {} } });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Ana López");
    await w.get("button.tu-enlace").trigger("click");

    // Sin nota no se envía.
    const siSeDevolvio = w
      .findAll("button")
      .find((b) => b.text() === "Sí se devolvió");
    await siSeDevolvio?.trigger("click");
    expect(api.post).not.toHaveBeenCalled();
    expect(w.text()).toContain("Escribe qué revisaste.");

    await w.get("input").setValue("En Stripe aparece devuelta");
    await siSeDevolvio?.trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/estudio-a/incidencias-cobro/i1/resolver",
      {
        resolucion: "En Stripe aparece devuelta",
        reembolso: "aprobado",
        accion: null,
      },
    );
    expect(w.emitted("cambio")).toHaveLength(1);
  });

  it("un pago tardío se devuelve desde la bandeja", async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          {
            id: "i2",
            tipo: "pago_tardio",
            detalle: "Llegó el pago cuando su apartado ya había vencido.",
            fecha: null,
            persona: "Bea",
            monto_minor: 25000,
            moneda: "MXN",
            reembolso: null,
          },
        ],
      },
    });
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    api.post.mockResolvedValue({ data: { data: {} } });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Pago tardío de");
    await w.get("button.tu-enlace").trigger("click");
    await w.get("input").setValue("Se le avisó");
    await w
      .findAll("button")
      .find((b) => b.text() === "Devolver el pago")
      ?.trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/estudio-a/incidencias-cobro/i2/resolver",
      { resolucion: "Se le avisó", reembolso: null, accion: "devolver" },
    );
  });

  it("un monto sin moneda va en la del negocio, no en pesos", async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          {
            id: "i3",
            tipo: "pago_tardio",
            detalle: "Llegó el pago cuando su apartado ya había vencido.",
            fecha: null,
            persona: "Bea",
            monto_minor: 25000,
            moneda: null,
            reembolso: null,
          },
        ],
      },
    });
    const w = montar();
    await flushPromises();

    expect(w.text().replace(/\s/g, " ")).toContain("S/ 250.00");
    expect(w.text()).not.toContain("MXN");
  });
});
