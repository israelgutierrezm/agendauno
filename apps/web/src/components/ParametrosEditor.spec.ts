import { flushPromises, mount } from "@vue/test-utils";
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

function montar(opciones: { attachTo?: HTMLElement } = {}) {
  return mount(ParametrosEditor, {
    props: { parametros, modo: "negocio" },
    ...opciones,
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

// El texto de una categoría en la lista de poco ancho.
function textoEnLista(w: ReturnType<typeof montar>, clave: string): string {
  return w
    .get(`[data-prueba="categoria-parametros"] option[value="${clave}"]`)
    .text();
}

describe("editor de parámetros", () => {
  it("una opción con nombre se muestra con él (la moneda del negocio)", () => {
    const w = mount(ParametrosEditor, {
      props: {
        modo: "negocio",
        parametros: [
          {
            clave: "negocio.moneda",
            grupo: "Moneda",
            etiqueta: "Moneda del negocio",
            ayuda: "",
            tipo: "entero",
            minimo: 1,
            maximo: 999,
            unidad: "",
            opciones: [484, 840],
            etiquetas: { 484: "MXN · Peso mexicano", 840: "USD · Dólar" },
            valor: 840,
            plataforma: 484,
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
    const opciones = w.findAll("option").map((o) => o.text());
    expect(opciones).toContain("MXN · Peso mexicano");
    expect(opciones).toContain("USD · Dólar");
    expect(w.text()).toContain("MXN · Peso mexicano");
  });

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
    // Otra categoría: lo escrito en la primera se conserva y se envía junto.
    await w.get('[data-categoria="recordatorios"]').trigger("click");
    await w.get("#par-recordatorios\\.segundo_horas").setValue("");
    expect(w.get('[data-prueba="cambios-sin-guardar"]').text()).toContain("2");
    await w.get("form").trigger("submit");

    expect(w.emitted("guardar")?.[0]).toEqual([
      {
        "reservas.minutos_para_pagar": 45,
        "recordatorios.segundo_horas": null,
      },
    ]);
  });
});

describe("una categoría a la vez (sin scroll largo)", () => {
  it("muestra solo la categoría elegida; la primera al llegar", async () => {
    const w = montar();
    expect(w.find("#par-reservas\\.minutos_para_pagar").exists()).toBe(true);
    expect(w.find("#par-recordatorios\\.segundo_horas").exists()).toBe(false);
    expect(
      w
        .get('[data-categoria="reservas-y-lista-de-espera"]')
        .attributes("aria-pressed"),
    ).toBe("true");

    await w.get('[data-categoria="recordatorios"]').trigger("click");
    expect(w.find("#par-reservas\\.minutos_para_pagar").exists()).toBe(false);
    expect(w.find("#par-recordatorios\\.segundo_horas").exists()).toBe(true);
  });

  it("con poco ancho, la categoría se elige en una lista", async () => {
    const w = montar();
    await w
      .get('[data-prueba="categoria-parametros"]')
      .setValue("recordatorios");
    expect(w.find("#par-recordatorios\\.segundo_horas").exists()).toBe(true);
    expect(w.find("#par-reservas\\.minutos_para_pagar").exists()).toBe(false);
  });

  it("el índice marca la categoría con cambios sin guardar", async () => {
    const w = montar();
    await w.get("#par-reservas\\.minutos_para_pagar").setValue("45");
    expect(
      w.get('[data-categoria="reservas-y-lista-de-espera"] .tu-badge').text(),
    ).toBe("1");
    expect(w.find('[data-categoria="recordatorios"] .tu-badge').exists()).toBe(
      false,
    );
  });

  it("el buscador filtra por nombre en todas las categorías", async () => {
    const w = montar();
    await w.get('[data-prueba="buscar-parametro"]').setValue("segundo");
    expect(w.find("#par-recordatorios\\.segundo_horas").exists()).toBe(true);
    expect(w.find("#par-reservas\\.minutos_para_pagar").exists()).toBe(false);
    expect(w.find('[data-prueba="resultados-parametros"]').exists()).toBe(true);

    await w.get('[data-prueba="buscar-parametro"]').setValue("zzz");
    expect(w.find('[data-prueba="sin-resultados-parametros"]').exists()).toBe(
      true,
    );
    expect(w.findAll("fieldset")).toHaveLength(0);

    // Elegir una categoría deja la búsqueda.
    await w
      .get('[data-categoria="reservas-y-lista-de-espera"]')
      .trigger("click");
    expect(
      (w.get('[data-prueba="buscar-parametro"]').element as HTMLInputElement)
        .value,
    ).toBe("");
    expect(w.find("#par-reservas\\.minutos_para_pagar").exists()).toBe(true);
  });

  it("con una sola categoría no hay índice ni buscador", () => {
    const w = mount(ParametrosEditor, {
      props: { parametros: parametros.slice(0, 1), modo: "negocio" },
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
    expect(w.find('[data-prueba="categorias-parametros"]').exists()).toBe(
      false,
    );
    expect(w.find('[data-prueba="buscar-parametro"]').exists()).toBe(false);
    expect(w.find("#par-reservas\\.minutos_para_pagar").exists()).toBe(true);
  });
});

describe("editor de parámetros: opciones", () => {
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

describe("errores junto a su campo (ADR 0166)", () => {
  it("lo que el servidor rechazó se dice debajo del campo, no solo arriba", async () => {
    const w = mount(ParametrosEditor, {
      props: {
        parametros,
        modo: "negocio",
        // La forma de `meta.errors` del API: la clave del parámetro sola.
        errores: {
          "reservas.minutos_para_pagar": "Debe ser al menos 5.",
        },
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
    expect(
      w.get('[data-prueba="error-reservas.minutos_para_pagar"]').text(),
    ).toBe("Debe ser al menos 5.");
    expect(
      w
        .get('[id="par-reservas.minutos_para_pagar"]')
        .attributes("aria-invalid"),
    ).toBe("true");
    await w.get('[data-categoria="recordatorios"]').trigger("click");
    expect(
      w
        .get('[id="par-recordatorios.segundo_horas"]')
        .attributes("aria-invalid"),
    ).toBeUndefined();
  });

  it("un error en otra categoría la abre para verlo junto a su campo", async () => {
    const w = montar();
    expect(w.find("#par-recordatorios\\.segundo_horas").exists()).toBe(false);
    await w.setProps({
      errores: { "recordatorios.segundo_horas": "Máximo 48." },
    });
    expect(
      w.get('[data-prueba="error-recordatorios.segundo_horas"]').text(),
    ).toBe("Máximo 48.");
  });

  it("acepta también `valores.<clave>` (compatibilidad)", async () => {
    const w = montar();
    await w.setProps({
      errores: { "valores.reservas.minutos_para_pagar": "Mínimo 5." },
    });
    expect(
      w.get('[data-prueba="error-reservas.minutos_para_pagar"]').text(),
    ).toBe("Mínimo 5.");
  });

  it("lo rechazado por el API marca su categoría en el índice", async () => {
    const w = montar();
    await w.setProps({
      errores: {
        "reservas.minutos_para_pagar": "Debe ser al menos 5.",
        "recordatorios.segundo_horas": "Máximo 48.",
      },
    });
    // Se queda en la categoría del primero (ya a la vista) y marca las dos.
    expect(
      w
        .get('[data-categoria="reservas-y-lista-de-espera"]')
        .attributes("aria-pressed"),
    ).toBe("true");
    expect(
      w.get('[data-prueba="con-errores-reservas-y-lista-de-espera"]').text(),
    ).toBe("con errores");
    expect(w.get('[data-prueba="con-errores-recordatorios"]').text()).toBe(
      "con errores",
    );
  });
});

describe("revisión antes de guardar (solo se monta una categoría)", () => {
  it("Enter en «Buscar un parámetro» no envía el formulario", async () => {
    const w = montar();
    await w.get("#par-reservas\\.minutos_para_pagar").setValue("45");
    const buscador = w.get('[data-prueba="buscar-parametro"]');
    await buscador.setValue("segundo");
    const enter = new KeyboardEvent("keydown", {
      key: "Enter",
      bubbles: true,
      cancelable: true,
    });
    buscador.element.dispatchEvent(enter);
    await flushPromises();
    // Evitada la tecla, el navegador no pulsa «Guardar» (envío implícito).
    expect(enter.defaultPrevented).toBe(true);
    expect(w.emitted("guardar")).toBeUndefined();
  });

  it("lo que no cuadra en otra categoría no se envía: la abre, lo dice junto al campo y le da el foco", async () => {
    const w = montar({ attachTo: document.body });
    await w.get("#par-reservas\\.minutos_para_pagar").setValue("2");
    await w.get('[data-categoria="recordatorios"]').trigger("click");
    expect(w.find("#par-reservas\\.minutos_para_pagar").exists()).toBe(false);

    await w.get("form").trigger("submit");
    await flushPromises();
    expect(w.emitted("guardar")).toBeUndefined();
    expect(
      w
        .get('[data-categoria="reservas-y-lista-de-espera"]')
        .attributes("aria-pressed"),
    ).toBe("true");
    const campo = w.get("#par-reservas\\.minutos_para_pagar");
    expect(campo.attributes("aria-invalid")).toBe("true");
    expect(
      w.get('[data-prueba="error-reservas.minutos_para_pagar"]').text(),
    ).toBe("Debe estar entre 5 min y 1440 min.");
    expect(document.activeElement).toBe(campo.element);

    // Al corregirlo, el error se va y ya se guarda.
    await campo.setValue("45");
    expect(
      w.find('[data-prueba="error-reservas.minutos_para_pagar"]').exists(),
    ).toBe(false);
    expect(campo.attributes("aria-invalid")).toBeUndefined();
    await w.get("form").trigger("submit");
    expect(w.emitted("guardar")?.[0]).toEqual([
      { "reservas.minutos_para_pagar": 45 },
    ]);
    w.unmount();
  });

  it("un número con decimales no se envía", async () => {
    const w = montar();
    await w.get("#par-reservas\\.minutos_para_pagar").setValue("7.5");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(w.emitted("guardar")).toBeUndefined();
    expect(
      w.get('[data-prueba="error-reservas.minutos_para_pagar"]').text(),
    ).toBe("Debe ser un número entero.");
  });

  it("el índice y la lista marcan cada categoría con errores", async () => {
    const w = montar();
    await w.get("#par-reservas\\.minutos_para_pagar").setValue("2");
    await w.get('[data-categoria="recordatorios"]').trigger("click");
    await w.get("#par-recordatorios\\.segundo_horas").setValue("99");
    expect(w.find('[data-prueba^="con-errores-"]').exists()).toBe(false);

    await w.get("form").trigger("submit");
    await flushPromises();
    // Abre la del primero (en el orden del catálogo) y marca las dos, para que el
    // segundo error no quede escondido.
    expect(w.find("#par-reservas\\.minutos_para_pagar").exists()).toBe(true);
    expect(
      w.find('[data-prueba="con-errores-reservas-y-lista-de-espera"]').exists(),
    ).toBe(true);
    expect(w.get('[data-prueba="con-errores-recordatorios"]').text()).toBe(
      "con errores",
    );
    // Con poco ancho, en la lista, como texto.
    expect(textoEnLista(w, "recordatorios")).toBe(
      "Recordatorios · con errores · 1 cambio sin guardar",
    );

    // Corregir el de la segunda le quita la marca; la primera la conserva.
    await w.get('[data-categoria="recordatorios"]').trigger("click");
    expect(
      w.get('[data-prueba="error-recordatorios.segundo_horas"]').text(),
    ).toBe("Debe estar entre 0 h y 48 h.");
    await w.get("#par-recordatorios\\.segundo_horas").setValue("24");
    expect(w.find('[data-prueba="con-errores-recordatorios"]').exists()).toBe(
      false,
    );
    expect(
      w.find('[data-prueba="con-errores-reservas-y-lista-de-espera"]').exists(),
    ).toBe(true);
    expect(textoEnLista(w, "recordatorios")).toBe(
      "Recordatorios · 1 cambio sin guardar",
    );
  });
});
