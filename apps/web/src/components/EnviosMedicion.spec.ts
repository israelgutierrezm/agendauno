import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import { i18n } from "@/i18n";
import type { EnvioMedicion } from "@/lib/medicion";
import EnviosMedicion from "./EnviosMedicion.vue";

/*
| Los últimos envíos a GA4 y Meta (ADR 0143). En Integraciones del negocio llevan su
| título; en el superadmin van integrados en la pestaña «Últimos envíos» (sin repetirlo)
| y con la paginación dentro de la tarjeta.
*/

const envio: EnvioMedicion = {
  id: "e1",
  proveedor: "ga4",
  evento: "negocio_creado",
  nombre: "negocio_creado",
  negocio: "demo",
  ocurrido_en: "2026-10-12T15:00:00Z",
  estado: "enviado",
  motivo: null,
  intentos: 1,
  proximo_intento_en: null,
  enviado_en: "2026-10-12T15:00:05Z",
  ultimo_error: null,
};

function montar(props: { integrado?: boolean } = {}, pie?: string) {
  return mount(EnviosMedicion, {
    props: { envios: [envio], ...props },
    slots: pie ? { pie } : {},
    global: { plugins: [i18n] },
  });
}

describe("últimos envíos de medición", () => {
  it("lleva su título si va sola (Integraciones del negocio)", () => {
    const w = montar();

    expect(w.get("h3").text()).toBe("Últimos envíos");
    expect(w.findAll('[data-prueba="envio-medicion"]')).toHaveLength(1);
  });

  it("integrada no repite el título y pinta el pie dentro de la tarjeta", () => {
    const w = montar(
      { integrado: true },
      '<nav data-prueba="pie-envios">páginas</nav>',
    );

    expect(w.find("h3").exists()).toBe(false);
    expect(w.text()).not.toContain("Últimos envíos");
    expect(
      w
        .get('[data-prueba="envios-medicion"] [data-prueba="pie-envios"]')
        .text(),
    ).toBe("páginas");
  });
});
