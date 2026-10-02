import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, nextTick, ref } from "vue";

import { i18n } from "@/i18n";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import SelectorSucursal from "./SelectorSucursal.vue";

/*
| Con qué sucursal se trabaja: con varias se elige en la barra y los filtros la
| siguen (su propio selector desaparece); con una, solo se muestra su nombre.
*/

const sesion = vi.hoisted(() => ({
  slug: "demo",
  usuario: {
    ulid: "u1",
    rol: "propietario",
    roles: ["propietario"],
    sucursales: [] as { id: string; nombre: string; zona_horaria: string }[],
  },
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

const ROMA = {
  id: "roma",
  nombre: "Roma Norte",
  zona_horaria: "America/Mexico_City",
};
const VALLE = {
  id: "valle",
  nombre: "Del Valle",
  zona_horaria: "America/Mexico_City",
};

// Una pantalla con un filtro de sucursal propio.
const Listado = defineComponent(() => {
  const filtro = ref("");
  const { mostrarSelect } = useSucursalOperativa({ filtro });
  return () =>
    h("div", [
      h("span", { "data-prueba": "filtro" }, filtro.value),
      mostrarSelect.value ? h("select", { "data-prueba": "propio" }) : null,
    ]);
});

beforeEach(() => {
  localStorage.clear();
});

describe("sucursal con la que se trabaja", () => {
  it("con varias: «Todas» por omisión; al elegir una, el filtro la usa y su selector sobra", async () => {
    sesion.usuario.sucursales = [VALLE, ROMA];
    const barra = mount(SelectorSucursal, { global: { plugins: [i18n] } });
    const pantalla = mount(Listado);

    const selector = barra.get('[data-prueba="selector-sucursal"]');
    expect((selector.element as HTMLSelectElement).value).toBe("");
    expect(barra.get('[data-prueba="sucursal-actual"]').text()).toBe(
      "Todas las sucursales",
    );
    expect(barra.find(".ss-vivo").exists()).toBe(false);
    expect(pantalla.find('[data-prueba="propio"]').exists()).toBe(true);

    await selector.setValue("roma");
    await nextTick();
    expect(pantalla.get('[data-prueba="filtro"]').text()).toBe("roma");
    expect(pantalla.find('[data-prueba="propio"]').exists()).toBe(false);
    // Operando en una sucursal: se dice y el punto late.
    expect(barra.text()).toContain("Operando en");
    expect(barra.get('[data-prueba="sucursal-actual"]').text()).toBe(
      "Roma Norte",
    );
    expect(barra.find(".ss-vivo").exists()).toBe(true);

    await selector.setValue("");
    await nextTick();
    expect(pantalla.get('[data-prueba="filtro"]').text()).toBe("");
  });

  it("con una sola: no hay selector, solo su nombre, y se usa siempre", () => {
    sesion.usuario.sucursales = [ROMA];
    const barra = mount(SelectorSucursal, { global: { plugins: [i18n] } });
    const nombre = mount(SelectorSucursal, {
      props: { variante: "unica" },
      global: { plugins: [i18n] },
    });
    const pantalla = mount(Listado);

    expect(barra.find('[data-prueba="selector-sucursal"]').exists()).toBe(
      false,
    );
    expect(nombre.get('[data-prueba="sucursal-unica"]').text()).toBe(
      "Roma Norte",
    );
    expect(pantalla.get('[data-prueba="filtro"]').text()).toBe("roma");
    expect(pantalla.find('[data-prueba="propio"]').exists()).toBe(false);
  });
});
