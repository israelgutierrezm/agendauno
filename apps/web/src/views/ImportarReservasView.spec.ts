import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ImportarReservasView from "./ImportarReservasView.vue";

/*
| Importar reservas futuras de clases (ADR 0176): no se revisa sin elegir si los saldos
| ya las descontaron; la vista previa dice qué queda pendiente y por qué; se importa
| por partes con la confirmación de esa vista, diciendo los créditos que se reintegran.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    zonaHoraria: "America/Mexico_City",
  }),
}));
const confirmar = vi.hoisted(() =>
  vi.fn<(mensaje: string) => Promise<boolean>>(async () => true),
);
vi.mock("@/lib/confirmar", () => ({ confirmar }));

enableAutoUnmount(afterEach);

const fila = (extra: Record<string, unknown> = {}) => ({
  fila: 2,
  datos: {
    email: "ana@correo.mx",
    clase: "Pole Nivel 1",
    sucursal: "Roma Norte",
    fecha: "2026-11-03",
    hora: "19:00",
  },
  estado: "valida",
  errores: [],
  avisos: [],
  alumno: "Ana López",
  clase: "Pole Nivel 1",
  inicia_en: "2026-11-04T01:00:00Z",
  queda: "confirmada",
  ...extra,
});
const resumen = {
  total: 2,
  validas: 1,
  existentes: 0,
  pendientes: 1,
  invalidas: 0,
  en_espera: 0,
  creditos_reintegrados: 1000,
};

function montar() {
  return mount(ImportarReservasView, {
    global: {
      plugins: [i18n],
      stubs: {
        EncabezadoSeccion: { template: "<div />" },
        RouterLink: { template: "<a><slot /></a>" },
        ZonaArchivo: {
          emits: ["archivo"],
          template: "<button data-prueba='zona' @click='elegir' />",
          methods: {
            elegir(this: { $emit: (e: string, f: File) => void }) {
              this.$emit("archivo", new File(["x"], "reservas.csv"));
            },
          },
        },
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("importar reservas", () => {
  it("no revisa sin elegir si los saldos ya las descontaron", async () => {
    const w = montar();
    await w.get('[data-prueba="zona"]').trigger("click");
    await flushPromises();

    expect(api.post).not.toHaveBeenCalled();
    expect(w.text()).toContain(
      "Indica la fecha de corte y si los saldos ya descontaron estas reservas.",
    );
  });

  it("muestra lo pendiente y su motivo, e importa las listas con la confirmación", async () => {
    api.post
      .mockResolvedValueOnce({
        data: {
          data: {
            ok: false,
            confirmacion: "c1",
            resumen,
            filas: [
              fila({
                avisos: [
                  "Su crédito ya se había descontado en el otro sistema: se reintegra y se aparta para esta clase.",
                ],
              }),
              fila({
                fila: 3,
                estado: "pendiente",
                alumno: "Beto Ruiz",
                queda: null,
                errores: ["La clase ya está llena aquí."],
              }),
            ],
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            ok: false,
            importadas: 1,
            resumen,
            filas: [],
          },
        },
      });
    const w = montar();
    await w.get('[data-prueba="descontadas-si"]').setValue(true);
    await w.get('[data-prueba="zona"]').trigger("click");
    await flushPromises();

    const previa = api.post.mock.calls[0]!;
    expect(previa[0]).toBe("/api/v1/app/demo/importaciones/reservas/preview");
    expect((previa[1] as FormData).get("ya_descontadas")).toBe("1");
    expect(w.get('[data-prueba="resumen-reservas"]').text()).toContain(
      "Pendientes (sin plan con saldo o clase llena): 1",
    );
    expect(w.text()).toContain("Pendiente");
    expect(w.text()).toContain("La clase ya está llena aquí.");
    // Con algo por resolver, todo junto no se puede: solo las listas.
    expect(
      w.get('[data-prueba="importar-reservas"]').attributes("disabled"),
    ).toBeDefined();

    await w.get('[data-prueba="importar-listas"]').trigger("click");
    await flushPromises();
    expect(confirmar.mock.calls[0]![0]).toContain(
      "se reintegrará 1 crédito como apertura",
    );
    const final = api.post.mock.calls[1]!;
    expect(final[0]).toBe("/api/v1/app/demo/importaciones/reservas");
    expect((final[1] as FormData).get("confirmacion")).toBe("c1");
    expect((final[1] as FormData).get("parcial")).toBe("1");
    expect(w.text()).toContain("1 reserva importada");
  });
});
