import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import NegociosAnimados from "./NegociosAnimados.vue";

const props = {
  negocios: ["Pilates", "barberías"],
  prefijo: "Una agenda para",
  pausar: "Pausar texto animado",
  reanudar: "Reanudar texto animado",
};
const montadas: ReturnType<typeof mount>[] = [];
function montar(reducido = false, negocios = props.negocios) {
  vi.stubGlobal("matchMedia", () => ({
    matches: reducido,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  }));
  const vista = mount(NegociosAnimados, { props: { ...props, negocios } });
  montadas.push(vista);
  return vista;
}
beforeEach(() => {
  vi.useFakeTimers();
});
afterEach(() => {
  montadas.splice(0).forEach((vista) => vista.unmount());
  vi.useRealTimers();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("escritura de negocios del hero", () => {
  it("mantiene el texto accesible completo y un primer negocio legible", () => {
    const vista = montar();
    expect(vista.get(".negocios-texto").text()).toBe("Pilates|");
    expect(vista.get(".sr-only").text()).toBe(
      "Una agenda para Pilates, barberías.",
    );
    expect(vista.get(".negocios-frase").attributes("aria-hidden")).toBe("true");
    expect(vista.findAll(".negocios-medida")).toHaveLength(2);
  });
  it("borra y escribe letra por letra, espera y vuelve al principio", async () => {
    const vista = montar();
    await vi.advanceTimersByTimeAsync(2400);
    expect(vista.get(".negocios-texto").text()).toBe("Pilate|");
    await vi.advanceTimersByTimeAsync(38 * 6);
    expect(vista.get(".negocios-texto").text()).toBe("|");
    await vi.advanceTimersByTimeAsync(300);
    expect(vista.get(".negocios-texto").text()).toBe("b|");
    await vi.advanceTimersByTimeAsync(75 * 8);
    expect(vista.get(".negocios-texto").text()).toBe("barberías|");
    await vi.advanceTimersByTimeAsync(2400 + 38 * 8 + 300 + 75 * 6);
    expect(vista.get(".negocios-texto").text()).toBe("Pilates|");
  });
  it("permite pausar y reanudar sin cambiar el negocio", async () => {
    const vista = montar();
    await vista.get("button").trigger("click");
    await vi.advanceTimersByTimeAsync(10000);
    expect(vista.get(".negocios-texto").text()).toBe("Pilates|");
    expect(vista.get("button").attributes("aria-label")).toBe(props.reanudar);
    await vista.get("button").trigger("click");
    await vi.advanceTimersByTimeAsync(2400);
    expect(vista.get(".negocios-texto").text()).toBe("Pilate|");
  });
  it("respeta movimiento reducido con texto fijo y sin cursor", async () => {
    const vista = montar(true);
    await vi.advanceTimersByTimeAsync(10000);
    expect(vista.get(".negocios-texto").text()).toBe("Pilates");
    expect(vista.find("button").exists()).toBe(false);
    expect(vi.getTimerCount()).toBe(0);
  });
  it("suspende los temporizadores cuando la pestaña queda oculta", async () => {
    const vista = montar();
    await vi.advanceTimersByTimeAsync(100);
    const visibilidad = vi
      .spyOn(document, "visibilityState", "get")
      .mockReturnValue("hidden");
    document.dispatchEvent(new Event("visibilitychange"));
    await vi.advanceTimersByTimeAsync(10000);
    expect(vista.get(".negocios-texto").text()).toBe("Pilates|");
    expect(vi.getTimerCount()).toBe(0);
    visibilidad.mockReturnValue("visible");
    document.dispatchEvent(new Event("visibilitychange"));
    await vi.advanceTimersByTimeAsync(2400);
    expect(vista.get(".negocios-texto").text()).toBe("Pilate|");
  });
  it("no anima una sola opción y reinicia si cambian los textos", async () => {
    const vista = montar(false, ["Pilates"]);
    await vi.advanceTimersByTimeAsync(10000);
    expect(vi.getTimerCount()).toBe(0);
    expect(vista.find("button").exists()).toBe(false);
    await vista.setProps({ negocios: ["academias", "spas"] });
    expect(vista.get(".negocios-texto").text()).toBe("academias|");
    await vi.advanceTimersByTimeAsync(2400);
    expect(vista.get(".negocios-texto").text()).toBe("academia|");
  });
  it("libera el temporizador al salir de la página", async () => {
    const vista = montar();
    await vi.advanceTimersByTimeAsync(100);
    expect(vi.getTimerCount()).toBe(1);
    vista.unmount();
    montadas.splice(0);
    expect(vi.getTimerCount()).toBe(0);
  });
});
