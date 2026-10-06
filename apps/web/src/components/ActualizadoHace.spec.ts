import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ActualizadoHace from "./ActualizadoHace.vue";

describe("actualizado hace…", () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it("dice cuándo se trajeron los datos y se pone al día solo", async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-10-05T12:00:00Z"));
    const w = mount(ActualizadoHace, {
      props: { en: new Date("2026-10-05T12:00:00Z") },
      global: { plugins: [i18n] },
    });
    expect(w.text()).toContain("Actualizado hace un momento");

    vi.setSystemTime(new Date("2026-10-05T12:05:10Z"));
    vi.advanceTimersByTime(30_000);
    await w.vm.$nextTick();
    expect(w.text()).toContain("Actualizado hace 5 min");
    w.unmount();
  });

  it("el botón pide actualizar y espera mientras tanto", async () => {
    const w = mount(ActualizadoHace, {
      props: { en: new Date(), actualizando: false },
      global: { plugins: [i18n] },
    });
    await w.get('[data-prueba="actualizar"]').trigger("click");
    expect(w.emitted("actualizar")).toHaveLength(1);

    await w.setProps({ actualizando: true });
    const boton = w.get('[data-prueba="actualizar"]');
    expect(boton.text()).toBe("Actualizando…");
    expect(boton.attributes("disabled")).toBeDefined();
  });
});
