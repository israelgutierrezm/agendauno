import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";
import { defineComponent, h } from "vue";
import { createI18n } from "vue-i18n";
import {
  createRouter,
  createWebHistory,
  RouterLink,
  RouterView,
} from "vue-router";

import esMX from "@/i18n/locales/es-MX";
import { useRegreso } from "./regreso";

/*
| «Volver» de una ficha (documento de reformulación, recorrido 5): regresa a la
| bandeja de donde se llegó sin perderla; entrando directo, a la lista de siempre.
*/

const Ficha = defineComponent({
  setup() {
    const regreso = useRegreso({
      name: "miembros",
      etiqueta: () => "Volver a miembros",
    });
    return () =>
      h(
        RouterLink,
        { to: regreso.destino.value, class: "volver" },
        () => regreso.etiqueta.value,
      );
  },
});
const Vacia = { render: () => h("p") };

async function montar(recorrido: string[]) {
  window.history.replaceState(null, "", "/");
  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: "/", component: Vacia },
      { path: "/miembros", name: "miembros", component: Vacia },
      { path: "/miembros/:id", name: "ficha-miembro", component: Ficha },
      { path: "/formularios", name: "formularios", component: Vacia },
      { path: "/recepcion", name: "recepcion", component: Vacia },
      { path: "/importar", name: "importar", component: Vacia },
    ],
  });
  for (const ruta of recorrido) {
    await router.push(ruta);
  }
  const w = mount(
    { render: () => h(RouterView) },
    {
      global: {
        plugins: [
          router,
          createI18n({
            legacy: false,
            locale: "es",
            missingWarn: false,
            fallbackWarn: false,
            messages: { es: esMX },
          }),
        ],
      },
    },
  );
  await flushPromises();
  return w.get("a.volver");
}

beforeEach(() => {
  setActivePinia(createPinia());
});

describe("volver de una ficha", () => {
  it("regresa a la bandeja de respuestas de donde se llegó", async () => {
    const a = await montar(["/formularios?vista=respuestas", "/miembros/1"]);
    expect(a.attributes("href")).toBe("/formularios?vista=respuestas");
    expect(a.text()).toBe("Volver a respuestas de fichas de datos");
  });

  it("regresa a Recepción", async () => {
    const a = await montar(["/recepcion", "/miembros/1"]);
    expect(a.attributes("href")).toBe("/recepcion");
    expect(a.text()).toBe("Volver a recepción");
  });

  it("desde la lista, o entrando directo, vuelve a la lista", async () => {
    let a = await montar(["/miembros", "/miembros/1"]);
    expect(a.attributes("href")).toBe("/miembros");
    expect(a.text()).toBe("Volver a miembros");

    a = await montar(["/miembros/1"]);
    expect(a.attributes("href")).toBe("/miembros");
  });

  it("las pantallas de paso (importar) no cuentan como origen", async () => {
    const a = await montar(["/importar", "/miembros/1"]);
    expect(a.attributes("href")).toBe("/miembros");
  });
});
