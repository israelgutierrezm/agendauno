import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import CampoContrasena from "./CampoContrasena.vue";

/*
| «Ver contraseña» se alcanza con el teclado: está en el recorrido de foco (Tab después
| del campo) y es un botón, así que Enter o Espacio lo activan.
*/

function montar() {
  return mount(CampoContrasena, {
    props: { id: "clave", modelValue: "secreta" },
    global: { plugins: [i18n] },
    attachTo: document.body,
  });
}

describe("campo de contraseña", () => {
  it("el botón para verla está en el recorrido de foco, después del campo", () => {
    const w = montar();
    const boton = w.get('[data-prueba="ver-contrasena"]');
    expect(boton.element.tagName).toBe("BUTTON");
    expect(boton.attributes("tabindex")).toBeUndefined();
    expect((boton.element as HTMLButtonElement).tabIndex).toBe(0);
    expect(boton.attributes("aria-controls")).toBe("clave");
    // En el documento va justo después del campo: Tab pasa del campo al botón.
    const enfocables = [...document.querySelectorAll("input, button")];
    expect(enfocables.indexOf(boton.element)).toBe(
      enfocables.indexOf(w.get("input").element) + 1,
    );
    w.unmount();
  });

  it("al activarlo muestra y oculta lo escrito, y lo anuncia", async () => {
    const w = montar();
    const boton = w.get('[data-prueba="ver-contrasena"]');
    (boton.element as HTMLButtonElement).focus();
    expect(document.activeElement).toBe(boton.element);
    // Enter o Espacio en un botón lo activan (el navegador dispara el clic).
    await boton.trigger("click");
    expect(w.get("input").attributes("type")).toBe("text");
    expect(boton.attributes("aria-pressed")).toBe("true");
    await boton.trigger("click");
    expect(w.get("input").attributes("type")).toBe("password");
    expect(boton.attributes("aria-pressed")).toBe("false");
    w.unmount();
  });
});
