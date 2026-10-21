import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import IntegracionesView from "./IntegracionesView.vue";

/*
| Con varias sedes, cada una es otro gimnasio en Wellhub o TotalPass (ADR 0202): se elige
| de cuál es la conexión que se ve, se guarda con sus llaves y se puede quitar.
*/

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  camposConError: () => [],
  erroresPorCampo: () => ({}),
  mensajeDeError: () => "Error",
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    esCitas: false,
    puede: () => true,
    zonaHoraria: "America/Mexico_City",
    estudio: { bienestar_posible: true },
    usuario: {
      sucursales: [
        { id: "S1", nombre: "Roma Norte", zona_horaria: null },
        { id: "S2", nombre: "Del Valle", zona_horaria: null },
      ],
    },
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const SEDES = [{ sucursal: { id: "S1", nombre: "Roma Norte" }, activa: true }];

function wellhub(cambios: Record<string, unknown>) {
  return {
    proveedor: "wellhub",
    sede: { id: "S1", nombre: "Roma Norte" },
    sedes: SEDES,
    activa: true,
    llaves: ["api_key", "gym_id"],
    llaves_configuradas: ["api_key", "gym_id"],
    llaves_obligatorias: ["api_key", "gym_id"],
    automatico: { aviso_url: null, listo: false, registrado_en: null },
    estado_conexion: "activa_sin_comprobar",
    ...cambios,
  };
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
  api.get.mockImplementation(
    (ruta: string, opciones?: { params?: { sucursal?: string } }) => {
      if (ruta.endsWith("/integraciones")) {
        return Promise.resolve({ data: { data: [wellhub({})] } });
      }
      if (ruta.endsWith("/integraciones/wellhub")) {
        const sede = opciones?.params?.sucursal;
        return Promise.resolve({
          data: {
            data:
              sede === "S2"
                ? wellhub({
                    sede: { id: "S2", nombre: "Del Valle" },
                    activa: false,
                    llaves_configuradas: [],
                    estado_conexion: "sin_configurar",
                  })
                : wellhub({}),
          },
        });
      }
      return Promise.resolve({ data: { data: [] } });
    },
  );
});

describe("integraciones por sede", () => {
  it("elige la sede, guarda con sus llaves y quita la conexión de una sede", async () => {
    const w = await montar();
    const tarjeta = w.find('[data-prueba="integracion-wellhub"]');
    expect(tarjeta.find('[data-prueba="sedes-wellhub"]').text()).toContain(
      "Conectadas: Roma Norte.",
    );
    const selector = tarjeta.find("select#wellhub-sede");
    expect((selector.element as HTMLSelectElement).value).toBe("S1");

    // Del Valle aún no tiene su conexión: sin llaves y sin «quitar».
    await selector.setValue("S2");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/integraciones/wellhub",
      { params: { sucursal: "S2" } },
    );
    expect(w.find('[data-prueba="quitar-sede-wellhub"]').exists()).toBe(false);

    api.put.mockResolvedValue({
      data: { data: wellhub({ sede: { id: "S2", nombre: "Del Valle" } }) },
    });
    await w.find("input#wellhub-api_key").setValue("wh_valle");
    await w.find("input#wellhub-gym_id").setValue("222");
    const guardar = w
      .findAll('[data-prueba="integracion-wellhub"] button')
      .find((b) => b.text() === "Guardar");
    await guardar?.trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/integraciones/wellhub",
      { activa: false, credenciales: { api_key: "wh_valle", gym_id: "222" } },
      { params: { sucursal: "S2" } },
    );

    api.delete.mockResolvedValue({});
    await w.find('[data-prueba="quitar-sede-wellhub"]').trigger("click");
    await flushPromises();
    expect(api.delete).toHaveBeenCalledWith(
      "/api/v1/app/demo/integraciones/wellhub",
      { params: { sucursal: "S2" } },
    );
    // Vuelve a la del negocio entero.
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/integraciones/wellhub",
      { params: {} },
    );
  });
});
