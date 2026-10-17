import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import IntegracionesView from "./IntegracionesView.vue";

/*
| Wellhub y TotalPass dicen su conexión por lo que de verdad pasó, como las pasarelas:
| sin configurar, llaves guardadas, activa sin comprobar y comprobada (con la fecha de
| lo último que llegó de la plataforma). Activarla no la comprueba.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  camposConError: () => [],
  erroresPorCampo: () => ({}),
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    esCitas: false,
    zonaHoraria: "America/Mexico_City",
    estudio: { bienestar_posible: true },
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

function integracion(proveedor: string, cambios: Record<string, unknown>) {
  return {
    proveedor,
    activa: false,
    llaves: ["api_key", "gym_id"],
    llaves_configuradas: [] as string[],
    llaves_obligatorias: ["api_key", "gym_id"],
    automatico: { aviso_url: null, listo: false, registrado_en: null },
    estado_conexion: "sin_configurar",
    comprobada_en: null,
    comprobada_por: null,
    ...cambios,
  };
}

function responder(lista: Record<string, unknown>[]): void {
  api.get.mockImplementation((ruta: string) =>
    Promise.resolve({
      data: { data: ruta.endsWith("/integraciones") ? lista : [] },
    }),
  );
}

async function montar() {
  const w = mount(IntegracionesView, {
    global: {
      plugins: [i18n],
      stubs: {
        Teleport: true,
        AutomaticoBienestar: true,
        ReservasBienestar: true,
        MedicionNegocio: true,
      },
    },
  });
  await flushPromises();
  return w;
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("integraciones: la conexión por lo que de verdad pasó", () => {
  it("muestra los cuatro estados con punto y texto, y la fecha de la última prueba", async () => {
    responder([
      integracion("wellhub", {
        activa: true,
        llaves_configuradas: ["api_key", "gym_id"],
        estado_conexion: "comprobada",
        comprobada_en: "2026-10-14T16:30:00+00:00",
        comprobada_por: "checkin",
      }),
      integracion("totalpass", {
        activa: true,
        llaves_configuradas: ["api_key", "gym_id"],
        estado_conexion: "activa_sin_comprobar",
      }),
    ]);
    const w = await montar();

    const wellhub = w.get('[data-prueba="conexion-wellhub"]');
    expect(wellhub.classes()).toContain("tu-estado");
    expect(wellhub.attributes("style")).toContain("--tono: var(--exito)");
    expect(wellhub.text()).toContain(
      "Conexión comprobada: último check-in validado el",
    );
    expect(wellhub.text()).toContain("14 oct 2026");

    const totalpass = w.get('[data-prueba="conexion-totalpass"]');
    expect(totalpass.attributes("style")).toContain("--tono: var(--aviso)");
    expect(totalpass.text()).toBe(
      "Conexión: activada, aún sin comprobar. Se comprueba con el primer check-in que la plataforma valide o con su primer aviso.",
    );
    w.unmount();
  });

  it("un aviso recibido también la comprueba; sin llaves o apagada no se ve como lista", async () => {
    responder([
      integracion("wellhub", {}),
      integracion("totalpass", {
        llaves_configuradas: ["api_key"],
        estado_conexion: "credenciales",
      }),
    ]);
    const w = await montar();
    expect(w.get('[data-prueba="conexion-wellhub"]').text()).toBe(
      "Conexión: sin configurar. Captura sus llaves.",
    );
    expect(w.get('[data-prueba="conexion-totalpass"]').text()).toBe(
      "Conexión: llaves guardadas, sin activar.",
    );
    for (const p of ["wellhub", "totalpass"]) {
      expect(
        w.get(`[data-prueba="conexion-${p}"]`).attributes("style"),
      ).toContain("--tono: var(--texto-suave)");
    }

    // Al guardar, la respuesta trae el estado nuevo y se ve al momento.
    api.put.mockResolvedValue({
      data: {
        data: integracion("wellhub", {
          activa: true,
          llaves_configuradas: ["api_key", "gym_id"],
          estado_conexion: "comprobada",
          comprobada_en: "2026-10-15T18:00:00+00:00",
          comprobada_por: "aviso",
        }),
      },
    });
    await w.get("#wellhub-api_key").setValue("wh_token");
    await w.get("#wellhub-gym_id").setValue("445566");
    await w
      .get('[data-prueba="integracion-wellhub"]')
      .findAll("button")
      .find((b) => b.text() === "Guardar")!
      .trigger("click");
    await flushPromises();
    const wellhub = w.get('[data-prueba="conexion-wellhub"]');
    expect(wellhub.text()).toContain(
      "Conexión comprobada: último aviso de la plataforma recibido el",
    );
    expect(wellhub.text()).toContain("15 oct 2026");
    expect(wellhub.attributes("data-estado")).toBe("comprobada");
    w.unmount();
  });
});
