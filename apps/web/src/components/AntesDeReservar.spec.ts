import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import portal from "@/i18n/locales/portal.es-MX";
import AntesDeReservar from "./AntesDeReservar.vue";

/*
| Antes de reservar (M05): el plan y lo que consume, hasta cuándo cancela sin costo en
| la hora de la sede, o el motivo principal con la acción que sirve. Sin vista previa,
| se puede intentar igual.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Sin conexión" }));
vi.mock("vue-router", () => ({
  RouterLink: {
    props: ["to"],
    template: '<a :data-hash="to.hash"><slot /></a>',
  },
}));

function antes(extra: Record<string, unknown>) {
  return {
    data: {
      data: {
        resultado: "reserva",
        codigo: null,
        motivo: null,
        accion: null,
        mensaje: null,
        unidades: 1000,
        inicia_en: "2030-01-10T14:00:00Z",
        zona_horaria: "America/Mexico_City",
        sucursal: { nombre: "Roma Norte", mapa_url: "https://maps/x" },
        requisitos: null,
        que_llevar: null,
        plan: {
          nombre: "Pack 8 clases",
          ilimitado: false,
          vence: null,
          disponible_despues: 7000,
        },
        de_pago: null,
        // 6 h antes: 02:00 en la Ciudad de México.
        cancelacion: {
          limite: "2030-01-10T08:00:00Z",
          horas_limite: 6,
          penaliza_tarde: true,
          cancela_tarde: true,
        },
        ...extra,
      },
    },
  };
}

function montar() {
  return mount(AntesDeReservar, {
    props: { base: "/api/v1/app/demo", sesionId: "s1" },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, portal } },
        }),
      ],
    },
  });
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2030-01-09T12:00:00Z"));
  api.get.mockReset();
});

describe("antes de reservar", () => {
  it("dice el plan, lo que consume y hasta cuándo cancela; confirmar reserva", async () => {
    api.get.mockResolvedValueOnce(antes({}));
    const w = montar();
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/sesiones/s1/antes-de-reservar",
    );
    expect(w.get('[data-prueba="antes-consumo"]').text()).toBe(
      "Usa Pack 8 clases: se descuenta 1 crédito y te quedan 7 créditos.",
    );
    const cancelacion = w.get('[data-prueba="antes-cancelacion"]').text();
    expect(cancelacion).toContain("Sin costo hasta el jueves, 10 de enero");
    expect(cancelacion).toContain("02:00");
    expect(cancelacion).toContain("Después, cancelar descuenta la clase.");
    expect(w.text()).toContain("Roma Norte");

    await w.get('[data-prueba="confirmar-reserva"]').trigger("click");
    expect(w.emitted("confirmar")?.[0]).toEqual([false]);
  });

  it("sin plan vigente dice el motivo y lleva a revisar su plan, sin botón de reservar", async () => {
    api.get.mockResolvedValueOnce(
      antes({
        resultado: "no_disponible",
        codigo: "ENTITLEMENT_REQUIRED",
        motivo: "vigencia",
        accion: "revisar_vigencia",
        unidades: 0,
        plan: null,
      }),
    );
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="antes-motivo"]').text()).toBe(
      portal.cobertura.motivos.vigencia,
    );
    expect(w.find('[data-prueba="confirmar-reserva"]').exists()).toBe(false);
    const accion = w.get('[data-prueba="antes-accion"]');
    expect(accion.text()).toBe("Revisar mi plan");
    expect(accion.attributes("data-hash")).toBe("#mp-planes");
    // No promete una cancelación de algo que no se reserva.
    expect(w.find('[data-prueba="antes-cancelacion"]').exists()).toBe(false);
  });

  it("si su plan es de otra sucursal, ofrece ver las clases que incluye", async () => {
    api.get.mockResolvedValueOnce(
      antes({
        resultado: "no_disponible",
        codigo: "ENTITLEMENT_REQUIRED",
        motivo: "sucursal",
        accion: "cambiar_sucursal",
        plan: null,
      }),
    );
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="antes-accion"]').trigger("click");
    expect(w.emitted("accion")?.[0]).toEqual(["cambiar_sucursal"]);
  });

  it("sin vista previa se puede intentar reservar (el servidor valida)", async () => {
    api.get.mockRejectedValueOnce(new Error("red"));
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Sin conexión");
    await w.get("button").trigger("click");
    expect(w.emitted("confirmar")?.[0]).toEqual([false]);
  });
});
