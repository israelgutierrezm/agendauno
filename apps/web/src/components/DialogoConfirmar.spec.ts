import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { defineComponent, h, ref } from "vue";

import { i18n } from "@/i18n";
import { confirmar } from "@/lib/confirmar";
import DialogoConfirmar from "./DialogoConfirmar.vue";
import ModalDialogo from "./ModalDialogo.vue";

/*
| La confirmación se monta una sola vez al arrancar (App.vue), antes que cualquier
| modal; aun así debe verse encima del modal desde el que se pide (p. ej. «Cobrar»
| dentro del detalle de una cita).
*/

const montados: { unmount: () => void }[] = [];
afterEach(() => {
  montados.splice(0).forEach((w) => w.unmount());
  document.body.innerHTML = "";
});

function capaDe(texto: string): number {
  const dialogo = Array.from(
    document.querySelectorAll<HTMLElement>('[role="dialog"]'),
  ).find((d) => d.textContent?.includes(texto));
  return Number(dialogo?.parentElement?.style.zIndex);
}

describe("confirmación sobre un modal", () => {
  it("la que se abre después queda encima aunque se montó antes", async () => {
    montados.push(
      mount(DialogoConfirmar, {
        attachTo: document.body,
        global: { plugins: [i18n] },
      }),
    );
    const Modal = defineComponent(() => {
      const abierto = ref(true);
      return () =>
        h(ModalDialogo, { abierto: abierto.value, titulo: "Detalle" }, () =>
          h("p", "Contenido de la cita"),
        );
    });
    montados.push(
      mount(Modal, { attachTo: document.body, global: { plugins: [i18n] } }),
    );
    await flushPromises();

    const respuesta = confirmar("¿Registrar el cobro?");
    await flushPromises();

    expect(capaDe("¿Registrar el cobro?")).toBeGreaterThan(
      capaDe("Contenido de la cita"),
    );
    document
      .querySelectorAll<HTMLButtonElement>("button")
      .forEach((b) => b.textContent?.includes("Cancelar") && b.click());
    await expect(respuesta).resolves.toBe(false);
  });
});

describe("el botón que confirma", () => {
  async function botonPara(
    opciones: Parameters<typeof confirmar>[1],
  ): Promise<{ boton: HTMLButtonElement; respuesta: Promise<boolean> }> {
    montados.push(
      mount(DialogoConfirmar, {
        attachTo: document.body,
        global: { plugins: [i18n] },
      }),
    );
    await flushPromises();
    const respuesta = confirmar("¿Seguro?", opciones);
    await flushPromises();
    const boton = document.querySelector<HTMLButtonElement>(
      '[data-prueba="confirmar-aceptar"]',
    )!;
    return { boton, respuesta };
  }

  it("una acción destructiva confirma con el sólido de peligro", async () => {
    const { boton, respuesta } = await botonPara({ peligro: true });
    expect(boton.className).toContain("tu-btn-solido");
    expect(boton.className).toContain("tu-tono-peligro");
    boton.click();
    await expect(respuesta).resolves.toBe(true);
  });

  it("`tono: peligro` equivale a `peligro: true`", async () => {
    const { boton, respuesta } = await botonPara({ tono: "peligro" });
    expect(boton.className).toContain("tu-tono-peligro");
    boton.click();
    await expect(respuesta).resolves.toBe(true);
  });

  it("los demás tonos (completar, regresar) confirman con el primario", async () => {
    for (const tono of ["exito", "aviso"] as const) {
      const { boton, respuesta } = await botonPara({ tono });
      expect(boton.className).toContain("tu-btn-primario");
      expect(boton.className).not.toContain("tu-tono-peligro");
      boton.click();
      await expect(respuesta).resolves.toBe(true);
      montados.splice(0).forEach((w) => w.unmount());
      document.body.innerHTML = "";
    }
  });
});
