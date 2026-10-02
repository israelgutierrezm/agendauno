import { flushPromises, mount } from "@vue/test-utils";
import { createPinia } from "pinia";
import { createI18n } from "vue-i18n";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import esMX from "@/i18n/locales/es-MX";
import { agendaAmpliada } from "@/lib/pantallaCompleta";
import BotonPantallaCompleta from "./BotonPantallaCompleta.vue";

let elemento: Element | null;
const solicitar = vi.fn();
const salir = vi.fn();
const montados: ReturnType<typeof mount>[] = [];
function montar(agenda = false) {
  const w = mount(BotonPantallaCompleta, {
    props: { agenda },
    global: {
      plugins: [
        createPinia(),
        createI18n({ legacy: false, locale: "es", messages: { es: esMX } }),
      ],
    },
  });
  montados.push(w);
  return w;
}
function cambiarNativa(valor: Element | null) {
  elemento = valor;
  document.dispatchEvent(new Event("fullscreenchange"));
}
beforeEach(() => {
  elemento = null;
  agendaAmpliada.value = false;
  Object.defineProperty(document, "fullscreenElement", {
    configurable: true,
    get: () => elemento,
  });
  Object.defineProperty(document, "fullscreenEnabled", {
    configurable: true,
    get: () => true,
  });
  solicitar
    .mockReset()
    .mockImplementation(async () => cambiarNativa(document.documentElement));
  salir.mockReset().mockImplementation(async () => cambiarNativa(null));
  document.documentElement.requestFullscreen = solicitar;
  document.exitFullscreen = salir;
});
afterEach(() => {
  for (const w of montados.splice(0)) w.unmount();
  vi.restoreAllMocks();
  agendaAmpliada.value = false;
});

describe("Pantalla completa", () => {
  it("el icono del header amplía el documento y permite salir", async () => {
    const w = montar();
    await flushPromises();
    await w.get("button").trigger("click");
    await flushPromises();
    expect(solicitar).toHaveBeenCalledOnce();
    expect(w.get("button").attributes("aria-pressed")).toBe("true");
    expect(w.get("button").attributes("aria-label")).toBe(
      "Salir de pantalla completa",
    );
    expect(agendaAmpliada.value).toBe(false);
    await w.get("button").trigger("click");
    await flushPromises();
    expect(salir).toHaveBeenCalledOnce();
    expect(w.get("button").attributes("aria-pressed")).toBe("false");
  });

  it("amplía la agenda y restaura su navegación al salir con Escape del navegador", async () => {
    const w = montar(true);
    await w.get("button").trigger("click");
    await flushPromises();
    expect(agendaAmpliada.value).toBe(true);
    cambiarNativa(null);
    await flushPromises();
    expect(agendaAmpliada.value).toBe(false);
    expect(w.text()).toBe("Ver en pantalla completa");
  });

  it("no abandona la pantalla completa global cuando solo cierra la agenda ampliada", async () => {
    cambiarNativa(document.documentElement);
    const w = montar(true);
    await w.get("button").trigger("click");
    await flushPromises();
    await w.get("button").trigger("click");
    await flushPromises();
    expect(salir).not.toHaveBeenCalled();
    expect(elemento).toBe(document.documentElement);
    expect(agendaAmpliada.value).toBe(false);
  });

  it("permite ampliar la agenda si el navegador rechaza la solicitud", async () => {
    solicitar.mockRejectedValue(new Error("Not allowed"));
    const w = montar(true);
    await w.get("button").trigger("click");
    await flushPromises();
    expect(agendaAmpliada.value).toBe(true);
    window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    await flushPromises();
    expect(agendaAmpliada.value).toBe(false);
  });

  it("no cierra la agenda al presionar Escape con un diálogo abierto", async () => {
    solicitar.mockRejectedValue(new Error("Not allowed"));
    const w = montar(true);
    await w.get("button").trigger("click");
    await flushPromises();
    const modal = document.createElement("section");
    modal.setAttribute("aria-modal", "true");
    document.body.append(modal);
    window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    expect(agendaAmpliada.value).toBe(true);
    modal.remove();
  });

  it("restaura la navegación y la pantalla al abandonar la agenda", async () => {
    const w = montar(true);
    await w.get("button").trigger("click");
    await flushPromises();
    w.unmount();
    await flushPromises();
    expect(agendaAmpliada.value).toBe(false);
    expect(salir).toHaveBeenCalledOnce();
  });
});
