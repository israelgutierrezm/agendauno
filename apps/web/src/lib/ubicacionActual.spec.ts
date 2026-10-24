import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";
import { defineComponent, h } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";

import { i18n } from "@/i18n";
import { POLITICAS } from "@/lib/acceso";
import { useUbicacionActual } from "@/lib/ubicacionActual";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/*
| La ruta de ubicación de la barra superior nombra la vista como el menú: quien
| trabaja solo ve «Tu agenda y sucursales» (la `etiquetaSolo` de la vista), no
| «Equipo y sucursales».
*/

const Vacia = { render: () => h("div") };

const Barra = defineComponent({
  setup() {
    const lugar = useUbicacionActual();
    return () =>
      h(
        "div",
        lugar.migas.value.map((m) => h("span", { class: "miga" }, m.texto)),
      );
  },
});

async function migas(conEquipo: boolean): Promise<string[]> {
  const sesion = useSesionTenantStore();
  sesion.usuario = { rol: "admin", permisos: ["*"] } as never;
  sesion.estudio = {
    perfil_config: { modalidad: "clases", flags: { grupos: false } },
    con_equipo: conEquipo,
  } as never;
  const router = createRouter({
    history: createMemoryHistory(),
    routes: Object.keys(POLITICAS).map((name) => ({
      path: `/${name}`,
      name,
      component: Vacia,
    })),
  });
  await router.push({ name: "reportes", query: { vista: "sucursales" } });
  const w = mount(Barra, { global: { plugins: [router, i18n] } });
  await flushPromises();
  return w.findAll(".miga").map((m) => m.text());
}

beforeEach(() => {
  setActivePinia(createPinia());
});

describe("ruta de ubicación", () => {
  it("con equipo, «Reportes › Equipo y sucursales»", async () => {
    expect(await migas(true)).toEqual(["Reportes", "Equipo y sucursales"]);
  });

  it("quien trabaja solo ve la etiqueta de la vista para él", async () => {
    expect(await migas(false)).toEqual(["Reportes", "Tu agenda y sucursales"]);
  });
});
