import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";

import { parametrosConfig } from "@/i18n/locales/gestion.es-MX";
import type { Parametro } from "@/lib/parametros";
import ParametrosEditor from "./ParametrosEditor.vue";

const parametros: Parametro[] = [
  {
    clave: "reservas.minutos_para_pagar",
    grupo: "Reservas y lista de espera",
    etiqueta: "Tiempo para pagar una reserva apartada",
    ayuda: "Si no se paga en este tiempo, el lugar se libera.",
    tipo: "entero",
    minimo: 5,
    maximo: 1440,
    unidad: "min",
    valor: null,
    plataforma: 30,
  },
  {
    clave: "recordatorios.segundo_horas",
    grupo: "Recordatorios",
    etiqueta: "Segundo recordatorio",
    ayuda: "",
    tipo: "entero",
    minimo: 0,
    maximo: 48,
    unidad: "h",
    valor: 2,
    plataforma: 2,
  },
];

function montar() {
  return mount(ParametrosEditor, {
    props: { parametros, modo: "negocio" },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { parametrosConfig } },
        }),
      ],
    },
  });
}

describe("editor de parámetros", () => {
  it("muestra el valor de la plataforma como referencia y agrupa", () => {
    const w = montar();
    expect(w.text()).toContain("Reservas y lista de espera");
    expect(w.text()).toContain("Plataforma: 30 min.");
    expect(
      (w.get("#par-reservas\\.minutos_para_pagar").element as HTMLInputElement)
        .placeholder,
    ).toBe("30");
  });

  it("solo envía lo que cambió; vacío vuelve al de la plataforma", async () => {
    const w = montar();
    await w.get("#par-reservas\\.minutos_para_pagar").setValue("45");
    await w.get("#par-recordatorios\\.segundo_horas").setValue("");
    await w.get("form").trigger("submit");

    expect(w.emitted("guardar")?.[0]).toEqual([
      {
        "reservas.minutos_para_pagar": 45,
        "recordatorios.segundo_horas": null,
      },
    ]);
  });

  it("con opciones fijas ofrece solo esos valores", async () => {
    const w = mount(ParametrosEditor, {
      props: {
        modo: "negocio",
        parametros: [
          {
            clave: "facturacion.iva_porcentaje",
            grupo: "Facturación",
            etiqueta: "Tasa de IVA de las facturas",
            ayuda: "",
            tipo: "entero",
            minimo: 8,
            maximo: 16,
            unidad: "%",
            opciones: [16, 8],
            valor: null,
            plataforma: 16,
          },
        ],
      },
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: "es",
            messages: { es: { parametrosConfig } },
          }),
        ],
      },
    });
    const opciones = w
      .findAll("#par-facturacion\\.iva_porcentaje option")
      .map((o) => o.text());
    expect(opciones.slice(1)).toEqual(["16 %", "8 %"]);

    await w.get("#par-facturacion\\.iva_porcentaje").setValue("8");
    await w.get("form").trigger("submit");
    expect(w.emitted("guardar")?.[0]).toEqual([
      { "facturacion.iva_porcentaje": 8 },
    ]);
  });
});
