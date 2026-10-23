import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ImportarCitasView from "./ImportarCitasView.vue";

/*
| Importar citas futuras: la vista previa pide las correspondencias de los nombres que
| no coinciden y, ya elegidas, revisa de nuevo con ellas; se importa con su
| confirmación. Si no se cargan los catálogos, se dice.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    zonaHoraria: "America/Mexico_City",
  }),
}));

enableAutoUnmount(afterEach);

const catalogos = {
  servicio: [{ id: "of1", nombre: "Corte" }],
  profesional: [{ id: "u1", nombre: "Luis" }],
  sucursal: [{ id: "s1", nombre: "Roma Norte" }],
  max_filas: 500,
};
const fila = (extra: Record<string, unknown> = {}) => ({
  fila: 2,
  datos: {
    cliente: "Beto",
    servicio: "Corte H",
    profesional: "Luis",
    sucursal: "Roma Norte",
    fecha: "2026-10-05",
    hora: "10:00",
  },
  errores: [],
  avisos: [],
  omitida: false,
  cliente: { nombre: "Beto", nueva: true },
  inicia_en: "2026-10-05T16:00:00Z",
  ...extra,
});

function montar() {
  return mount(ImportarCitasView, {
    global: {
      plugins: [i18n],
      stubs: {
        EncabezadoSeccion: { template: "<div><slot name='acciones' /></div>" },
        RouterLink: { template: "<a><slot /></a>" },
        ZonaArchivo: {
          emits: ["archivo"],
          template: "<button data-prueba='zona' @click='elegir' />",
          methods: {
            elegir(this: { $emit: (e: string, f: File) => void }) {
              this.$emit("archivo", new File(["x"], "citas.csv"));
            },
          },
        },
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue({ data: { data: catalogos } });
});

describe("importar citas", () => {
  it("pide la correspondencia del nombre distinto y vuelve a revisar con ella", async () => {
    api.post
      .mockResolvedValueOnce({
        data: {
          data: {
            ok: false,
            creados: 0,
            resumen: {
              total: 1,
              validas: 0,
              invalidas: 1,
              omitidas: 0,
              clientes_nuevos: 0,
            },
            faltantes: { servicio: ["Corte H"], profesional: [], sucursal: [] },
            filas: [
              fila({
                errores: [
                  "No encontramos el servicio «Corte H»: elige su correspondencia.",
                ],
                cliente: null,
              }),
            ],
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            ok: true,
            confirmacion: "c1",
            creados: 0,
            resumen: {
              total: 1,
              validas: 1,
              invalidas: 0,
              omitidas: 0,
              clientes_nuevos: 1,
            },
            faltantes: { servicio: [], profesional: [], sucursal: [] },
            filas: [fila()],
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            ok: true,
            creados: 1,
            resumen: {
              total: 1,
              validas: 1,
              invalidas: 0,
              omitidas: 0,
              clientes_nuevos: 1,
            },
            faltantes: { servicio: [], profesional: [], sucursal: [] },
            filas: [fila()],
          },
        },
      });
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="zona"]').trigger("click");
    await flushPromises();

    expect(w.get('[data-prueba="correspondencias-citas"]').text()).toContain(
      "«Corte H»",
    );
    expect(
      w.get('[data-prueba="importar-citas"]').attributes("disabled"),
    ).toBeDefined();

    await w.get('[data-prueba="corresponde-servicio"]').setValue("of1");
    await w.get('[data-prueba="revisar-citas"]').trigger("click");
    await flushPromises();
    const enviado = api.post.mock.calls[1]![1] as FormData;
    expect(JSON.parse(enviado.get("correspondencias") as string)).toEqual({
      servicio: { "Corte H": "of1" },
      profesional: {},
      sucursal: {},
    });
    expect(enviado.get("recordatorios")).toBe("0");

    await w.get('[data-prueba="importar-citas"]').trigger("click");
    await flushPromises();
    const final = api.post.mock.calls[2]![1] as FormData;
    expect(final.get("confirmacion")).toBe("c1");
    expect(w.get('[data-prueba="exito-citas"]').text()).toContain(
      "Se importó 1 cita",
    );
    expect(w.text()).toContain("No se le avisó nada a tus alumnos.");
  });

  it("antes de confirmar dice cuántas quedan por cobrar, cuánto y cuántas ya estaban pagadas (R14)", async () => {
    api.post.mockResolvedValueOnce({
      data: {
        data: {
          ok: true,
          confirmacion: "c1",
          creados: 0,
          resumen: {
            total: 3,
            validas: 3,
            invalidas: 0,
            omitidas: 0,
            clientes_nuevos: 2,
            por_cobrar: 2,
            por_cobrar_minor: 40000,
            pagadas_antes: 1,
            moneda: "MXN",
          },
          faltantes: { servicio: [], profesional: [], sucursal: [] },
          filas: [fila(), fila({ fila: 3 }), fila({ fila: 4 })],
        },
      },
    });
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="zona"]').trigger("click");
    await flushPromises();

    const aviso = w.get('[data-prueba="importar-por-cobrar"]').text();
    expect(aviso).toContain("2 citas quedarán por cobrar");
    expect(aviso).toContain("400.00");
    expect(aviso).toContain("1 ya estaba pagada en tu sistema anterior");
  });

  it("si no se cargan los catálogos lo dice y no deja subir el archivo", async () => {
    api.get.mockRejectedValueOnce(new Error("red"));
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="error-catalogos-citas"]').exists()).toBe(true);
  });
});
