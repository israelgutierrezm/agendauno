import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import SucursalesEstudioView from "./SucursalesEstudioView.vue";

/*
| Entrada pública del negocio (la raíz de su subdominio, ADR 0104): va al flujo de
| citas solo si el escaparate dice que el negocio es de citas. Uno de clases va a su
| página aunque tenga clases de pago.
*/

const mocks = vi.hoisted(() => ({ get: vi.fn(), replace: vi.fn() }));
vi.mock("@/lib/api", () => ({ api: { get: mocks.get } }));
vi.mock("@/lib/negociosRecientes", () => ({ recordarNegocio: vi.fn() }));
vi.mock("vue-router", () => ({
  useRoute: () => ({ params: { slug: "casa-navaja" }, query: {} }),
  useRouter: () => ({ replace: mocks.replace }),
  RouterLink: {
    props: ["to"],
    template: "<a :data-ruta='JSON.stringify(to)'><slot /></a>",
  },
}));

function escaparate(
  modalidad: "clases" | "citas",
  sucursales: { id: string; nombre: string }[],
) {
  return {
    data: {
      data: {
        estudio: {
          slug: "casa-navaja",
          nombre: "Casa Navaja",
          logo_url: null,
          modalidad,
          capacidades: {
            clases: modalidad === "clases",
            citas: modalidad === "citas",
          },
          // Lo que antes se deducía de las ofertas ya no decide nada.
          tiene_citas: modalidad === "clases",
        },
        sucursales: sucursales.map((s) => ({ ...s, zona_horaria: null })),
      },
    },
  };
}

function montar() {
  return mount(SucursalesEstudioView, { global: { plugins: [i18n] } });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("entrada pública del negocio", () => {
  it("un negocio de clases va a su página, aunque tenga varias sedes", async () => {
    mocks.get.mockResolvedValue(
      escaparate("clases", [
        { id: "s1", nombre: "Roma" },
        { id: "s2", nombre: "Condesa" },
      ]),
    );
    const w = montar();
    await flushPromises();

    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/casa-navaja/escaparate",
    );
    expect(mocks.replace).toHaveBeenCalledWith({
      name: "estudio-publico",
      params: { slug: "casa-navaja" },
    });
    expect(w.text()).not.toContain("Elige tu sucursal");
  });

  it("uno de citas con una sola sede va directo a agendar en ella", async () => {
    mocks.get.mockResolvedValue(
      escaparate("citas", [{ id: "s1", nombre: "Cantera" }]),
    );
    montar();
    await flushPromises();

    expect(mocks.replace).toHaveBeenCalledWith({
      name: "agendar-cita",
      params: { slug: "casa-navaja" },
      query: { sucursal: "s1" },
    });
  });

  it("uno de citas con varias sedes pide elegir dónde agendar", async () => {
    mocks.get.mockResolvedValue(
      escaparate("citas", [
        { id: "s1", nombre: "Cantera" },
        { id: "s2", nombre: "Centro" },
      ]),
    );
    const w = montar();
    await flushPromises();

    expect(mocks.replace).not.toHaveBeenCalled();
    expect(w.text()).toContain("Elige tu sucursal");
    const destinos = w
      .findAll("a[data-ruta]")
      .map((a) => JSON.parse(a.attributes("data-ruta") ?? "{}"));
    expect(destinos).toContainEqual({
      name: "agendar-cita",
      params: { slug: "casa-navaja" },
      query: { sucursal: "s2" },
    });
  });

  it("si la página no está abierta, lo dice", async () => {
    mocks.get.mockRejectedValue(new Error("404"));
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Este negocio no está disponible por ahora.");
    expect(mocks.replace).not.toHaveBeenCalled();
  });
});
