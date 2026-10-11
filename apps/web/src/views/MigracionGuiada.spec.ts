import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ImportarInstructoresView from "./ImportarInstructoresView.vue";
import ImportarSaldosView from "./ImportarSaldosView.vue";

const api = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
const confirmar = vi.hoisted(() => vi.fn(async () => true));
vi.mock("@/lib/confirmar", () => ({ confirmar }));

const global = {
  plugins: [i18n],
  stubs: { EncabezadoSeccion: true, RouterLink: true, ZonaArchivo: true },
};
const csv = new File(["email,plan\n"], "carga.csv", { type: "text/csv" });

function elegirArchivo(w: ReturnType<typeof mount>): void {
  w.findComponent({ name: "ZonaArchivo" }).vm.$emit("archivo", csv);
}

function formulario(llamada: number): FormData {
  return api.post.mock.calls[llamada]![1] as FormData;
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("migración guiada (C07)", () => {
  it("los saldos de apertura se revisan con su corte y su evidencia y se cargan solo los listos", async () => {
    api.post
      .mockResolvedValueOnce({
        data: {
          data: {
            resumen: {
              total: 3,
              validas: 2,
              invalidas: 1,
              existentes: 0,
              conflictos: 1,
              rechazadas: 0,
            },
            filas: [
              {
                fila: 1,
                datos: {
                  email: "ana@correo.mx",
                  plan: "Pack",
                  creditos: "4.5",
                  vence: "2026-10-20",
                },
                errores: [],
                estado: "valida",
              },
              {
                fila: 2,
                datos: {
                  email: "caro@correo.mx",
                  plan: "Pack",
                  creditos: "3",
                  vence: "2026-10-30",
                },
                errores: [
                  "Ya tiene «Pack» vigente aquí: revísalo para no duplicar su saldo.",
                ],
                estado: "conflicto",
              },
            ],
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            cargados: 2,
            resumen: { total: 3, validas: 2, invalidas: 1 },
            filas: [],
          },
        },
      });
    const w = mount(ImportarSaldosView, { global });

    await w.get("#saldos-corte").setValue("2026-09-30");
    await w
      .get("#saldos-evidencia")
      .setValue("Reporte del sistema anterior al 30/09");
    elegirArchivo(w);
    await flushPromises();

    expect(api.post.mock.calls[0]![0]).toBe(
      "/api/v1/app/demo/importaciones/saldos/preview",
    );
    expect(formulario(0).get("corte")).toBe("2026-09-30");
    expect(formulario(0).get("evidencia")).toBe(
      "Reporte del sistema anterior al 30/09",
    );
    const estados = w
      .findAll('[data-prueba="estado-fila"]')
      .map((e) => e.text());
    expect(estados).toEqual(["Lista", "Conflicto"]);

    await w.get('[data-prueba="cargar-listas"]').trigger("click");
    await flushPromises();

    expect(confirmar).toHaveBeenCalledWith(
      expect.stringContaining("sin ventas ni pagos"),
    );
    expect(api.post.mock.calls[1]![0]).toBe(
      "/api/v1/app/demo/importaciones/saldos",
    );
    expect(formulario(1).get("parcial")).toBe("1");
    expect(w.text()).toContain("2 saldos de apertura cargados");
  });

  it("los instructores se importan sin invitar, o se invitan solo al confirmarlo", async () => {
    const preview = {
      data: {
        data: {
          resumen: { total: 2, validas: 2, invalidas: 0, existentes: 0 },
          filas: [
            {
              fila: 1,
              datos: { nombre: "Ana", email: "ana@correo.mx" },
              errores: [],
              estado: "valida",
            },
            {
              fila: 2,
              datos: { nombre: "Beto", email: "beto@correo.mx" },
              errores: [],
              estado: "valida",
            },
          ],
        },
      },
    };
    api.post.mockResolvedValueOnce(preview).mockResolvedValueOnce({
      data: {
        data: {
          creados: 2,
          invitados: 0,
          resumen: { total: 2, validas: 2, invalidas: 0 },
          filas: [],
        },
      },
    });
    const w = mount(ImportarInstructoresView, { global });
    elegirArchivo(w);
    await flushPromises();

    await w.get('[data-prueba="importar-sin-invitar"]').trigger("click");
    await flushPromises();
    expect(confirmar).not.toHaveBeenCalled();
    expect(formulario(1).get("invitar")).toBe("0");
    expect(w.get('[data-prueba="invitaciones"]').text()).toContain(
      "Quedaron sin invitar",
    );

    // Otra carga, ahora invitando: se confirma antes de mandar los correos.
    api.post.mockReset();
    api.post.mockResolvedValueOnce(preview).mockResolvedValueOnce({
      data: {
        data: {
          creados: 2,
          invitados: 2,
          resumen: { total: 2, validas: 2, invalidas: 0 },
          filas: [],
        },
      },
    });
    const w2 = mount(ImportarInstructoresView, { global });
    elegirArchivo(w2);
    await flushPromises();
    await w2.get('[data-prueba="importar-e-invitar"]').trigger("click");
    await flushPromises();

    expect(confirmar).toHaveBeenCalledWith(
      expect.stringContaining("invitación a 2 instructores"),
    );
    expect(formulario(1).get("invitar")).toBe("1");
    expect(w2.get('[data-prueba="invitaciones"]').text()).toBe(
      "Se enviaron 2 invitaciones.",
    );
  });
});
