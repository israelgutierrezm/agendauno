import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, ref } from "vue";
import { createI18n } from "vue-i18n";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";

import esMX from "@/i18n/locales/es-MX";
import {
  confirmarSinGuardar,
  hayCambiosPendientes,
  instantanea,
  useCambiosPendientes,
} from "./cambiosPendientes";

const confirmar = vi.hoisted(() => vi.fn());
vi.mock("@/lib/confirmar", () => ({ confirmar }));

/*
| Aviso de cambios sin guardar (documento de reformulación, UX05): salir de un
| formulario con cambios pregunta; moverse entre anclas de la misma pantalla no.
*/

const valor = ref("");
const Formulario = defineComponent({
  setup() {
    const foto = instantanea(() => valor.value);
    useCambiosPendientes(() => foto.cambio());
    return () => h("form", "formulario");
  },
});

async function montar() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/reglas", name: "reglas", component: Formulario },
      { path: "/otra", name: "otra", component: { render: () => h("p") } },
    ],
  });
  await router.push("/reglas");
  mount(
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
  return router;
}

beforeEach(() => {
  valor.value = "";
  confirmar.mockReset();
});

describe("cambios sin guardar", () => {
  it("sin cambios se sale sin preguntar", async () => {
    const router = await montar();
    await router.push("/otra");
    expect(confirmar).not.toHaveBeenCalled();
    expect(router.currentRoute.value.name).toBe("otra");
  });

  it("con cambios pregunta y se queda si no se confirma", async () => {
    const router = await montar();
    valor.value = "editado";
    await flushPromises();
    expect(hayCambiosPendientes()).toBe(true);

    confirmar.mockResolvedValue(false);
    await router.push("/otra");
    expect(confirmar).toHaveBeenCalledWith(
      esMX.comun.cambiosSinGuardar,
      expect.objectContaining({ aceptar: esMX.comun.salirSinGuardar }),
    );
    expect(router.currentRoute.value.name).toBe("reglas");

    confirmar.mockResolvedValue(true);
    await router.push("/otra");
    await flushPromises();
    expect(router.currentRoute.value.name).toBe("otra");
    expect(hayCambiosPendientes()).toBe(false);
  });

  it("ir a otra ancla de la misma pantalla no pregunta", async () => {
    const router = await montar();
    valor.value = "editado";
    await flushPromises();
    await router.push("/reglas#cierres");
    expect(confirmar).not.toHaveBeenCalled();
    expect(router.currentRoute.value.hash).toBe("#cierres");
    // Limpieza: sale confirmando para no dejar el registro sucio.
    confirmar.mockResolvedValue(true);
    await router.push("/otra");
  });

  it("volver al valor guardado deja de contar como cambio", async () => {
    await montar();
    valor.value = "editado";
    await flushPromises();
    expect(hayCambiosPendientes()).toBe(true);
    valor.value = "";
    await flushPromises();
    expect(hayCambiosPendientes()).toBe(false);
  });

  it("cambiar de rol pregunta solo si hay cambios", async () => {
    expect(await confirmarSinGuardar("m", "a")).toBe(true);
    expect(confirmar).not.toHaveBeenCalled();

    await montar();
    valor.value = "editado";
    await flushPromises();
    confirmar.mockResolvedValue(false);
    expect(await confirmarSinGuardar("m", "a")).toBe(false);
    confirmar.mockResolvedValue(true);
    expect(await confirmarSinGuardar("m", "a")).toBe(true);
    expect(hayCambiosPendientes()).toBe(false);
  });
});
