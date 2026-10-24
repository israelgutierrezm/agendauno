import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import DemoAgendable from "./DemoAgendable.vue";

/*
| Agendar una demo desde la landing (ADR 0193 y 0204), en tres pasos como el formulario
| de la competencia: su negocio, el día y la hora, y sus datos. Cada paso pide lo suyo
| antes de seguir; el envío lleva el sello de la página y lo que contó del negocio; si
| el servidor rechaza algo, se vuelve al paso donde se corrige.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
const erroresDelServidor = vi.hoisted(() => ({
  valor: {} as Record<string, string>,
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Error del servidor",
  erroresPorCampo: () => erroresDelServidor.valor,
}));
vi.mock("@/lib/recaptcha", () => ({
  tokenRecaptcha: () => Promise.resolve("captcha"),
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));

const HORARIOS = {
  activas: true,
  minutos: 15,
  zona: "America/Mexico_City",
  whatsapp: true,
  dias: [
    { fecha: "2026-10-01", horas: ["10:00", "10:15"] },
    { fecha: "2026-10-02", horas: ["12:00"] },
  ],
  sello: "sello-cifrado",
};
const global = { plugins: [i18n], stubs: { RouterLink: true } };

function respuestas(horarios: unknown) {
  api.get.mockImplementation((url: string) =>
    Promise.resolve(
      url === "/api/v1/demos"
        ? { data: { data: horarios } }
        : {
            data: { data: { versiones: { aviso_privacidad: { version: 3 } } } },
          },
    ),
  );
}

function montar(props: Record<string, unknown> = {}) {
  return mount(DemoAgendable, {
    props: { producto: "agendauno", ...props },
    global,
  });
}

async function llenarNegocio(w: ReturnType<typeof montar>): Promise<void> {
  await w.get('[data-prueba="demo-giro"]').setValue("pilates");
  await w.get('[data-prueba="demo-negocio"]').setValue("Fluo Pilates");
  await w.get('[data-prueba="demo-operando-si"]').setValue(true);
  await w.get('[data-prueba="demo-tamano"]').setValue("51-100");
  await w.get('[data-prueba="demo-sucursales"]').setValue("2-4");
  await w.get('[data-objetivo="cobros"]').trigger("change");
  await w.get('[data-objetivo="retencion"]').trigger("change");
}

beforeEach(() => {
  vi.clearAllMocks();
  erroresDelServidor.valor = {};
});

describe("DemoAgendable", () => {
  it("apagada, no se muestra y lo avisa", async () => {
    respuestas({ ...HORARIOS, activas: false, dias: [] });
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="demo-agendable"]').exists()).toBe(false);
    expect(w.emitted("apagadas")).toHaveLength(1);
  });

  it("cada paso pide lo suyo antes de seguir", async () => {
    respuestas(HORARIOS);
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="demo-siguiente"]').trigger("submit");
    expect(w.text()).toContain("Paso 1 de 3");
    // Giro, negocio, si opera, sucursales y objetivos, cada uno junto a su campo.
    expect(w.findAll(".tu-error-campo")).toHaveLength(5);
    expect(w.text()).toContain("Completa lo marcado para seguir.");

    await llenarNegocio(w);
    // Hasta dos objetivos: el tercero queda deshabilitado.
    expect(
      (w.get('[data-objetivo="app"]').element as HTMLInputElement).disabled,
    ).toBe(true);
    await w.get("form").trigger("submit");
    expect(w.text()).toContain("Paso 2 de 3");

    // Sin hora no sigue.
    await w.get("form").trigger("submit");
    expect(w.text()).toContain("Paso 2 de 3");
  });

  it("agenda con el sello, lo que contó del negocio y su WhatsApp con lada", async () => {
    respuestas(HORARIOS);
    api.post.mockResolvedValue({ data: { data: { id: "d1" } } });
    const w = montar();
    await flushPromises();

    await llenarNegocio(w);
    await w.get("form").trigger("submit");
    await w.get('[data-dia="2026-10-02"]').trigger("click");
    expect(w.findAll("[data-hora]").map((b) => b.text())).toEqual(["12:00"]);
    await w.get('[data-hora="12:00"]').trigger("click");
    await w.get("form").trigger("submit");
    expect(w.text()).toContain("Paso 3 de 3");

    await w.get('[data-prueba="demo-nombre"]').setValue("Ana López");
    await w.get('[data-prueba="demo-correo"]').setValue("ana@fluo.mx");
    await w.get('[data-prueba="demo-telefono"]').setValue("5512345678");
    await w.get('[data-prueba="demo-aviso"]').setValue(true);
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/demos",
      expect.objectContaining({
        producto: "agendauno",
        fecha: "2026-10-02",
        hora: "12:00",
        giro: "pilates",
        negocio: "Fluo Pilates",
        operando: true,
        tamano: "51-100",
        apertura: null,
        sucursales: "2-4",
        objetivos: ["cobros", "retencion"],
        nombre: "Ana López",
        correo: "ana@fluo.mx",
        telefono: "+52 5512345678",
        // La confirmación por WhatsApp no viene marcada.
        acepta_whatsapp: false,
        acepta_aviso: true,
        aviso_version: 3,
        sello: "sello-cifrado",
        recaptcha_token: "captcha",
      }),
    );
    expect(w.find('[data-prueba="demo-listo"]').exists()).toBe(true);
    expect(w.emitted("agendada")).toHaveLength(1);
  });

  it("si el horario ya no está, vuelve al paso de la hora con los horarios al día", async () => {
    respuestas(HORARIOS);
    api.post.mockRejectedValue(new Error("422"));
    erroresDelServidor.valor = {
      hora: "Ese horario ya no está disponible: elige otro.",
    };
    const w = montar();
    await flushPromises();
    await llenarNegocio(w);
    await w.get("form").trigger("submit");
    await w.get('[data-hora="10:00"]').trigger("click");
    await w.get("form").trigger("submit");
    await w.get('[data-prueba="demo-nombre"]').setValue("Ana López");
    await w.get('[data-prueba="demo-correo"]').setValue("ana@fluo.mx");
    await w.get('[data-prueba="demo-telefono"]').setValue("5512345678");
    await w.get('[data-prueba="demo-aviso"]').setValue(true);
    const pedidos = api.get.mock.calls.filter(
      ([url]) => url === "/api/v1/demos",
    ).length;
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(w.text()).toContain("Paso 2 de 3");
    expect(w.text()).toContain("Ese horario ya no está disponible");
    expect(
      api.get.mock.calls.filter(([url]) => url === "/api/v1/demos").length,
    ).toBe(pedidos + 1);
  });

  it("llega con el giro de la página de donde viene, si es de su producto", async () => {
    respuestas(HORARIOS);
    const w = montar({ giroInicial: "yoga" });
    await flushPromises();
    expect(
      (w.get('[data-prueba="demo-giro"]').element as HTMLSelectElement).value,
    ).toBe("yoga");

    // Un giro de citas no aplica a una demo de clases.
    const otro = montar({ giroInicial: "barberia" });
    await flushPromises();
    expect(
      (otro.get('[data-prueba="demo-giro"]').element as HTMLSelectElement)
        .value,
    ).toBe("");
  });
});
