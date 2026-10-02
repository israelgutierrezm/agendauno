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
