import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import portal from "@/i18n/locales/portal.es-MX";
import recepcionVisual from "@/i18n/locales/recepcionVisual.es-MX";
import PanelClase from "./PanelClase.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
const permisos = vi.hoisted(() => new Set<string>());
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    terminologia: { sesion: "Clase" },
    puede: (p: string) => permisos.has(p),
  }),
}));
vi.mock("vue-router", () => ({ RouterLink: { template: "<a><slot /></a>" } }));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
vi.mock("@/lib/confirmarAsistencia", () => ({
  confirmarAsistencia: () => Promise.resolve(true),
}));

const reserva = (id: string, estado: string, asistencia: string | null) => ({
  id,
  estado,
  asistencia,
  persona: `Persona ${id}`,
  persona_id: id,
  primera_vez: false,
  adeudo: false,
  documentos_pendientes: 0,
});
function montar() {
  return mount(PanelClase, {
    props: {
      incrustado: true,
      sesion: {
        id: "s1",
        oferta: "Pole dance",
        instructor: "Coach",
        inicia_en: "2030-01-09T16:00:00Z",
        zona_horaria: "America/Mexico_City",
        capacidad: 8,
      },
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, portal, recepcionVisual } },
        }),
      ],
      stubs: { MoverReserva: true, ConfirmarCancelacion: true },
    },
  });
}
describe("pase de lista visual", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    permisos.clear();
  });
  it("cuenta solo las reservas vigentes y separa presentes de pendientes", async () => {
    permisos.add("asistencia.marcar");
    api.get.mockResolvedValue({
      data: {
        data: [
          reserva("a", "confirmada", "presente"),
          reserva("b", "confirmada", null),
          reserva("c", "confirmada", "ausente"),
          reserva("d", "cancelada", "presente"),
          reserva("e", "en_espera", null),
        ],
      },
    });
    const w = montar();
    await flushPromises();
    const resumen = w.findAll(".pc-lista-resumen > div");
    expect(resumen.map((r) => r.get("strong").text())).toEqual(["3", "1", "1"]);
    // Por reserva confirmada: Llegó, Retardo y No vino.
    expect(w.findAll(".pc-lista-accion")).toHaveLength(9);
    expect(w.findAll("a")).toHaveLength(0);
    expect(w.text()).not.toContain("Persona d");
    expect(api.post).not.toHaveBeenCalled();
    w.unmount();
  });
  it("no ofrece acciones ni enlaces de gestión sin permiso", async () => {
    api.get.mockResolvedValue({
      data: { data: [reserva("a", "confirmada", null)] },
    });
    const w = montar();
    await flushPromises();
    expect(w.findAll(".pc-lista-accion")).toHaveLength(0);
    expect(w.findAll("a")).toHaveLength(0);
    expect(w.text()).toContain("Persona a");
    w.unmount();
  });
  it("no muestra disponibilidad o asistencia ficticia al fallar la carga", async () => {
    api.get.mockRejectedValue(new Error("Sin conexión"));
    const w = montar();
    expect(w.find(".pc-lista-resumen").exists()).toBe(false);
    await flushPromises();
    expect(w.find(".pc-lista-resumen").exists()).toBe(false);
    expect(w.find(".md-pastilla").exists()).toBe(false);
    expect(w.text()).toContain("Sin conexión");
    w.unmount();
  });

  it("retardo, ventana del pase de lista y terminar la lista (ADR 0101)", async () => {
    permisos.add("asistencia.marcar");
    // Aún no se puede: la lista abre a las 9:30.
    api.get.mockResolvedValueOnce({
      data: {
        data: [reserva("a", "confirmada", null)],
        meta: { asistencia_desde: "2030-01-09T15:30:00Z", empezo: false },
      },
    });
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2030-01-09T14:00:00Z"));
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="lista-abre"]').text()).toContain("09:30");
    expect(
      w.get('[data-prueba="lista-retardo"]').attributes("disabled"),
    ).toBeDefined();
    expect(w.find('[data-prueba="terminar-lista"]').exists()).toBe(false);
    w.unmount();

    // Ya empezó: se marca un retardo y se termina la lista.
    vi.setSystemTime(new Date("2030-01-09T16:10:00Z"));
    api.get.mockResolvedValue({
      data: {
        data: [
          { ...reserva("a", "confirmada", "presente"), retardo: true },
          reserva("b", "confirmada", null),
        ],
        meta: { asistencia_desde: "2030-01-09T15:30:00Z", empezo: true },
      },
    });
    api.post.mockResolvedValue({ data: { data: { no_se_presentaron: 1 } } });
    const v = montar();
    await flushPromises();
    vi.useRealTimers();
    expect(v.text()).toContain("Llegó tarde");
    (
      v.findAll('[data-prueba="lista-retardo"]')[1].element as HTMLButtonElement
    ).click();
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/reservas/b/asistencia",
      { estado: "presente", retardo: true },
    );
    await v.get('[data-prueba="terminar-lista"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/sesiones/s1/terminar-lista",
      {},
    );
  });
});
