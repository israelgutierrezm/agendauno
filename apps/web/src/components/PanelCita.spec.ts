import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n as i18nApp } from "@/i18n";
import type { SesionAgenda } from "@/lib/agenda";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import PanelCita from "./PanelCita.vue";

/*
| Lo delicado de una cita se confirma y se puede corregir: cobrar en caja, marcar
| asistencia (y corregirla) y la forma de pago de un cobro en caja (ADR 0086).
*/

const mocks = vi.hoisted(() => ({
  post: vi.fn(),
  put: vi.fn(),
  confirmar: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: { post: mocks.post, put: mocks.put },
  fijarBearer: vi.fn(),
  mensajeDeError: () => "Error",
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: mocks.confirmar }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

function cita(extra: Partial<NonNullable<SesionAgenda["cita"]>> = {}) {
  return {
    id: "s1",
    tipo: "cita",
    estado: "programada",
    oferta: "Arreglo de barba",
    oferta_id: "o1",
    oferta_precio_clase: 15000,
    instructor: "Beto",
    instructor_id: "i1",
    sala: null,
    inicia_en: "2099-10-01T20:15:00Z",
    termina_en: "2099-10-01T20:35:00Z",
    zona_horaria: "America/Mexico_City",
    cita: {
      reserva_id: "r1",
      cliente: "Israel Gutierrez",
      estado: "confirmada",
      asistencia: null,
      orden_id: "ord1",
      por_cobrar: true,
      ...extra,
    },
  } as unknown as SesionAgenda;
}

function montar(sesion: SesionAgenda) {
  return mount(PanelCita, {
    props: {
      abierto: true,
      base: "/api/v1/app/demo",
      sesion,
      catalogo: ["o1"],
      puedeMarcar: true,
      puedeCobrar: true,
      puedeCancelar: true,
    },
    global: {
      plugins: [i18nApp],
      stubs: {
        PanelLateral: {
          props: ["abierto", "titulo"],
          template: '<div v-if="abierto"><slot /></div>',
        },
        CambiarHorario: true,
        ConfirmarCancelacion: true,
      },
    },
  });
}

function boton(w: ReturnType<typeof montar>, texto: string) {
  const b = w.findAll("button").find((x) => x.text().startsWith(texto));
  if (!b) {
    throw new Error(`Sin botón «${texto}»`);
  }
  return b;
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
  mocks.post.mockResolvedValue({ data: {} });
  mocks.put.mockResolvedValue({ data: {} });
  const sesion = useSesionTenantStore();
  sesion.usuario = { rol: "propietario", permisos: ["*"] } as never;
});

describe("cobrar en caja", () => {
  it("pregunta antes de cobrar y no cobra si se cancela", async () => {
    mocks.confirmar.mockResolvedValue(false);
    const w = montar(cita());
    await boton(w, "Cobrar").trigger("click");
    await flushPromises();

    expect(mocks.confirmar).toHaveBeenCalledWith(
      expect.stringContaining("Israel Gutierrez"),
      expect.anything(),
    );
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it("al confirmar, registra el cobro con la forma elegida", async () => {
    mocks.confirmar.mockResolvedValue(true);
    const w = montar(cita());
    await boton(w, "Cobrar").trigger("click");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/ordenes/ord1/liquidar",
      { metodo: "efectivo" },
    );
  });
});

describe("asistencia", () => {
  it("«No asistió» se confirma como acción delicada", async () => {
    mocks.confirmar.mockResolvedValue(true);
    const w = montar(cita({ por_cobrar: false }));
    await boton(w, "No asistió").trigger("click");
    await flushPromises();

    expect(mocks.confirmar).toHaveBeenCalledWith(
      expect.stringContaining("no asistió"),
      expect.objectContaining({ peligro: true }),
    );
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/reservas/r1/asistencia",
      { estado: "ausente" },
    );
  });

  it("ya marcada, se puede corregir (con su confirmación)", async () => {
    mocks.confirmar.mockResolvedValue(true);
    // Pasó la hora y llegó: la cita está completada, sin acciones del día.
    const pasada = cita({ asistencia: "presente", por_cobrar: false });
    (pasada as { inicia_en: string }).inicia_en = "2020-01-01T10:00:00Z";
    (pasada as { termina_en: string }).termina_en = "2020-01-01T10:30:00Z";
    const w = montar(pasada);

    await boton(w, "Corregir: no asistió").trigger("click");
    await flushPromises();
    expect(mocks.confirmar).toHaveBeenCalledWith(
      expect.stringContaining("¿Cambiarlo a «No asistió»?"),
      expect.anything(),
    );
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/reservas/r1/asistencia",
      { estado: "ausente" },
    );
  });
});

describe("forma de pago de un cobro en caja", () => {
  it("si se puede corregir, la cambia sin tocar el monto", async () => {
    mocks.confirmar.mockResolvedValue(true);
    const w = montar(
      cita({
        por_cobrar: false,
        pago: { id: "p1", metodo: "efectivo", en_caja: true, corregible: true },
      }),
    );
    expect(w.text()).toContain("Pagada · Efectivo");

    await w.get('[data-prueba="corregir-pago"]').trigger("click");
    await w.get("select").setValue("transferencia");
    await boton(w, "Guardar forma de pago").trigger("click");
    await flushPromises();

    expect(mocks.put).toHaveBeenCalledWith("/api/v1/app/demo/pagos/p1/metodo", {
      metodo: "transferencia",
    });
  });

  it("si el negocio no lo permite, no se ofrece", () => {
    const w = montar(
      cita({
        por_cobrar: false,
        pago: {
          id: "p1",
          metodo: "efectivo",
          en_caja: true,
          corregible: false,
        },
      }),
    );
    expect(w.find('[data-prueba="corregir-pago"]').exists()).toBe(false);
  });
});
