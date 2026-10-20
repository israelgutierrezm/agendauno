import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n as i18nApp } from "@/i18n";
import type { SesionAgenda } from "@/lib/agenda";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import PanelCita from "./PanelCita.vue";

/*
| Lo delicado de una cita se confirma y se puede corregir: cobrar en caja, marcar
| asistencia (y corregirla) y la forma de pago de un cobro en caja (ADR 0086). El
| detalle muestra el contacto del cliente y el historial de la cita.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  confirmar: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post, put: mocks.put },
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
        ModalDialogo: {
          props: ["abierto", "titulo"],
          template: '<div v-if="abierto"><slot /><slot name="pie" /></div>',
        },
        RouterLink: {
          props: ["to"],
          template: '<a data-prueba="ver-perfil"><slot /></a>',
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
  mocks.get.mockResolvedValue({ data: { data: [] } });
  mocks.put.mockResolvedValue({ data: {} });
  const sesion = useSesionTenantStore();
  sesion.usuario = { rol: "propietario", permisos: ["*"] } as never;
});

describe("cobrar en caja", () => {
  it("«Registrar pago» abre el cobro completo y cobra con la forma elegida", async () => {
    mocks.post.mockResolvedValue({
      data: {
        data: {
          servicio_minor: 15000,
          productos_minor: 0,
          propina_minor: 0,
          total_minor: 15000,
          recibido_minor: null,
          cambio_minor: null,
          ticket: null,
        },
      },
    });
    const w = montar(cita({ total_minor: 15000 }));
    await boton(w, "Registrar pago").trigger("click");
    await flushPromises();

    await boton(w, "Transferencia").trigger("click");
    await w.get("#form-cobro-cita").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/reservas/r1/cobro",
      expect.objectContaining({ metodo: "transferencia", items: [] }),
    );
    expect(w.find('[data-prueba="comprobante-cita"]').exists()).toBe(true);
    expect(w.emitted("cambiada")).toBeTruthy();
  });
});

describe("asistencia", () => {
  it("ya atendida y terminada, se sigue cobrando; sin asistencia, no", () => {
    const atendida = cita({ asistencia: "presente", por_cobrar: true });
    (atendida as { inicia_en: string }).inicia_en = "2020-01-01T10:00:00Z";
    (atendida as { termina_en: string }).termina_en = "2020-01-01T10:30:00Z";
    expect(
      montar(atendida)
        .findAll("button")
        .some((b) => b.text() === "Registrar pago"),
    ).toBe(true);

    const noVino = cita({ asistencia: "ausente", por_cobrar: true });
    expect(
      montar(noVino)
        .findAll("button")
        .some((b) => b.text() === "Registrar pago"),
    ).toBe(false);
  });

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
      { estado: "ausente", retardo: false },
    );
  });

  it("ya marcada, se puede corregir (con su confirmación)", async () => {
    mocks.confirmar.mockResolvedValue(true);
    // Pasó la hora y llegó: la cita está completada, sin acciones del día.
    const pasada = cita({ asistencia: "presente", por_cobrar: false });
    (pasada as { inicia_en: string }).inicia_en = "2020-01-01T10:00:00Z";
    (pasada as { termina_en: string }).termina_en = "2020-01-01T10:30:00Z";
    const w = montar(pasada);

    // La opción marcada no se vuelve a marcar; la otra corrige.
    expect(boton(w, "Llegó").attributes("disabled")).toBeDefined();
    await boton(w, "No asistió").trigger("click");
    await flushPromises();
    expect(mocks.confirmar).toHaveBeenCalledWith(
      expect.stringContaining("¿Cambiarlo a «No asistió»?"),
      expect.anything(),
    );
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/reservas/r1/asistencia",
      { estado: "ausente", retardo: false },
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
    expect(w.get('[data-prueba="estado-cita"]').text()).toBe("Agendada");
    expect(w.text()).toContain("Pagada");
    expect(w.text()).toContain("Efectivo");
    expect(w.findAll("button").some((b) => b.text() === "Registrar pago")).toBe(
      false,
    );

    await w.get('[data-prueba="corregir-pago"]').trigger("click");
    await w.get("select").setValue("transferencia");
    // Cambia cómo cuadra el corte: sin motivo no se guarda.
    expect(
      boton(w, "Guardar forma de pago").attributes("disabled"),
    ).toBeDefined();
    await w.get('[data-prueba="motivo-metodo"]').setValue("Pagó por SPEI");
    await boton(w, "Guardar forma de pago").trigger("click");
    await flushPromises();

    expect(mocks.put).toHaveBeenCalledWith("/api/v1/app/demo/pagos/p1/metodo", {
      metodo: "transferencia",
      motivo: "Pagó por SPEI",
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

describe("quién y qué pasó", () => {
  it("muestra el contacto y la ficha del cliente", () => {
    const w = montar(
      cita({
        cliente_id: "per1",
        telefono: "55 1234 5678",
        email: "diego@correo.mx",
      }),
    );
    expect(w.text()).toContain("55 1234 5678");
    expect(w.text()).toContain("diego@correo.mx");
    expect(w.get('[data-prueba="ver-perfil"]').text()).toContain("Ver perfil");
  });

  it("el historial cuenta lo que pasó y quién lo hizo", async () => {
    mocks.get.mockResolvedValue({
      data: {
        data: [
          {
            tipo: "agendada",
            fecha: "2026-10-01T15:00:00Z",
            actor: null,
            detalle: {},
          },
          {
            tipo: "cobrada",
            fecha: "2026-10-01T16:00:00Z",
            actor: "Rosa",
            detalle: { monto_minor: 25000, metodo: "efectivo", en_caja: true },
          },
        ],
      },
    });
    const w = montar(cita({ por_cobrar: false }));
    await boton(w, "Historial").trigger("click");
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/reservas/r1/historial",
    );
    const historial = w.get('[data-prueba="historial"]').text();
    expect(historial).toContain("Cita agendada");
    expect(historial).toContain("Cobrada en caja · Efectivo");
    expect(historial).toContain("por Rosa");
  });

  it("con la asistencia marcada ya no se cancela ni se reprograma", () => {
    const w = montar(cita({ asistencia: "presente", por_cobrar: false }));
    expect(boton(w, "Cancelada").attributes("disabled")).toBeDefined();
    expect(
      w.findAll("button").some((b) => b.text() === "Reprogramar cita"),
    ).toBe(false);
  });
});
