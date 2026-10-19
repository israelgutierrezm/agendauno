import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CampanasView from "./CampanasView.vue";

/*
| Campañas en Captación (ADR 0167): el reporte por campaña con su periodo; por
| omisión, el mes en curso del negocio.
*/

vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ zonaHoraria: "America/Mexico_City" }),
}));

function montar() {
  return mount(CampanasView, {
    global: {
      plugins: [i18n],
      stubs: {
        CampanasNegocio: {
          props: ["desde", "hasta"],
          template: '<p data-prueba="reporte">{{ desde }} a {{ hasta }}</p>',
        },
      },
    },
  });
}

describe("campañas", () => {
  it("muestra el reporte del mes en curso y lo cambia con el periodo", async () => {
    const w = montar();
    await flushPromises();
    const desde = (
      w.get('[data-prueba="campanas-desde"]').element as HTMLInputElement
    ).value;
    expect(desde).toMatch(/^\d{4}-\d{2}-01$/);
    expect(w.get('[data-prueba="reporte"]').text()).toContain(desde);

    await w.get('[data-prueba="campanas-desde"]').setValue("2026-09-01");
    await w.get('[data-prueba="campanas-hasta"]').setValue("2026-09-30");
    expect(w.get('[data-prueba="reporte"]').text()).toBe(
      "2026-09-01 a 2026-09-30",
    );
  });

  it("un periodo al revés se dice y no se pide el reporte", async () => {
    const w = montar();
    await w.get('[data-prueba="campanas-desde"]').setValue("2026-09-30");
    await w.get('[data-prueba="campanas-hasta"]').setValue("2026-09-01");
    expect(w.find('[data-prueba="reporte"]').exists()).toBe(false);
    expect(w.get('[data-prueba="campanas-periodo"]').text()).toContain(
      "va antes",
    );
  });
});
