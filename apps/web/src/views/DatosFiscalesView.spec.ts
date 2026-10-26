import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import {
  REGIMENES_FISCALES,
  regimenCorresponde,
  tipoPersonaDeRfc,
} from "@/lib/catalogosSat";
import DatosFiscalesView from "./DatosFiscalesView.vue";

/*
| Datos fiscales: el régimen se elige del catálogo del SAT (clave y descripción, con
| buscador), el error del API va junto a su campo y arriba se ve como lista lo que
| falta para timbrar.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Error",
  erroresPorCampo: (e: {
    response?: { data?: { meta?: { errors?: Record<string, string[]> } } };
  }) =>
    Object.fromEntries(
      Object.entries(e.response?.data?.meta?.errors ?? {}).map(([c, m]) => [
        c,
        m[0] ?? "",
      ]),
    ),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

const guardados = {
  razon_social: "Estudio Luna SA de CV",
  rfc: "EKU9003173C9",
  regimen_fiscal: "601",
  codigo_postal: "06600",
  sellos_cargados: false,
};

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/timbres")
        ? { data: { disponibles: 0 } }
        : { data: null, meta: { disponible: true } },
    }),
  );
  api.put.mockResolvedValue({ data: { data: guardados } });
});

function montar() {
  return mount(DatosFiscalesView, {
    attachTo: document.body,
    global: {
      plugins: [i18n],
      stubs: {
        EncabezadoSeccion: true,
        ZonaArchivo: true,
        RouterLink: { template: "<a><slot /></a>" },
      },
    },
  });
}

const estados = (w: ReturnType<typeof montar>) =>
  Object.fromEntries(
    w
      .findAll('[data-prueba^="requisito-"]')
      .map((r) => [
        r.attributes("data-prueba")!.replace("requisito-", ""),
        r.get('[data-prueba="estado-requisito"]').text(),
      ]),
  );

describe("datos fiscales", () => {
  it("el régimen se elige del catálogo del SAT, con clave, descripción y buscador", async () => {
    const w = montar();
    await flushPromises();
    await w.get("#rfc").setValue("EKU9003173C9");
    const campo = w.get('[data-prueba="regimen"]');

    // Con un RFC de persona moral, primero los regímenes de personas morales.
    await campo.trigger("click");
    expect(w.findAll(".sb-grupo").map((g) => g.text())).toEqual([
      "Para tu RFC",
      "Otros regímenes",
    ]);
    const opciones = w.findAll('[role="option"]');
    expect(opciones).toHaveLength(REGIMENES_FISCALES.length);
    expect(opciones[0].text()).toContain(
      "601 · General de Ley Personas Morales",
    );
    expect(opciones[0].text()).toContain("Persona moral");

    // Se encuentra por como lo conoce la gente.
    await campo.setValue("resico");
    const encontradas = w.findAll('[role="option"]');
    expect(encontradas.map((o) => o.attributes("data-valor"))).toEqual(["626"]);
    await encontradas[0].trigger("mousedown");
    expect((campo.element as HTMLInputElement).value).toBe(
      "626 · Régimen Simplificado de Confianza",
    );
    expect(w.find('[data-prueba="aviso-regimen"]').exists()).toBe(false);

    // Uno de personas físicas con RFC de moral: se avisa junto al campo.
    await campo.trigger("click");
    await campo.setValue("612");
    await w.get('[role="option"]').trigger("mousedown");
    expect(w.get('[data-prueba="aviso-regimen"]').text()).toContain(
      "tu RFC es de persona moral",
    );
    w.unmount();
  });

  it("arriba dice qué falta para timbrar y se actualiza al guardar", async () => {
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="requisitos"]').text()).toContain(
      "Te faltan 5 cosas para timbrar.",
    );
    expect(estados(w)).toEqual({
      razonRfc: "Falta",
      regimen: "Falta",
      codigoPostal: "Falta",
      sellos: "Falta",
      timbres: "Falta",
    });
    expect(w.get('[data-prueba="requisito-timbres"]').text()).toContain(
      "0 disponibles",
    );

    await w.get("#rs").setValue(guardados.razon_social);
    await w.get("#rfc").setValue(guardados.rfc);
    await w.get("#cp").setValue(guardados.codigo_postal);
    await w.get('[data-prueba="regimen"]').trigger("click");
    await w.get('[data-valor="601"]').trigger("mousedown");
    await w.get("#form-fiscales").trigger("submit");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/datos-fiscales", {
      razon_social: "Estudio Luna SA de CV",
      rfc: "EKU9003173C9",
      regimen_fiscal: "601",
      codigo_postal: "06600",
    });
    expect(toast.exito).toHaveBeenCalledWith(
      "Datos fiscales de «Estudio Luna SA de CV» guardados.",
    );
    expect(estados(w)).toMatchObject({
      razonRfc: "Listo",
      regimen: "Listo",
      codigoPostal: "Listo",
      sellos: "Falta",
    });
    expect(w.get('[data-prueba="requisitos"]').text()).toContain(
      "Te faltan 2 cosas para timbrar.",
    );
    w.unmount();
  });

  it("el error del API va junto a su campo y lo capturado se queda", async () => {
    api.put.mockRejectedValueOnce({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          meta: { errors: { rfc: ["El RFC no tiene un formato válido."] } },
        },
      },
    });
    const w = montar();
    await flushPromises();
    await w.get("#rfc").setValue("XYZ");
    await w.get("#form-fiscales").trigger("submit");
    await flushPromises();

    expect(w.get("#rfc-error").text()).toBe(
      "El RFC no tiene un formato válido.",
    );
    expect(w.get("#rfc").attributes("aria-invalid")).toBe("true");
    expect((w.get("#rfc").element as HTMLInputElement).value).toBe("XYZ");
    w.unmount();
  });

  it("el tipo de persona sale del RFC y decide qué régimen le corresponde", () => {
    expect(tipoPersonaDeRfc("EKU9003173C9")).toBe("moral");
    expect(tipoPersonaDeRfc("xaxx010101000")).toBe("fisica");
    expect(tipoPersonaDeRfc("XYZ")).toBeNull();
    expect(regimenCorresponde("626", "moral")).toBe(true);
    expect(regimenCorresponde("612", "moral")).toBe(false);
    expect(regimenCorresponde("601", null)).toBe(true);
  });
});

describe("alta en FacturAPI (ADR 0212)", () => {
  it("con el sello cargado dice lo que falta, enlaza la carta manifiesto y vuelve a revisar", async () => {
    const pendiente = {
      ...guardados,
      sellos_cargados: true,
      facturacion_lista: false,
      pendientes: ["Firma la carta manifiesto"],
      error: null,
      portal_manifiesto: "https://www.facturapi.io/manifiesto",
    };
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.endsWith("/timbres")
          ? { data: { disponibles: 0 } }
          : { data: pendiente, meta: { disponible: true } },
      }),
    );
    api.post.mockResolvedValueOnce({
      data: { data: { ...pendiente, facturacion_lista: true, pendientes: [] } },
    });
    const w = montar();
    await flushPromises();
    const alta = w.get('[data-prueba="alta-facturapi"]');
    expect(alta.text()).toContain("Firma la carta manifiesto");
    expect(alta.get("a").attributes("href")).toBe(
      "https://www.facturapi.io/manifiesto",
    );

    await w.get('[data-prueba="revisar-alta"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/datos-fiscales/revisar",
      {},
    );
    expect(w.get('[data-prueba="alta-facturapi"]').text()).toContain(
      "Tu negocio ya factura con su propio sello.",
    );
    w.unmount();
  });
});
