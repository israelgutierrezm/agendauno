import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { nextTick } from "vue";

import { i18n } from "@/i18n";
import { efectivoDe, partesListas } from "@/lib/formasDePago";
import FormasDePago from "./FormasDePago.vue";

/*
| Cobro mixto (ADR 0177): dividir el cobro entre varias formas. El último renglón se
| completa solo con lo que falta; las partes sirven si suman el total sin repetir
| forma; el cambio sale solo del efectivo.
*/

function montar(total = 12000) {
  return mount(FormasDePago, {
    props: {
      total,
      metodos: ["efectivo", "tarjeta", "transferencia"],
      prefijo: "pos.metodos",
      inicial: "efectivo",
      moneda: "MXN",
      pais: "MX",
      dividido: false,
      partes: null,
      "onUpdate:dividido": () => undefined,
      "onUpdate:partes": () => undefined,
    },
    global: { plugins: [i18n] },
  });
}

type Emitidas = { metodo: string; monto_minor: number }[] | null;
function ultimas(w: ReturnType<typeof montar>): Emitidas {
  const eventos = w.emitted("update:partes") ?? [];
  return (eventos.at(-1)?.[0] ?? null) as Emitidas;
}

describe("formas de pago", () => {
  it("al dividir, el último renglón completa lo que falta", async () => {
    const w = montar();
    await w.get('[data-prueba="dividir-pago"]').trigger("click");
    await w.setProps({ dividido: true });
    expect(w.findAll('[data-prueba="parte-pago"]')).toHaveLength(2);

    await w.get('[data-prueba="monto-0"]').setValue("70");
    await nextTick();
    const partes = ultimas(w);
    expect(partes).toEqual([
      { metodo: "efectivo", monto_minor: 7000 },
      { metodo: "tarjeta", monto_minor: 5000 },
    ]);
    expect(partesListas(partes, 12000)).toBe(true);
    expect(efectivoDe(partes)).toBe(7000);
    expect(w.get('[data-prueba="estado-formas"]').text()).toBe(
      "Las formas suman el total.",
    );
  });

  it("si un monto pasa del total lo dice, y no sirven", async () => {
    const w = montar();
    await w.get('[data-prueba="dividir-pago"]').trigger("click");
    await w.setProps({ dividido: true });
    await w.get('[data-prueba="monto-0"]').setValue("150");
    await nextTick();
    expect(w.get('[data-prueba="estado-formas"]').text()).toContain(
      "suman más que el total",
    );
    expect(partesListas(ultimas(w), 12000)).toBe(false);
  });

  it("agrega una forma antes de la que completa, y vuelve a una sola", async () => {
    const w = montar(30000);
    await w.get('[data-prueba="dividir-pago"]').trigger("click");
    await w.setProps({ dividido: true });
    await w.get('[data-prueba="agregar-forma"]').trigger("click");
    expect(w.findAll('[data-prueba="parte-pago"]')).toHaveLength(3);
    await w.get('[data-prueba="monto-0"]').setValue("100");
    await w.get('[data-prueba="monto-1"]').setValue("50");
    await nextTick();
    expect(ultimas(w)).toEqual([
      { metodo: "efectivo", monto_minor: 10000 },
      { metodo: "transferencia", monto_minor: 5000 },
      { metodo: "tarjeta", monto_minor: 15000 },
    ]);
    // Ya no quedan formas sin usar.
    expect(w.find('[data-prueba="agregar-forma"]').exists()).toBe(false);

    await w.get('[data-prueba="una-forma"]').trigger("click");
    expect(w.emitted("update:dividido")?.at(-1)?.[0]).toBe(false);
  });

  it("no sirven partes repetidas, en cero ni que no sumen", () => {
    expect(
      partesListas(
        [
          { metodo: "efectivo", monto_minor: 6000 },
          { metodo: "efectivo", monto_minor: 6000 },
        ],
        12000,
      ),
    ).toBe(false);
    expect(
      partesListas(
        [
          { metodo: "efectivo", monto_minor: 12000 },
          { metodo: "tarjeta", monto_minor: 0 },
        ],
        12000,
      ),
    ).toBe(false);
    expect(partesListas(null, 0)).toBe(false);
  });
});
