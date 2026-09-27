import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import HorariosView from "./HorariosView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));

// Respuestas del horario que el test resuelve cuando quiere (para desordenarlas).
const pendientes = new Map<string, (dato: unknown) => void>();
function horario(inicio: string) {
  return {
    data: {
      data: [
        { id: "h", dia_semana: 1, hora_inicio: inicio, hora_fin: "18:00" },
      ],
    },
  };
}

function montar() {
  return mount(HorariosView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, operacion } },
        }),
      ],
      stubs: { EncabezadoSeccion: true, BloqueosAgenda: true },
    },
  });
}

describe("horarios de atención", () => {
  beforeEach(() => {
    pendientes.clear();
    api.get.mockImplementation(
      (url: string, config?: { params?: { instructor_id?: string } }) => {
        if (url.endsWith("/sucursales")) {
          return Promise.resolve({
            data: {
              data: [
                {
                  id: "s1",
                  nombre: "Roma",
                  zona_horaria: "America/Mexico_City",
                },
              ],
            },
          });
        }
        if (url.endsWith("/instructores")) {
          return Promise.resolve({
            data: {
              data: [
                { id: "ana", nombre: "Ana" },
                { id: "beto", nombre: "Beto" },
              ],
            },
          });
        }
        return new Promise((r) =>
          pendientes.set(config?.params?.instructor_id ?? "", r),
        );
      },
    );
  });
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("una respuesta vieja no pisa el horario de la persona elegida ahora", async () => {
    const w = montar();
    await flushPromises();

    await w.find("#h-prov").setValue("ana"); // lenta
    await flushPromises();
    // Mientras carga, no hay guardar disponible.
    const guardar = () =>
      w.findAll("button").find((b) => b.text() === esMX.horarios.guardar);
    expect(
      guardar() === undefined ||
        guardar()!.attributes("disabled") !== undefined,
    ).toBe(true);

    await w.find("#h-prov").setValue("beto"); // rápida
    await flushPromises();
    pendientes.get("beto")!(horario("10:00:00"));
    await flushPromises();
    pendientes.get("ana")!(horario("07:00:00")); // llega tarde
    await flushPromises();

    expect((w.find("#d1-i0-ini").element as HTMLInputElement).value).toBe(
      "10:00",
    );
    expect(guardar()!.attributes("disabled")).toBeUndefined();
  });

  it("con cambios sin guardar, pregunta antes de cambiar de persona", async () => {
    const pregunta = vi.spyOn(window, "confirm").mockReturnValue(false);
    const w = montar();
    await flushPromises();
    await w.find("#h-prov").setValue("ana");
    await flushPromises();
    pendientes.get("ana")!(horario("09:00:00"));
    await flushPromises();

    await w.find("#d1-i0-ini").setValue("08:00");
    await w.find("#h-prov").setValue("beto");
    await flushPromises();

    expect(pregunta).toHaveBeenCalledOnce();
    // No los descartó: se queda con Ana y con su cambio.
    expect((w.find("#h-prov").element as HTMLSelectElement).value).toBe("ana");
    expect((w.find("#d1-i0-ini").element as HTMLInputElement).value).toBe(
      "08:00",
    );
  });
});
