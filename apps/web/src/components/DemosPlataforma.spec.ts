import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import DemosPlataforma from "./DemosPlataforma.vue";

/*
| Superadmin › Demos (ADR 0204): cuántas hay agendadas, las agendadas por páginas con
| sus filtros y lo que contó cada quien, y cancelar una como acción de peligro.
*/

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("axios", () => ({ default: { create: () => http } }));
vi.mock("@/lib/api", () => ({
  mensajeDeError: () => "Error",
  erroresPorCampo: () => ({}),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
const confirmar = vi.hoisted(() => vi.fn());
vi.mock("@/lib/confirmar", () => ({ confirmar }));

const RESUMEN = {
  hoy: 2,
  semana: 5,
  proximas: 9,
  atendidas: 4,
  no_asistio: 1,
  canceladas: 3,
};
const futura = {
  id: "d1",
  producto: "agendauno",
  inicia_en: "2099-01-01T16:00:00+00:00",
  nombre: "Ana López",
  negocio: "Fluo Pilates",
  giro: "pilates",
  correo: "ana@fluo.mx",
  telefono: "525512345678",
  acepta_whatsapp: false,
  estado: "agendada",
  calificacion: {
    operando: true,
    tamano: "51-100",
    sucursales: "2-4",
    objetivos: ["cobros", "retencion"],
  },
};

function montar() {
  return mount(DemosPlataforma, {
    props: { apiUrl: "http://api", token: "tk" },
    global: { plugins: [i18n] },
  });
}

enableAutoUnmount(afterEach);
beforeEach(() => {
  vi.clearAllMocks();
  vi.useRealTimers();
  http.get.mockResolvedValue({
    data: {
      data: [futura],
      meta: {
        page: 1,
        ultima_pagina: 1,
        total: 1,
        per_page: 15,
        resumen: RESUMEN,
      },
    },
  });
  http.put.mockResolvedValue({ data: { data: futura } });
});

describe("DemosPlataforma", () => {
  it("muestra cuántas hay y avisa al menú las próximas", async () => {
    const w = montar();
    await flushPromises();
    const cifra = (c: string) => w.get(`[data-cifra="${c}"] .dp-numero`).text();
    expect(cifra("hoy")).toBe("2");
    expect(cifra("semana")).toBe("5");
    expect(cifra("proximas")).toBe("9");
    expect(cifra("atendidas")).toBe("4");
    expect(w.emitted("proximas")?.[0]).toEqual([9]);
    // Lo que contó de su negocio, en palabras.
    const fila = w.get('[data-demo="d1"]').text();
    expect(fila).toContain("Ya opera · 51-100 · 2 a 4");
    expect(fila).toContain("Quiere: Cobros y pagos en línea, Que regresen");
  });

  it("filtra por cuáles, producto y búsqueda", async () => {
    vi.useFakeTimers();
    const w = montar();
    await flushPromises();
    expect(http.get).toHaveBeenLastCalledWith("/api/v1/plataforma/demos", {
      params: { page: 1, ver: "proximas", producto: undefined, q: undefined },
    });

    await w.get('[data-prueba="demos-ver"]').setValue("pasadas");
    await w.get('[data-prueba="demos-producto"]').setValue("turnouno");
    await w.get('[data-prueba="demos-buscar"]').setValue("fluo");
    await vi.advanceTimersByTimeAsync(350);
    expect(http.get).toHaveBeenLastCalledWith("/api/v1/plataforma/demos", {
      params: { page: 1, ver: "pasadas", producto: "turnouno", q: "fluo" },
    });
  });

  it("cancelar una demo próxima se confirma como acción de peligro", async () => {
    const w = montar();
    await flushPromises();
    const boton = w.get('[data-prueba="cancelar-demo-d1"]');
    expect(boton.classes()).toContain("tu-btn-peligro");

    confirmar.mockResolvedValue(false);
    await boton.trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalledWith(
      expect.stringContaining("Ana López"),
      expect.objectContaining({ peligro: true }),
    );
    expect(http.put).not.toHaveBeenCalled();

    confirmar.mockResolvedValue(true);
    await boton.trigger("click");
    await flushPromises();
    expect(http.put).toHaveBeenCalledWith("/api/v1/plataforma/demos/d1", {
      estado: "cancelada",
    });
  });
});
