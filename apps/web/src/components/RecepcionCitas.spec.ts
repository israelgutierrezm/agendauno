import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import RecepcionCitas from "./RecepcionCitas.vue";

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));

const cita = (
  id: string,
  hora: string,
  cliente: string,
  extra: Record<string, unknown> = {},
) => ({
  id,
  tipo: "cita",
  oferta: "Corte",
  oferta_id: "corte",
  oferta_precio_clase: 25000,
  instructor: "Luis",
  instructor_id: "luis",
  sala: null,
  inicia_en: `2030-01-07T${hora}:00Z`,
  termina_en: `2030-01-07T${hora.replace(/^(\d\d)/, (h) => String(Number(h) + 1).padStart(2, "0"))}:00Z`,
  zona_horaria: "UTC",
  capacidad: 1,
  ocupados: 1,
  en_espera: 0,
  estado: "programada",
  cita: {
    reserva_id: `r-${id}`,
    cliente,
    estado: "confirmada",
    asistencia: null,
    orden_id: null,
    por_cobrar: false,
  },
  ...extra,
});

describe("recepción en un negocio de citas", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2030-01-07T08:00:00Z"));
    api.get.mockResolvedValue({
      data: {
        data: [
          cita("c2", "11", "Bea"),
          cita("c1", "10", "Ana", {
            cita: {
              reserva_id: "r-c1",
              cliente: "Ana",
              estado: "confirmada",
              asistencia: "presente",
              orden_id: "o1",
              por_cobrar: true,
            },
          }),
          cita("c3", "12", "Carlos", { estado: "cancelada" }),
          // De otro día (el servidor ensancha el rango): no se muestra.
          { ...cita("c4", "10", "Dani"), inicia_en: "2030-01-08T10:00:00Z" },
        ],
      },
    });
  });
  afterEach(() => vi.useRealTimers());

  it("lista a los clientes del día en orden, con su atención y lo que falta cobrar", async () => {
    const w = mount(RecepcionCitas, {
      props: { fecha: "2030-01-07", sucursalId: "" },
      global: { plugins: [i18n], stubs: { PanelCita: true } },
    });
    await flushPromises();

    const filas = w.findAll(".rc-fila").map((f) => f.text());
    expect(filas).toHaveLength(3);
    expect(filas[0]).toContain("Ana");
    expect(filas[0]).toContain("Llegó");
    expect(filas[0]).toContain("Por cobrar");
    expect(filas[1]).toContain("Bea");
    expect(filas[1]).toContain("Agendada");
    expect(filas[2]).toContain("Cancelada");
    // Ni «lugares» ni «llena»: una cita es de una persona.
    expect(w.text()).not.toContain("lugares");

    expect(w.emitted("resumen")?.at(-1)).toEqual([
      { citas: 2, llegaron: 1, porAtender: 1, porCobrar: 1 },
    ]);
  });
});
