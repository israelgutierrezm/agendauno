import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { i18n } from "@/i18n";
import ModalDialogo from "./ModalDialogo.vue";

afterEach(() => {
  document.body.style.overflow = "";
});
describe("diálogo del administrador", () => {
  it("explica el formulario y no cierra mientras se guarda", async () => {
    const w = mount(ModalDialogo, {
      props: {
        abierto: true,
        titulo: "Editar",
        descripcion: "Datos de la sucursal",
        ocupado: true,
      },
      global: { plugins: [i18n], stubs: { Teleport: true } },
    });
    expect(w.get('[role="dialog"]').attributes("aria-busy")).toBe("true");
    expect(w.text()).toContain("Datos de la sucursal");
    expect(w.get("button").attributes("disabled")).toBeDefined();
    await w.get(".absolute.inset-0").trigger("click");
    expect(w.emitted("cerrar")).toBeUndefined();
    await w.setProps({ ocupado: false });
    await w.get("button").trigger("click");
    expect(w.emitted("cerrar")).toHaveLength(1);
    w.unmount();
  });
});
