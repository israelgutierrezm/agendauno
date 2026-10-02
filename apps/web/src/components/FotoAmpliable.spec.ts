import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import FotoAmpliable from "./FotoAmpliable.vue";

const vistas: ReturnType<typeof mount>[] = [];
function montar(foto: string | null = "/storage/ana.webp") {
  const vista = mount(FotoAmpliable, {
    attachTo: document.body,
    props: { nombre: "Ana Pérez López", foto },
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
    },
  });
  vistas.push(vista);
  return vista;
}

afterEach(() => {
  vistas.splice(0).forEach((vista) => vista.unmount());
});

describe("FotoAmpliable", () => {
  it("abre desde la imagen y muestra el nombre completo", async () => {
    const vista = montar();
    await vista.get("img").trigger("click");
    const modal = document.querySelector('[role="dialog"]');
    expect(modal?.querySelector("img")?.getAttribute("src")).toBe(
      "/storage/ana.webp",
    );
    expect(modal?.textContent).toContain("Ana Pérez López");
    modal?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
    await flushPromises();
    expect(document.querySelector('[role="dialog"]')).toBeNull();
  });

  it("mantiene el foco en el modal y lo devuelve al cerrar con Escape", async () => {
    const vista = montar();
    const abrir = vista.get("button");
    abrir.element.focus();
    await abrir.trigger("click");
    await flushPromises();
    const cerrar = document.querySelector('[role="dialog"] button');
    expect(document.activeElement).toBe(cerrar);
    const tab = new KeyboardEvent("keydown", {
      key: "Tab",
      shiftKey: true,
      cancelable: true,
    });
    window.dispatchEvent(tab);
    expect(tab.defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(cerrar);
    window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    await flushPromises();
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    expect(document.activeElement).toBe(abrir.element);
  });

  it("cierra con el botón y permite volver a abrir", async () => {
    const vista = montar();
    await vista.get("button").trigger("click");
    (
      document.querySelector('[role="dialog"] button') as HTMLButtonElement
    ).click();
    await flushPromises();
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    await vista.get("img").trigger("click");
    expect(document.querySelector('[role="dialog"]')).not.toBeNull();
  });

  it("sin foto conserva la inicial sin ofrecer ampliación", () => {
    const vista = montar(null);
    expect(vista.find("button").exists()).toBe(false);
    expect(vista.text()).toBe("A");
  });
});
