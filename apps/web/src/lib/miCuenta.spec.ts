import { beforeEach, describe, expect, it, vi } from "vitest";
import { reactive } from "vue";

import { identidadDeSesion, reiniciarMiCuenta, useMiCuenta } from "./miCuenta";

/*
 * El estado del portal es de UNA sesión: al cambiar de cuenta o de negocio no queda
 * nada de la anterior (ni si la carga nueva falla) y lo que llegue tarde de la
 * sesión anterior se descarta. Datos sintéticos.
 */

const sesion = reactive<{ slug: string | null; bearer: string | null }>({
  slug: "demo",
  bearer: "token-ana",
});
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "No se pudo cargar.",
}));

function perfil(oferta: string) {
  return {
    derechos: [],
    reservas: [
      {
        id: `r-${oferta}`,
        sesion_id: "s1",
        estado: "confirmada",
        oferta,
        sucursal: "Roma",
        inicia_en: "2030-01-10T15:00:00Z",
        zona_horaria: "America/Mexico_City",
        oferta_expira_en: null,
        orden_id: null,
      },
    ],
    politica_cancelacion: null,
  };
}

/** Respuestas por ruta para una sesión; `/mi/perfil` puede fallar o esperar. */
function responder(
  perfilDe: () => Promise<unknown>,
): (url: string) => Promise<unknown> {
  return (url: string) => {
    if (url.endsWith("/mi/perfil")) {
      return perfilDe().then((d) => ({ data: { data: d } }));
    }
    if (url.endsWith("/mi/formularios")) {
      return Promise.resolve({
        data: { data: { persona_id: `p-${sesion.bearer}`, formularios: [] } },
      });
    }
    return Promise.resolve({ data: { data: [] } });
  };
}

const ofertas = (c: ReturnType<typeof useMiCuenta>) =>
  c.reservas.value.map((r) => r.oferta);

describe("portal del alumno: estado por sesión", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    reiniciarMiCuenta();
    sesion.slug = "demo";
    sesion.bearer = "token-ana";
  });

  it("si falla la carga de la nueva sesión, no quedan reservas de la anterior", async () => {
    api.get.mockImplementation(
      responder(() => Promise.resolve(perfil("Pole de Ana"))),
    );
    const ana = useMiCuenta();
    await ana.asegurar();
    expect(ofertas(ana)).toEqual(["Pole de Ana"]);

    // Entra otra persona en el mismo equipo y su carga falla.
    sesion.bearer = "token-beto";
    api.get.mockImplementation(
      responder(() => Promise.reject(new Error("red"))),
    );
    const beto = useMiCuenta();
    // Ya al montar (antes de cargar) no hay nada de Ana.
    expect(ofertas(beto)).toEqual([]);
    await beto.asegurar();

    expect(ofertas(beto)).toEqual([]);
    // Lo que sí cargó es de Beto, nunca de Ana.
    expect(beto.personaId.value).toBe("p-token-beto");
    expect(beto.error.value).toBe("No se pudo cargar.");
  });

  it("una respuesta que llega tarde de la sesión anterior se descarta", async () => {
    let soltarAna: (d: unknown) => void = () => {};
    api.get.mockImplementation(
      responder(() => new Promise((r) => (soltarAna = r))),
    );
    const ana = useMiCuenta();
    const cargaDeAna = ana.asegurar();

    // Cambia de negocio antes de que responda la carga de Ana.
    sesion.slug = "otro-negocio";
    sesion.bearer = "token-beto";
    reiniciarMiCuenta();
    api.get.mockImplementation(
      responder(() => Promise.resolve(perfil("Corte de Beto"))),
    );
    const beto = useMiCuenta();
    await beto.asegurar();
    expect(ofertas(beto)).toEqual(["Corte de Beto"]);

    soltarAna(perfil("Pole de Ana"));
    await cargaDeAna;

    expect(ofertas(beto)).toEqual(["Corte de Beto"]);
  });

  it("al salir se vacía todo", async () => {
    api.get.mockImplementation(
      responder(() => Promise.resolve(perfil("Pole de Ana"))),
    );
    const ana = useMiCuenta();
    await ana.asegurar();

    reiniciarMiCuenta(); // lo que hace App.vue al cambiar la identidad
    expect(ofertas(ana)).toEqual([]);
    expect(identidadDeSesion("demo", null)).toBeNull();
  });
});
