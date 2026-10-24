import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import TarifasPlataforma from "./TarifasPlataforma.vue";

/*
| Tarifas del SaaS en el superadmin (ADR 0019 y 0107): una tarifa a la vez, en
| pestañas; las escaleras en columnas y el reparto de funciones plegado, para que la
| sección no baje varias pantallas. Publicar una no borra lo que se editaba en la otra.
*/

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
vi.mock("axios", () => ({ default: { create: () => http } }));
vi.mock("@/lib/api", () => ({ mensajeDeError: (e: unknown) => String(e) }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
const confirmar = vi.hoisted(() => vi.fn());
vi.mock("@/lib/confirmar", () => ({ confirmar }));

// Precios por profesionales, de 2 a 20 (en centavos).
function porProfesionales(base: number): Record<string, number> {
  const precios: Record<string, number> = {};
  for (let n = 2; n <= 20; n++) {
    precios[String(n)] = (base + n) * 100;
  }
  return precios;
}

const citas = {
  id: "01CITAS",
  modalidad: "citas",
  version: 4,
  vigente_desde: "2026-10-08T00:00:00-06:00",
  definicion: {
    moneda: "USD",
    dias_prueba: 14,
    iva_porcentaje: 16,
    iva_porcentaje_extranjero: 0,
    meses_anual: 10,
    niveles: {
      individual: { "1": 900 },
      premium: porProfesionales(20),
      pro: porProfesionales(30),
    },
    funciones: { equipo: "premium", facturacion: "pro" },
  },
};
const tarifas = {
  clases: {
    vigente: {
      id: "01CLASES",
      modalidad: "clases",
      version: 3,
      vigente_desde: "2026-10-08T00:00:00-06:00",
      definicion: {
        moneda: "USD",
        dias_prueba: 30,
        iva_porcentaje: 16,
        iva_porcentaje_extranjero: 0,
        bandas: [40, 60, 80, 100, 150, 200, 300, 500, 1000, null].map(
          (hasta, i) => ({ hasta, monto_minor: (21 + i * 9) * 100 }),
        ),
      },
    },
    historial: [],
  },
  citas: { vigente: citas, historial: [] },
};

function montar() {
  return mount(TarifasPlataforma, {
    props: { apiUrl: "http://api", token: "tk" },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  http.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/tipo-cambio")
          ? {
              banxico_configurado: false,
              ultimo: {
                valor: "17.2345",
                fecha: "2026-10-23",
                fuente: "manual",
              },
            }
          : tarifas,
      },
    }),
  );
});

describe("TarifasPlataforma", () => {
  it("muestra una tarifa a la vez, con su escalera en columnas", async () => {
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="tarifa-clases"]').exists()).toBe(true);
    expect(w.find('[data-prueba="tarifa-citas"]').exists()).toBe(false);
    // Diez escalones: dos columnas de cinco; el techo, al final, sin tope.
    const escalera = w.findAll('[data-prueba="tarifa-clases"] table');
    expect(escalera).toHaveLength(2);
    expect(escalera[0].findAll("tbody tr")).toHaveLength(5);
    expect(escalera[1].text()).toContain("Sin tope (techo)");
    // El tipo de cambio vale para las dos: se ve en cualquier pestaña.
    expect(w.get('[data-prueba="tipo-cambio"]').text()).toContain(
      "1 USD = 17.2345 MXN",
    );

    await w.get('[data-pestana="citas"]').trigger("click");
    expect(w.find('[data-prueba="tarifa-clases"]').exists()).toBe(false);
    // De 2 a 20 profesionales: tres columnas.
    expect(w.findAll('[data-prueba="tarifa-citas"] table')).toHaveLength(3);
    expect(w.find('input[aria-label="Premium 20"]').exists()).toBe(true);
    // Qué nivel abre cada función: plegado, con el reparto a la vista.
    const plegado = w.get('[data-prueba="tarifa-citas"] details');
    expect(plegado.attributes("open")).toBeUndefined();
    expect(plegado.get("summary").text()).toBe(
      "Qué nivel abre cada función · Premium 1 · Pro 1",
    );
    expect(plegado.find('[data-prueba="funciones-por-nivel"]').exists()).toBe(
      true,
    );
    expect(w.get('[data-prueba="tipo-cambio"]').exists()).toBe(true);
  });

  it("publicar una tarifa no borra lo que se editaba en la otra", async () => {
    confirmar.mockResolvedValue(true);
    http.post.mockResolvedValue({ data: { data: { ...citas, version: 5 } } });
    const w = montar();
    await flushPromises();

    await w
      .get('[data-prueba="tarifa-clases"] input[aria-label="Precio mensual"]')
      .setValue("25");
    await w.get('[data-pestana="citas"]').trigger("click");
    await w.get('[data-prueba="tarifa-citas"]').trigger("submit");
    await flushPromises();

    expect(http.post).toHaveBeenCalledWith(
      "/api/v1/plataforma/tarifas/citas",
      expect.objectContaining({ meses_anual: 10 }),
    );
    expect(toast.exito).toHaveBeenCalled();
    await w.get('[data-pestana="clases"]').trigger("click");
    const precio = w.get(
      '[data-prueba="tarifa-clases"] input[aria-label="Precio mensual"]',
    ).element as HTMLInputElement;
    expect(precio.value).toBe("25");
  });
});
