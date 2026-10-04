import { flushPromises, mount } from "@vue/test-utils";
import { expect, it, vi } from "vitest";
import { i18n } from "@/i18n";
import OportunidadesView from "./OportunidadesView.vue";

const get = vi.hoisted(() => vi.fn());
vi.mock("@/lib/api", () => ({ api: { get }, mensajeDeError: String }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => false }),
}));

it("pagina los lugares y vuelve a la primera página al buscar o filtrar", async () => {
  get.mockResolvedValue({
    data: {
      data: Array.from({ length: 25 }, (_, i) => ({
        id: String(i),
        oferta: `Clase ${i}`,
        actividad: "Pole dance",
        sucursal: i === 24 ? "Polanco" : "Roma",
        instructor: "Ana",
        inicia_en: "2026-10-05T12:00:00Z",
        zona_horaria: "America/Mexico_City",
        capacidad: 10,
        ocupados: 5,
        libres: 5,
        en_espera: 0,
        ocupacion_pct: 50,
      })),
    },
  });
  const w = mount(OportunidadesView, { global: { plugins: [i18n] } });
  await flushPromises();
  expect(w.findAll('[data-prueba="oportunidad"]')).toHaveLength(20);
  await w.get('button[aria-label="Página 2"]').trigger("click");
  expect(w.findAll('[data-prueba="oportunidad"]')).toHaveLength(5);
  await w.get('input[type="search"]').setValue("Polanco");
  expect(w.findAll('[data-prueba="oportunidad"]')).toHaveLength(1);
  expect(w.find('[data-prueba="oportunidad"]').text()).toContain("Clase 24");
  await w.get('input[type="search"]').setValue("");
  await w.get('select[aria-label="Filtrar por sucursal"]').setValue("Polanco");
  expect(w.findAll('[data-prueba="oportunidad"]')).toHaveLength(1);
});
