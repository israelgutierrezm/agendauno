import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ResumenDelDia from "./ResumenDelDia.vue";

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "No se pudo cargar.",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    estudio: { nombre: "Estudio Demo", perfil: "pole" },
    puede: () => true,
  }),
}));

const sesion = (extra: Record<string, unknown>) => ({
  id: "s1",
  tipo: "clase",
  oferta: "Pole Nivel 1",
  instructor: "Caro",
  sucursal: "Roma Norte",
  cliente: null,
  inicia_en: "2026-10-01T14:00:00Z",
  zona_horaria: "America/Mexico_City",
  capacidad: 10,
  esperados: 3,
  llegaron: 1,
  sin_marcar: 0,
  cancelada: false,
  momento: "proxima",
  ...extra,
});

function montar() {
  return mount(ResumenDelDia, {
    global: {
      plugins: [i18n],
      stubs: { RouterLink: { props: ["to"], template: "<a><slot /></a>" } },
    },
  });
}

describe("el día de hoy en el Inicio", () => {
  beforeEach(() => vi.clearAllMocks());

  it("indicadores, agenda con lo que falta marcar y pendientes", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: {
            totales: { sesiones: 2, esperados: 5, llegaron: 1, sin_marcar: 2 },
            sesiones: [
              sesion({ id: "s1", momento: "termino", sin_marcar: 2 }),
              sesion({
                id: "s2",
                tipo: "cita",
                oferta: "Corte",
                cliente: "Dana",
                capacidad: 1,
                esperados: 1,
                inicia_en: "2026-10-01T18:00:00Z",
                momento: "en_curso",
              }),
            ],
          },
          cobros: {
            ordenes_pendientes: 2,
            por_cobrar: [{ moneda: "MXN", total_minor: 50000 }],
            en_mora: 0,
          },
          renovaciones: { por_vencer: 3, vencidas: 0, dias: 7 },
        },
      },
    });
    const w = montar();
    await flushPromises();
    const texto = w.text();

    // La fecha local del día va a la API.
    expect(api.get.mock.calls[0][1].params.fecha).toMatch(
      /^\d{4}-\d{2}-\d{2}$/,
    );
    // La tarjeta principal: lo que está en curso y los indicadores del día.
    expect(texto).toContain("En curso");
    expect(texto).toContain("Corte · Dana");
    expect(texto).toContain("Abrir agenda");
    expect(texto).toContain("Por pasar lista");
    expect(texto).toContain("08:00");
    expect(texto).toContain("Falta pasar lista a 2 personas");
    expect(texto).toContain("3 de 10");
    expect(texto).toContain("Corte · Dana");
    expect(texto).toContain("En curso");
    expect(texto).toContain("2 órdenes por cobrar");
    expect(texto).toContain("$500.00");
    expect(texto).toContain("3 membresías vencen en 7 días o menos");
  });

  it("sin permisos de cobro ni agenda no muestra esos bloques; el error no es un día vacío", async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          fecha: "2026-10-01",
          agenda: null,
          cobros: null,
          renovaciones: null,
        },
      },
    });
    const w = montar();
    await flushPromises();
    expect(w.text()).not.toContain("Agenda de hoy");
    expect(w.text()).not.toContain("Pendientes");

    api.get.mockRejectedValueOnce(new Error("red"));
    const conError = montar();
    await flushPromises();
    expect(conError.text()).toContain("No se pudo cargar.");
    expect(conError.text()).not.toContain("No hay clases hoy.");
  });
});
