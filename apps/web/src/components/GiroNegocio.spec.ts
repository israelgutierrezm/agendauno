import { flushPromises, mount } from "@vue/test-utils";
import { AxiosError, type InternalAxiosRequestConfig } from "axios";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import GiroNegocio from "./GiroNegocio.vue";

/*
| Tipo de negocio en Configuración (ADR 0104): solo se ofrecen los giros de su
| modalidad. Pasar de citas a clases (o al revés) lo hace AgendaUno: si el servidor
| lo niega (MODALITY_LOCKED), su mensaje se muestra tal cual y se vuelve al de antes.
*/

const mocks = vi.hoisted(() => ({ put: vi.fn() }));
vi.mock("@/lib/api", async (importOriginal) => {
  const real = await importOriginal<typeof import("@/lib/api")>();
  return { ...real, api: { put: mocks.put } };
});
vi.mock("@/stores/apariencia", () => ({
  useAparienciaStore: () => ({ activar: vi.fn(), desactivar: vi.fn() }),
}));

const TERMINOS_BARBERIA = {
  sesion: "Cita",
  miembro: "Cliente",
  instructor: "Barbero",
};

function montar(modalidad: "clases" | "citas", perfil: string) {
  const sesion = useSesionTenantStore();
  sesion.slug = "demo";
  sesion.estudio = {
    slug: "demo",
    nombre: "Demo",
    estado: "active",
    perfil,
    modalidad,
    capacidades: {
      clases: modalidad === "clases",
      citas: modalidad === "citas",
    },
    perfil_config: {
      terminologia: TERMINOS_BARBERIA,
      flags: { grupos: false, niveles: false, acceso_abierto: false },
      modalidad,
    },
  };
  return mount(GiroNegocio, { global: { plugins: [i18n] } });
}

function opciones(w: ReturnType<typeof montar>): string[] {
  return w
    .findAll('[data-prueba="giro"] option')
    .map((o) => o.attributes("value") ?? "");
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe("tipo de negocio", () => {
  it("un negocio de citas solo elige entre giros de citas", () => {
    const w = montar("citas", "barberia");
    expect(opciones(w)).toEqual([
      "barberia",
      "estetica",
      "salon",
      "spa",
      "salud",
    ]);
    expect(w.text()).toContain("Tu negocio trabaja con citas.");
  });

  it("un negocio de clases solo elige entre giros de clases", () => {
    const w = montar("clases", "pole");
    expect(opciones(w)).toContain("pilates");
    expect(opciones(w)).toContain("general");
    expect(opciones(w)).not.toContain("barberia");
    expect(w.text()).toContain("Tu negocio trabaja con clases.");
  });

  it("otro giro de su modalidad se guarda y cambia la terminología", async () => {
    mocks.put.mockResolvedValue({
      data: {
        data: {
          perfil: "spa",
          perfil_config: {
            terminologia: { ...TERMINOS_BARBERIA, instructor: "Terapeuta" },
            flags: { grupos: false, niveles: false, acceso_abierto: false },
            modalidad: "citas",
          },
        },
      },
    });
    const w = montar("citas", "barberia");
    await w.get('[data-prueba="giro"]').setValue("spa");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(mocks.put).toHaveBeenCalledWith("/api/v1/app/demo/perfil", {
      perfil_negocio: "spa",
    });
    expect(useSesionTenantStore().terminologia.instructor).toBe("Terapeuta");
    expect(w.text()).toContain("Guardado");
  });

  it("si el servidor lo niega (MODALITY_LOCKED), dice por qué y vuelve al de antes", async () => {
    const config = { headers: {} } as InternalAxiosRequestConfig;
    mocks.put.mockRejectedValue(
      new AxiosError("Falla", "ERR_BAD_REQUEST", config, null, {
        status: 422,
        statusText: "",
        headers: {},
        config,
        data: {
          code: "MODALITY_LOCKED",
          message:
            "Este negocio trabaja con citas; cambiar a clases lo hace AgendaUno.",
        },
      }),
    );
    const w = montar("citas", "barberia");
    await w.get('[data-prueba="giro"]').setValue("salon");
    await w.get("form").trigger("submit");
    await flushPromises();

    // Sin adaptar a la terminología: «clases» no se vuelve «citas».
    expect(w.get('[data-prueba="giro-error"]').text()).toBe(
      "Este negocio trabaja con citas; cambiar a clases lo hace AgendaUno.",
    );
    expect(
      (w.get('[data-prueba="giro"]').element as HTMLSelectElement).value,
    ).toBe("barberia");
    expect(useSesionTenantStore().estudio?.perfil).toBe("barberia");
  });
});
