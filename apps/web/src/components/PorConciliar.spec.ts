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
          messages: {
            es: { porConciliar, comun: { reintentar: "Reintentar" } },
          },
        }),
      ],
    },
  });
}

describe("por conciliar", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("sin nada pendiente lo dice, en vez de esconderse", async () => {
    api.get.mockResolvedValue({ data: { data: [] } });
    const w = montar();
    expect(w.text()).toContain("Revisando");
    await flushPromises();

    expect(w.get('[data-prueba="nada-por-conciliar"]').text()).toContain(
      "Nada por conciliar",
    );
  });

  it("si no se pudo consultar, lo dice (no es «nada») y se reintenta", async () => {
    api.get.mockRejectedValueOnce(new Error("Sin red"));
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="error-conciliar"]').text()).toContain(
      "esto no quiere decir que no haya nada",
    );
    expect(w.find('[data-prueba="nada-por-conciliar"]').exists()).toBe(false);

    api.get.mockResolvedValueOnce({ data: { data: [devolucionSinConfirmar] } });
    await w.get('[data-prueba="reintentar-conciliar"]').trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="error-conciliar"]').exists()).toBe(false);
    expect(w.text()).toContain("Ana López");
  });

  it("sin permiso lo dice, y no se confunde con un fallo", async () => {
    api.get.mockRejectedValueOnce({
      isAxiosError: true,
      response: { status: 403 },
    });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Tu rol no puede ver");
    expect(w.find('[data-prueba="error-conciliar"]').exists()).toBe(false);
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

  it("un contracargo dice su monto y qué hacer (ADR 0168)", async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          {
            id: "i3",
            tipo: "contracargo",
            detalle:
              "El banco del cliente disputa el pago de $899.00 MXN. Responde en Stripe antes del 2026-10-27.",
            fecha: null,
            persona: "Ana",
            monto_minor: 89900,
            moneda: "MXN",
            reembolso: null,
          },
        ],
      },
    });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Contracargo de");
    expect(w.text()).toContain("Responde en Stripe");
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

  it("una clase de prueba pagada de nuevo se nombra y se devuelve (ADR 0137)", async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          {
            id: "i9",
            tipo: "primera_vez_repetida",
            detalle:
              "Bea ya había usado su clase de prueba: no se concedió otra.",
            fecha: null,
            persona: "Bea",
            monto_minor: 15000,
            moneda: "MXN",
            reembolso: null,
          },
        ],
      },
    });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Clase de prueba ya usada");
    await w.get("button.tu-enlace").trigger("click");
    expect(w.findAll("button").map((b) => b.text())).toContain(
      "Devolver el pago",
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

  it("un cobro del pago automático por menos se da por pagado; uno de más devuelve solo el excedente", async () => {
    const base = {
      fecha: "2026-10-01T12:00:00Z",
      persona: "Vale Ruiz",
      moneda: "MXN",
      reembolso: null,
      domiciliacion: {
        proveedor: "mercadopago",
        referencia: "pre_1",
        estado: "activa",
      },
    };
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          {
            ...base,
            id: "m1",
            tipo: "monto_distinto",
            detalle: "La pasarela cobró de menos.",
            monto_minor: 99900,
            esperado_minor: 129900,
            excedente_minor: null,
            aplicable: true,
          },
          {
            ...base,
            id: "m2",
            tipo: "monto_distinto",
            detalle: "La pasarela cobró de más.",
            monto_minor: 150000,
            esperado_minor: 129900,
            excedente_minor: 20100,
            aplicable: false,
          },
          {
            ...base,
            id: "c1",
            tipo: "cancelacion_sin_confirmar",
            detalle: "Cancélalo en su panel.",
            monto_minor: null,
            esperado_minor: null,
            excedente_minor: null,
            aplicable: false,
            domiciliacion: { ...base.domiciliacion, estado: "cancelando" },
          },
        ],
      },
    });
    api.get.mockResolvedValue({ data: { data: [] } });
    api.post.mockResolvedValue({ data: { data: {} } });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Pago automático sin cancelar en Mercado Pago");
    const filas = w.findAll("li");
    // De más: solo se ofrece devolver el excedente.
    await filas[1]!.get("button.tu-enlace").trigger("click");
    expect(filas[1]!.text()).toContain("Devolver el excedente");
    expect(filas[1]!.text()).not.toContain("Dar por pagada");
    // Sin cancelar: solo se marca (se cierra sola al confirmarse).
    await filas[2]!.get("button.tu-enlace").trigger("click");
    expect(filas[2]!.text()).not.toContain("Devolver");
    expect(filas[2]!.text()).toContain("Marcar como resuelta");

    // Por menos: se puede dar por pagada.
    await filas[0]!.get("button.tu-enlace").trigger("click");
    expect(filas[0]!.text()).toContain("Dar por pagada");
    await filas[0]!.get("input").setValue("Acordamos el precio anterior.");
    await filas[0]!
      .findAll("button")
      .find((b) => b.text() === "Dar por pagada")!
      .trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/estudio-a/incidencias-cobro/m1/resolver",
      {
        resolucion: "Acordamos el precio anterior.",
        reembolso: null,
        accion: "aplicar",
      },
    );
  });
});
