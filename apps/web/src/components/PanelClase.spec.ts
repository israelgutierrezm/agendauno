import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import paseLista from "@/i18n/locales/paseLista.es-MX";
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
          messages: { es: { ...esMX, paseLista, portal, recepcionVisual } },
        }),
      ],
      stubs: {
        MoverReserva: true,
        ConfirmarCancelacion: true,
        AgregarAClase: true,
      },
    },
  });
}
describe("detalle de la clase", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    permisos.clear();
  });
  it("cuenta solo las reservas vigentes y dice cómo va cada quien, sin marcar aquí", async () => {
    permisos.add("asistencia.marcar");
    permisos.add("reservas.ver");
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
    // El estado con su nombre (punto + texto); la asistencia se pasa en su pantalla.
    expect(
      w.findAll('[data-prueba="estado-asistencia"]').map((e) => e.text()),
    ).toEqual(["Llegó", "No vino"]);
    expect(w.find('[data-prueba="pasar-lista"]').exists()).toBe(true);
    expect(w.findAll("button").map((b) => b.text())).not.toContain("Llegó");
    expect(w.text()).not.toContain("Persona d");
    expect(api.post).not.toHaveBeenCalled();
    w.unmount();
  });
  it("sin permiso no ofrece pasar lista ni acciones de gestión", async () => {
    api.get.mockResolvedValue({
      data: { data: [reserva("a", "confirmada", null)] },
    });
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="pasar-lista"]').exists()).toBe(false);
    expect(w.findAll("a")).toHaveLength(0);
    expect(w.text()).not.toContain("Agregar alumno");
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
    expect(w.find('[data-prueba="pasar-lista"]').exists()).toBe(false);
    expect(w.text()).toContain("Sin conexión");
    w.unmount();
  });
});
