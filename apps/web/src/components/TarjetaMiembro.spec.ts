import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { tarjetas } from "@/i18n/locales/gestion.es-MX";
import type { ResumenTarjeta } from "@/lib/resumenTarjeta";
import TarjetaMiembro from "./TarjetaMiembro.vue";

function montar(resumen: ResumenTarjeta | null, extra = {}) {
  return mount(TarjetaMiembro, {
    props: {
      nombre: "Ana",
      nombreCompleto: "Ana Alumna",
      email: "ana@demo.mx",
      celular: "5512345678",
      activo: true,
      resumen,
      ...extra,
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { tarjetas, miembros: esMX.miembros } },
        }),
      ],
    },
  });
}

const base: ResumenTarjeta = {
  membresia: {
    estado: "vigente",
    plan: "Pack 8 clases",
    valido_hasta: "2030-02-20",
    pausada_hasta: null,
    ilimitado: false,
    saldo_unidades: 6500,
    tiene_acceso: true,
  },
  ultima_visita: "2030-01-04T15:00:00Z",
  proxima: {
    inicia_en: "2030-01-08T15:00:00Z",
    zona_horaria: "America/Mexico_City",
    clase: "Nivel 1",
  },
  adeudo: false,
};

describe("tarjeta de miembro", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2030-01-05T18:00:00Z"));
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("muestra contacto, plan, lo disponible, vencimiento y visitas", () => {
    const w = montar(base);
    const texto = w.text();
    expect(texto).toContain("ana@demo.mx · 5512345678");
    expect(texto).toContain("Pack 8 clases");
    expect(texto).toContain("6.5 disponibles");
    expect(texto).toContain("vence el 20 feb");
    expect(texto).toContain("ayer");
    expect(texto).toMatch(/mar.*8.*ene.*09:00/);
  });

  it("marca solo lo que pide atención: vencida y adeudo", () => {
    const w = montar({
      ...base,
      membresia: {
        ...base.membresia,
        estado: "vencida",
        valido_hasta: "2030-01-02",
        tiene_acceso: false,
      },
      adeudo: true,
      ultima_visita: null,
      proxima: null,
    });
    expect(w.text()).toContain("venció el 2 ene");
    expect(w.text()).toContain("Tiene un pago pendiente");
    expect(w.text()).toContain("Aún no asiste");
    expect(w.text()).toContain("Sin reservas");
    expect(w.text()).not.toContain("disponibles");
  });

  it("sin membresía lo dice, y un suspendido se marca junto al nombre", () => {
    const w = montar(
      {
        ...base,
        membresia: {
          ...base.membresia,
          estado: "sin",
          plan: null,
          tiene_acceso: false,
        },
      },
      { activo: false },
    );
    expect(w.text()).toContain("Sin membresía");
    expect(w.text()).toContain(esMX.miembros.suspendido);
  });
});
