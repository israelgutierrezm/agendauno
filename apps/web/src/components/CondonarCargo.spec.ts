import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import CondonarCargo from "./CondonarCargo.vue";

/*
| Condonar un cargo de renta (superadmin): pide el motivo en la fila y solo avisa con
| un motivo escrito; «Cancelar» lo cierra sin avisar.
*/

function montar() {
  return mount(CondonarCargo, { global: { plugins: [i18n] } });
}

describe("CondonarCargo", () => {
  it("pide el motivo y avisa con él (sin espacios de más)", async () => {
    const w = montar();
    await w.get('[data-prueba="condonar"]').trigger("click");

    const enviar = w.get('form button[type="submit"]');
    expect(enviar.attributes("disabled")).toBeDefined();

    await w.get("input").setValue("  Error de cobro de la plataforma  ");
    await w.get("form").trigger("submit");

    expect(w.emitted("condonar")).toEqual([
      ["Error de cobro de la plataforma"],
    ]);
  });

  it("«Cancelar» lo cierra sin condonar", async () => {
    const w = montar();
    await w.get('[data-prueba="condonar"]').trigger("click");
    await w.get("input").setValue("Algo");
    const cancelar = w.findAll("button").find((b) => b.text() === "Cancelar")!;
    await cancelar.trigger("click");

    expect(w.find("form").exists()).toBe(false);
    expect(w.emitted("condonar")).toBeUndefined();
  });
});
