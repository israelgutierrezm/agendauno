import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { defineComponent, nextTick, ref } from "vue";

import { i18n } from "@/i18n";
import CampoCelular from "./CampoCelular.vue";

/*
| Celular con su lada (ADR 0103): la lada de la lista completa de países (la del
| negocio de inicio) y el número aparte. El valor es «+<lada> <número>»; uno guardado
| sin «+» se muestra con la lada del negocio y no se reescribe si no se toca.
*/

const sesion = vi.hoisted(() => ({ pais: "CO", lada: "57" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

function montar(inicial = "", props: Record<string, unknown> = {}) {
  const valor = ref(inicial);
  const datos = ref(props);
  const Envoltura = defineComponent({
    components: { CampoCelular },
    setup() {
      return { valor, datos };
    },
    template: `<label for="cel">Celular</label>
      <CampoCelular id="cel" v-model="valor" v-bind="datos" data-prueba="numero" />`,
  });
  const w = mount(Envoltura, { global: { plugins: [i18n] } });
  return { w, valor, datos };
}

function lada(w: ReturnType<typeof montar>["w"]): string {
  return w.get('[data-prueba="lada-celular"]').text();
}

describe("celular con lada", () => {
  it("empieza con la lada del negocio y manda «+lada número»", async () => {
    const { w, valor } = montar();
    expect(lada(w)).toBe("CO +57");
    const numero = w.get('[data-prueba="numero"]');
    expect(numero.attributes("id")).toBe("cel");
    expect(numero.attributes("type")).toBe("tel");

    await numero.setValue("300 123 4567");
    expect(valor.value).toBe("+57 3001234567");
    // Lo que escribe se queda como lo escribió.
    expect((numero.element as HTMLInputElement).value).toBe("300 123 4567");

    await numero.setValue("");
    expect(valor.value).toBe("");
  });

  it("ofrece todos los países, los más usados primero, y cambia la lada", async () => {
    const { w, valor } = montar();
    const select = w.get("select");
    expect(select.attributes("aria-label")).toBe("Lada del país");
    const grupos = w.findAll("optgroup");
    expect(grupos.map((g) => g.attributes("label"))).toEqual([
      "Más usados",
      "Todos los países",
    ]);
    expect(grupos[0].find("option").text()).toBe("México +52");
    expect(w.findAll("option")).toHaveLength(250);

    await w.get('[data-prueba="numero"]').setValue("991234567");
    await select.setValue("EC");
    expect(lada(w)).toBe("EC +593");
    expect(valor.value).toBe("+593 991234567");
  });

  it("muestra un número guardado con su lada; sin «+», con la del negocio", async () => {
    const { w, valor } = montar("+1 809 555 1234");
    expect(lada(w)).toBe("US +1");
    expect(
      (w.get('[data-prueba="numero"]').element as HTMLInputElement).value,
    ).toBe("8095551234");

    valor.value = "3001234567";
    await nextTick();
    expect(lada(w)).toBe("CO +57");
    expect(
      (w.get('[data-prueba="numero"]').element as HTMLInputElement).value,
    ).toBe("3001234567");
    // No se reescribe mientras no se toque.
    expect(valor.value).toBe("3001234567");
  });

  it("al pegar el número completo toma la lada de ahí", async () => {
    const { w, valor } = montar();
    const numero = w.get('[data-prueba="numero"]');
    await numero.setValue("+34 612 345 678");
    expect(lada(w)).toBe("ES +34");
    expect((numero.element as HTMLInputElement).value).toBe("612345678");
    expect(valor.value).toBe("+34 612345678");
  });

  it("al teclear «+» y la lada, el campo los conserva hasta que llega el número", async () => {
    const { w, valor } = montar();
    const numero = w.get('[data-prueba="numero"]');
    const campo = numero.element as HTMLInputElement;
    for (const texto of ["+", "+5", "+52", "+52 "]) {
      await numero.setValue(texto);
      expect(campo.value).toBe(texto);
      // Aún no hay número ni cambia la lada.
      expect(valor.value).toBe("");
      expect(lada(w)).toBe("CO +57");
    }
    await numero.setValue("+52 5");
    expect(lada(w)).toBe("MX +52");
    expect(campo.value).toBe("5");
    expect(valor.value).toBe("+52 5");
    await numero.setValue("55 1234 5678");
    expect(valor.value).toBe("+52 5512345678");

    // Sin espacio, también: la lada sale en cuanto sigue un dígito.
    await numero.setValue("");
    await numero.setValue("+57");
    expect(campo.value).toBe("+57");
    await numero.setValue("+573");
    expect(lada(w)).toBe("CO +57");
    expect(campo.value).toBe("3");
    expect(valor.value).toBe("+57 3");

    // Lo que no empieza con una lada de la lista no se pierde: va como se escribió.
    await numero.setValue("+999 1234");
    expect(campo.value).toBe("+999 1234");
    expect(valor.value).toBe("+999 1234");
  });

  it("con `pais` usa esa lada y la sigue mientras no haya número", async () => {
    const { w, datos } = montar("", { pais: "PE" });
    expect(lada(w)).toBe("PE +51");
    datos.value = { pais: "CL" };
    await nextTick();
    expect(lada(w)).toBe("CL +56");
    // Con `lada` (sin país): el país de esa lada.
    datos.value = { lada: "54" };
    await nextTick();
    expect(lada(w)).toBe("AR +54");
  });
});
