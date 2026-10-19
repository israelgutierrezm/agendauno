import { flushPromises, mount } from "@vue/test-utils";
import { expect, it, vi } from "vitest";
import { i18n } from "@/i18n";
import OportunidadesView from "./OportunidadesView.vue";

/*
| Desde el Inicio, «mañana, a menos de la mitad» abre Lugares libres de ese día
| (`?fecha=`): el filtro se ve y se puede quitar.
*/

const get = vi.hoisted(() => vi.fn());
vi.mock("@/lib/api", () => ({ api: { get }, mensajeDeError: String }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => false,
    zonaHoraria: "America/Mexico_City",
  }),
}));
const consulta = vi.hoisted(() => ({
  valor: { fecha: "2026-10-02" } as Record<string, string>,
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({ query: consulta.valor }),
}));

const clase = (id: string, inicia_en: string) => ({
  id,
  oferta: `Clase ${id}`,
  actividad: "Pole dance",
  sucursal: "Roma",
  instructor: "Ana",
  inicia_en,
  zona_horaria: "America/Mexico_City",
  capacidad: 10,
  ocupados: 2,
  libres: 8,
  en_espera: 0,
  ocupacion_pct: 20,
});

it("abre solo las clases de ese día y deja quitar el filtro", async () => {
  get.mockResolvedValue({
    data: {
      data: [
        clase("a", "2026-10-02T14:00:00Z"),
        // 20:00 del 2 en la Ciudad de México ya es el 3 en UTC: sigue siendo del 2.
        clase("b", "2026-10-03T02:00:00Z"),
        clase("c", "2026-10-03T14:00:00Z"),
      ],
    },
  });
  const w = mount(OportunidadesView, { global: { plugins: [i18n] } });
  await flushPromises();

  expect(w.findAll('[data-prueba="oportunidad"]')).toHaveLength(2);
  await w.get('[data-prueba="filtro-fecha"]').trigger("click");
  expect(w.findAll('[data-prueba="oportunidad"]')).toHaveLength(3);
});

it("con «a menos de la mitad» (`?poca=1`) abre solo las que contó el Inicio", async () => {
  consulta.valor = { fecha: "2026-10-02", poca: "1" };
  get.mockResolvedValue({
    data: {
      data: [
        clase("a", "2026-10-02T14:00:00Z"),
        { ...clase("b", "2026-10-02T16:00:00Z"), ocupados: 6, libres: 4 },
      ],
    },
  });
  const w = mount(OportunidadesView, { global: { plugins: [i18n] } });
  await flushPromises();

  expect(w.findAll('[data-prueba="oportunidad"]')).toHaveLength(1);
  await w.get('[data-prueba="solo-poca"]').setValue(false);
  expect(w.findAll('[data-prueba="oportunidad"]')).toHaveLength(2);
  consulta.valor = { fecha: "2026-10-02" };
});
