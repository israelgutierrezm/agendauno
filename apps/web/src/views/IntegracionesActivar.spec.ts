import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import IntegracionesView from "./IntegracionesView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
const campos = vi.hoisted(() => ({ lista: [] as string[] }));
vi.mock("@/lib/api", () => ({
  api,
  camposConError: () => campos.lista,
  erroresPorCampo: () => ({}),
  mensajeDeError: () => "Para activar Wellhub captura sus llaves.",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    esCitas: false,
    puede: () => true,
    estudio: { bienestar_posible: true },
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const WELLHUB = {
  proveedor: "wellhub",
  activa: false,
  llaves: ["api_key", "gym_id", "webhook_secret"],
  llaves_configuradas: [] as string[],
  llaves_obligatorias: ["api_key", "gym_id"],
  automatico: { aviso_url: null, listo: false, registrado_en: null },
};

function responder(wellhub = WELLHUB): void {
  api.get.mockImplementation((ruta: string) =>
    Promise.resolve({
      data: { data: ruta.endsWith("/integraciones") ? [wellhub] : [] },
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
  campos.lista = [];
  responder();
});

describe("integraciones: activar solo con sus llaves", () => {
  it("sin llaves el interruptor no se enciende y dice cuáles faltan", async () => {
    const w = await montar();
    const interruptor = w.get('[data-prueba="activa-wellhub"]');

    expect(interruptor.attributes("disabled")).toBeDefined();
    expect(w.get('[data-prueba="faltan-llaves-wellhub"]').text()).toBe(
      "Para activarla captura: Token de API de Wellhub, Gym ID.",
    );
    // Las obligatorias llevan su asterisco.
    expect(w.findAll(".tu-obligatorio")).toHaveLength(2);

    // Con las dos escritas ya se puede encender.
    await w.get("#wellhub-api_key").setValue("wh_token");
    expect(w.get('[data-prueba="faltan-llaves-wellhub"]').text()).toBe(
      "Para activarla captura: Gym ID.",
    );
    await w.get("#wellhub-gym_id").setValue("445566");
    expect(w.find('[data-prueba="faltan-llaves-wellhub"]').exists()).toBe(
      false,
    );
    expect(interruptor.attributes("disabled")).toBeUndefined();
    w.unmount();
  });

  it("las llaves ya guardadas cuentan, y una activa siempre se puede apagar", async () => {
    responder({
      ...WELLHUB,
      activa: true,
      llaves_configuradas: ["api_key", "gym_id"],
    });
    const w = await montar();
    const interruptor = w.get('[data-prueba="activa-wellhub"]');

    expect(interruptor.attributes("disabled")).toBeUndefined();
    await interruptor.trigger("click");
    expect(interruptor.attributes("aria-checked")).toBe("false");
    // Apagada y con sus llaves guardadas, se puede volver a encender.
    expect(interruptor.attributes("disabled")).toBeUndefined();
    w.unmount();
  });

  it("con un grupo de llaves que también basta (TotalPass: la de su sede) se puede encender", async () => {
    responder({
      ...WELLHUB,
      proveedor: "totalpass",
      llaves: ["api_key", "codigo_gimnasio", "codigo_plan", "place_api_key"],
      llaves_obligatorias: ["api_key", "codigo_gimnasio"],
      llaves_alternativas: [["place_api_key"]],
    } as typeof WELLHUB);
    const w = await montar();
    const interruptor = w.get('[data-prueba="activa-totalpass"]');
    expect(interruptor.attributes("disabled")).toBeDefined();

    await w.get("#totalpass-place_api_key").setValue("llave-de-la-sede");
    expect(interruptor.attributes("disabled")).toBeUndefined();
    w.unmount();
  });

  it("marca las llaves que la API rechazó", async () => {
    api.put.mockRejectedValue(new Error("422"));
    campos.lista = ["activa", "credenciales.gym_id"];
    const w = await montar();

    await w
      .findAll("button")
      .find((b) => b.text() === "Guardar")!
      .trigger("click");
    await flushPromises();

    expect(w.get("#wellhub-gym_id").attributes("aria-invalid")).toBe("true");
    expect(
      w.get("#wellhub-api_key").attributes("aria-invalid"),
    ).toBeUndefined();
    w.unmount();
  });
});
