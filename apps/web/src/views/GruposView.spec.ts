import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import BuscarPersona from "@/components/BuscarPersona.vue";
import GruposView from "./GruposView.vue";

/*
| Grupos: un grupo no tiene tope de lugares, así que sus inscritos se piden por páginas,
| se cuentan todos y, si son muchos, se buscan.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => toast,
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => true,
    zonaHoraria: "America/Mexico_City",
  }),
}));

enableAutoUnmount(afterEach);

const inscrito = (n: number) => ({
  id: `i${n}`,
  persona: `Persona ${n}`,
  persona_id: `p${n}`,
  activo: n !== 3,
});

beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers();
  api.get.mockImplementation(
    (url: string, opciones?: { params?: { page?: number; q?: string } }) => {
      if (url.endsWith("/grupos")) {
        return Promise.resolve({
          data: {
            data: [
              {
                id: "g1",
                nombre: "Natación niños",
                oferta: "Natación",
                inscritos: 25,
                activo: true,
              },
            ],
          },
        });
      }
      if (url.endsWith("/grupos/g1/inscripciones")) {
        const p = opciones?.params ?? {};
        const lista = p.q
          ? [inscrito(7)]
          : p.page === 2
            ? Array.from({ length: 5 }, (_, i) => inscrito(21 + i))
            : Array.from({ length: 20 }, (_, i) => inscrito(1 + i));
        return Promise.resolve({
          data: { data: lista, meta: { total: p.q ? 1 : 25 } },
        });
      }
      return Promise.resolve({ data: { data: [] } });
    },
  );
});
afterEach(() => {
  vi.useRealTimers();
});

describe("inscritos de un grupo", () => {
  it("se cuentan todos, van por páginas y se buscan", async () => {
    const w = mount(GruposView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    await w.get('[data-prueba="grupo"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/grupos/g1/inscripciones",
      { params: { page: 1, per_page: 20 } },
    );
    expect(w.get('[data-prueba="total-inscritos"]').text()).toBe(
      "25 inscritos",
    );
    expect(w.findAll(".gr-inscritos > li")).toHaveLength(20);
    // Quien se dio de baja se distingue.
    expect(w.findAll(".gr-inscritos > li")[2]!.text()).toContain("Baja");

    await w.get('[data-prueba="mas-inscritos"]').trigger("click");
    await flushPromises();
    expect(w.findAll(".gr-inscritos > li")).toHaveLength(25);
    expect(w.find('[data-prueba="mas-inscritos"]').exists()).toBe(false);

    await w.get('[data-prueba="buscar-inscrito"]').setValue("persona 7");
    vi.advanceTimersByTime(300);
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/grupos/g1/inscripciones",
      { params: { page: 1, per_page: 20, q: "persona 7" } },
    );
    expect(w.findAll(".gr-inscritos > li")).toHaveLength(1);
  });
});

async function montarElegido() {
  const w = mount(GruposView, {
    global: { plugins: [i18n], stubs: { teleport: true } },
  });
  await flushPromises();
  await w.get('[data-prueba="grupo"]').trigger("click");
  await flushPromises();
  return w;
}

describe("entradas y salidas de un grupo", () => {
  it("al inscribir dice cómo quedó cada fecha y por qué no se reservó", async () => {
    api.post.mockResolvedValue({
      data: {
        data: {
          reservadas: 1,
          en_espera: 1,
          no_reservadas: 1,
          sesiones: [
            {
              sesion_id: "s1",
              inicia_en: "2026-10-02T00:00:00Z",
              estado: "reservada",
              motivo: null,
            },
            {
              sesion_id: "s2",
              inicia_en: "2026-10-03T00:00:00Z",
              estado: "en_espera",
              motivo: "Sin lugares: quedó en lista de espera.",
            },
            {
              sesion_id: "s3",
              inicia_en: "2026-10-04T00:00:00Z",
              estado: "no_reservada",
              motivo: "Su plan no tiene saldo o no cubre esta clase.",
            },
          ],
        },
      },
    });
    const w = await montarElegido();
    w.findComponent(BuscarPersona).vm.$emit("update:modelValue", "p9");
    await flushPromises();
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(w.get('[data-prueba="resultado-inscripcion"]').text()).toContain(
      "Inscrito: 1 clase reservada · 1 en lista de espera · 1 sin reservar.",
    );
    await w.get('[data-prueba="ver-fechas"]').trigger("click");
    const filas = w.findAll('[data-prueba="fechas-inscripcion"] tbody tr');
    expect(filas).toHaveLength(3);
    expect(filas[2]!.text()).toContain("Sin reservar");
    expect(filas[2]!.text()).toContain("Su plan no tiene saldo");
  });

  it("da de baja desde una fecha o cambia de grupo", async () => {
    api.post.mockResolvedValue({
      data: { data: {}, meta: { canceladas: 4, no_canceladas: [] } },
    });
    const w = await montarElegido();
    await w.findAll('[data-prueba="dar-de-baja"]')[0]!.trigger("click");
    await w.get('[data-prueba="salida-desde"]').setValue("2026-10-05");
    await w.get('[data-prueba="confirmar-salida"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/grupos/g1/inscripciones/i1/baja",
      { desde: "2026-10-05" },
    );
    expect(toast.exito).toHaveBeenCalledWith(
      "Persona 1 dejó el grupo desde el 5 oct; se cancelaron 4 reservas.",
    );
  });

  it("cambiar de grupo muestra antes lo que pasaría y un cambio parcial se confirma (R08)", async () => {
    const base = api.get.getMockImplementation()!;
    api.get.mockImplementation((url: string, opciones?: unknown) => {
      if (url.endsWith("/grupos")) {
        return Promise.resolve({
          data: {
            data: [
              {
                id: "g1",
                nombre: "Natación niños",
                oferta: "Natación",
                inscritos: 25,
                activo: true,
              },
              {
                id: "g2",
                nombre: "Natación tarde",
                oferta: "Natación",
                inscritos: 3,
                activo: true,
              },
            ],
          },
        });
      }
      if (url.endsWith("/inscripciones/i1/cambio")) {
        return Promise.resolve({
          data: {
            data: {
              baja: { canceladas: 7, no_canceladas: [] },
              alta: {
                reservadas: 6,
                en_espera: 0,
                no_reservadas: 1,
                sesiones: [
                  {
                    sesion_id: "s8",
                    inicia_en: "2026-10-09T00:00:00Z",
                    estado: "no_reservada",
                    motivo: "Su plan no tiene saldo o no cubre esta clase.",
                  },
                ],
              },
              completo: false,
            },
          },
        });
      }
      return base(url, opciones);
    });
    api.post.mockResolvedValue({
      data: { data: {}, meta: { baja: { canceladas: 7, no_canceladas: [] } } },
    });
    const w = await montarElegido();
    await w.findAll('[data-prueba="dar-de-baja"]')[0]!.trigger("click");
    await w.get('[data-prueba="salida-destino"]').setValue("g2");
    await flushPromises();

    const previa = w.get('[data-prueba="vista-previa-cambio"]').text();
    expect(previa).toContain("Se cancelan 7 reservas de este grupo");
    expect(previa).toContain("Su plan no tiene saldo");
    // Sin confirmar el parcial no se puede hacer el cambio.
    expect(
      w.get('[data-prueba="confirmar-salida"]').attributes("disabled"),
    ).toBeDefined();
    await w.get('[data-prueba="aceptar-parcial"]').setValue(true);
    await w.get('[data-prueba="confirmar-salida"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/grupos/g1/inscripciones/i1/cambiar",
      expect.objectContaining({ grupo_id: "g2", parcial: true }),
    );
  });

  it("cerrar el grupo pide la fecha y avisa a cuántos dio de baja", async () => {
    api.post.mockResolvedValue({
      data: {
        data: {},
        meta: { bajas: 24, canceladas: 90, no_canceladas: [] },
      },
    });
    const w = await montarElegido();
    await w.get('[data-prueba="cerrar-grupo"]').trigger("click");
    await w.get('[data-prueba="cierre-desde"]').setValue("2026-10-10");
    await w.get('[data-prueba="confirmar-cierre"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/grupos/g1/cerrar", {
      desde: "2026-10-10",
    });
    expect(toast.exito).toHaveBeenCalledWith(
      "Se cerró «Natación niños» y se dio de baja a 24 inscritos.",
    );
  });
});
