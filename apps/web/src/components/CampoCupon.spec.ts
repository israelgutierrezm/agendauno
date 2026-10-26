import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CampoCupon from "./CampoCupon.vue";

/*
| «¿Tienes un cupón?» (ADR 0220): se revisa antes de aplicarlo; uno que no sirve dice
| por qué y no queda puesto; si cambia sobre cuánto se calcula, se revisa otra vez.
*/

vi.mock("@/lib/api", () => ({
  mensajeDeError: (e: Error) => e.message,
}));

enableAutoUnmount(afterEach);

function montar(revisar: (c: string) => Promise<{ descuento_minor: number }>) {
  // Como un v-model: lo que emite vuelve como valor.
  const w = mount(CampoCupon, {
    props: {
      revisar,
      dinero: (m: number) => `$${(m / 100).toFixed(2)}`,
      base: 30000,
      modelValue: "",
      "onUpdate:modelValue": (v: string) => w.setProps({ modelValue: v }),
    },
    global: { plugins: [i18n] },
  });
  return w;
}

describe("campo de cupón", () => {
  it("revisa el código, muestra el descuento y lo deja puesto", async () => {
    const revisar = vi.fn().mockResolvedValue({ descuento_minor: 6000 });
    const w = montar(revisar);
    await w.get('[data-prueba="abrir-cupon"]').trigger("click");
    await w.get("#cc-codigo").setValue(" corte20 ");
    await w.get("#cc-codigo").trigger("keydown", { key: "Enter" });
    await flushPromises();

    expect(revisar).toHaveBeenCalledWith("corte20");
    expect(w.get('[data-prueba="cupon-aplicado"]').text()).toBe(
      "Cupón CORTE20: −$60.00",
    );
    expect(w.emitted("update:modelValue")?.at(-1)).toEqual(["corte20"]);
    expect(w.emitted("aplicado")?.at(-1)).toEqual([6000]);

    // Cambió el carrito: el mismo cupón, revisado otra vez.
    revisar.mockResolvedValue({ descuento_minor: 9000 });
    await w.setProps({ base: 45000 });
    await flushPromises();
    expect(revisar).toHaveBeenCalledTimes(2);
    expect(w.emitted("aplicado")?.at(-1)).toEqual([9000]);
  });

  it("uno que no sirve dice por qué y no queda puesto", async () => {
    const w = montar(() => Promise.reject(new Error("El cupón ya venció.")));
    await w.get('[data-prueba="abrir-cupon"]').trigger("click");
    await w.get("#cc-codigo").setValue("VIEJO");
    await w.get('[data-prueba="aplicar-cupon"]').trigger("click");
    await flushPromises();

    expect(w.get('[data-prueba="error-cupon"]').text()).toBe(
      "El cupón ya venció.",
    );
    expect(w.find('[data-prueba="cupon-aplicado"]').exists()).toBe(false);
    expect(w.props("modelValue")).toBe("");
    expect(w.emitted("aplicado")?.at(-1)).toEqual([0]);
    // Lo escrito se conserva para corregirlo.
    expect((w.get("#cc-codigo").element as HTMLInputElement).value).toBe(
      "VIEJO",
    );
  });
});
