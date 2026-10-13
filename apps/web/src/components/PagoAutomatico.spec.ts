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
vi.mock("@/lib/openpay", () => ({
  tokenizarTarjeta: vi.fn(async () => ({
    token_id: "tok_1",
    device_session_id: "dev_1",
  })),
}));
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

  it("las membresías de una persona a cargo dicen para quién son y se cobran a su tarjeta", async () => {
    const r = respuesta(false);
    Object.assign(r.data.data.membresias[0]!, {
      para: { id: "p2", nombre: "Sofía" },
    });
    api.get.mockResolvedValue(r);
    const vista = montar();
    await flushPromises();

    expect(vista.get('[data-prueba="pago-para"]').text()).toBe("Para Sofía");
    expect(vista.text()).toContain(
      "Las membresías de las personas a tu cargo se cobran a tu tarjeta.",
    );
  });

  it("dice que se está cancelando y ofrece autorizar el precio nuevo del plan", async () => {
    const base = respuesta(true).data.data;
    api.get.mockResolvedValue({
      data: {
        data: {
          ...base,
          membresias: [
            {
              ...base.membresias[0],
              precio_nuevo_minor: 149900,
            },
            {
              ...base.membresias[0],
              id: "a2",
              producto: "Plan Yoga",
              automatico: false,
              cancelando: true,
            },
          ],
        },
      },
    });
    api.post.mockResolvedValue({
      data: {
        data: { estado: "redirect", checkout: { url: "https://mp/checkout" } },
      },
    });
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="cancelando"]').text()).toBe(
      "Cancelando en la pasarela",
    );
    const precio = w.get('[data-prueba="precio-nuevo"]');
    expect(precio.text()).toContain("$1,499.00");
    expect(precio.text()).toContain("$1,299.00");
  });

  it("autorizar con la tarjeta capturada espera al servidor y su rechazo queda en la tarjeta", async () => {
    api.get.mockResolvedValue(respuesta(false));
    let rechazar: (e: unknown) => void = () => undefined;
    api.post
      .mockResolvedValueOnce({
        data: {
          data: {
            estado: "formulario",
            formulario: {
              proveedor: "openpay",
              merchant_id: "m",
              public_key: "k",
              sandbox: true,
            },
          },
        },
      })
      .mockReturnValueOnce(
        new Promise((_, r) => {
          rechazar = r;
        }),
      );
    const vista = montar();
    await flushPromises();
    await vista.get("button.tu-btn-primario").trigger("click");
    await flushPromises();

    const autorizar = () =>
      vista.findAll("button").find((b) => b.text() === "Autorizar tarjeta")!;
    await vista.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/estudio-a/mi/pago-automatico/a1",
      { token_id: "tok_1", device_session_id: "dev_1" },
    );
    // Mientras el servidor responde, no se puede volver a autorizar.
    expect(autorizar().attributes("disabled")).toBeDefined();

    rechazar("Tarjeta rechazada por el banco.");
    await flushPromises();
    expect(vista.get('[data-prueba="error-tarjeta"]').text()).toBe(
      "Tarjeta rechazada por el banco.",
    );
    expect(autorizar().attributes("disabled")).toBeUndefined();
    expect(vista.findAll('[role="alert"]')).toHaveLength(1);
  });
});
