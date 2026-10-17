import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CortePlanes from "./CortePlanes.vue";

/*
| Corte de planes: de cada plan llegan las clases más recientes y su total; las demás
| se piden por páginas con «Ver más» (una membresía de años junta cientos).
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    zonaHoraria: "America/Mexico_City",
    terminologia: { sesion: "Clase", sesiones: "Clases" },
    puede: () => true,
  }),
}));

const uso = (dia: number) => ({
  clase: "Pole Nivel 1",
  inicia_en: `2026-09-${String(dia).padStart(2, "0")}T01:00:00Z`,
  zona_horaria: "America/Mexico_City",
  estado: "asistio",
  unidades: 1000,
  extra: false,
});
const plan = {
  id: "acuerdo1",
  derecho_id: "derecho1",
  producto: "Ilimitada",
  tipo: "membresia",
  comprado: "2026-01-01",
  desde: "2026-01-01",
  hasta: null,
  estado: "vigente",
  ilimitado: true,
  aplica_a: [],
  unidades: {
    incluidas: 0,
    extras: 0,
    usadas: 14000,
    devueltas: 0,
    vencidas: 0,
    ajustes: 0,
    apartadas: 0,
    disponibles: 0,
  },
  extras: [],
  usos: Array.from({ length: 10 }, (_, i) => uso(20 - i)),
  usos_total: 14,
};

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/usos")
        ? { data: [uso(10), uso(9), uso(8), uso(7)] }
        : { data: [plan] },
    }),
  );
});

describe("corte de planes", () => {
  it("cuenta todos los usos y pide los demás por páginas", async () => {
    const w = mount(CortePlanes, {
      props: { url: "/api/v1/app/demo/mi/planes" },
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const abrir = w.findAll("button").find((b) => b.text().includes("(14)"));
    expect(abrir).toBeDefined();
    await abrir!.trigger("click");
    expect(w.text()).toContain("Ver 4 más");

    await w.get('[data-prueba="ver-mas-usos"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/mi/planes/derecho1/usos",
      { params: { page: 2, per_page: 10 } },
    );
    // Ya están los 14: no hay más que pedir.
    expect(w.find('[data-prueba="ver-mas-usos"]').exists()).toBe(false);
  });
});
