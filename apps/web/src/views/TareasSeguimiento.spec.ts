import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import TareasView from "./TareasView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
// Completar y regresar a pendientes se confirman.
const confirmar = vi.hoisted(() => vi.fn(async () => true));
vi.mock("@/lib/confirmar", () => ({ confirmar }));

const seguimiento = {
  id: "t1",
  titulo: "Plan por vencer: Ana López",
  detalle:
    "Mensaje sugerido (edítalo antes de enviarlo): Hola Ana, tu plan vence el 15 de octubre. Si quieres seguir, te ayudamos a renovarlo.",
  estado: "pendiente",
  vence_en: "2026-10-02T06:00:00+00:00",
  persona: "Ana López",
  persona_id: "p1",
  celular: "+52 55 1234 5678",
  motivo: "plan_por_vencer",
  resultado: null,
  responsable: null,
  automatica: false,
  completada_en: null,
};

function montar(respuesta?: unknown) {
  api.get.mockResolvedValue(
    respuesta ?? {
      data: { data: [seguimiento], pendientes: 1, alcance: "equipo" },
    },
  );
  api.post.mockResolvedValue({ data: { data: {} } });
  return mount(TareasView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, operacion } },
        }),
      ],
      stubs: {
        EncabezadoSeccion: true,
        RouterLink: { props: ["to"], template: "<a><slot /></a>" },
        PanelLateral: {
          props: ["abierto", "titulo"],
          template: '<div v-if="abierto"><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

beforeEach(() => vi.clearAllMocks());

describe("seguimiento en tareas", () => {
  it("sin celular propio, escribe por WhatsApp a su responsable y lo dice", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          {
            ...seguimiento,
            persona: "Sofía Ríos",
            celular: null,
            contacto: { nombre: "Laura Ríos", celular: "5511112222" },
            detalle:
              "Mensaje sugerido (edítalo antes de enviarlo): Hola Laura, el plan de Sofía vence el 15 de octubre. Si quieren seguir, les ayudamos a renovarlo.\n\nNo tiene celular propio: escríbele a Laura Ríos, su responsable.",
          },
        ],
        pendientes: 1,
        alcance: "equipo",
      },
    });
    api.post.mockResolvedValue({ data: { data: {} } });
    const w = mount(TareasView, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: "es",
            missingWarn: false,
            fallbackWarn: false,
            messages: { es: { ...esMX, operacion } },
          }),
        ],
        stubs: {
          EncabezadoSeccion: true,
          RouterLink: { props: ["to"], template: "<a><slot /></a>" },
          PanelLateral: true,
        },
      },
    });
    await flushPromises();

    const wa = w.get('a[target="_blank"]').attributes("href") ?? "";
    expect(wa).toMatch(/^https:\/\/wa\.me\/\d*5511112222\?text=/);
    expect(decodeURIComponent(wa.split("text=")[1])).toBe(
      "Hola Laura, el plan de Sofía vence el 15 de octubre. Si quieren seguir, les ayudamos a renovarlo.",
    );
    expect(w.get('[data-prueba="contacto-responsable"]').text()).toBe(
      "Se le escribe a Laura Ríos, su responsable",
    );
  });

  it("ofrece escribirle por WhatsApp con el mensaje sugerido y copiarlo", async () => {
    const escribir = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText: escribir } });
    const w = montar();
    await flushPromises();

    const wa = w.get('a[target="_blank"]').attributes("href") ?? "";
    expect(wa).toMatch(/^https:\/\/wa\.me\/525512345678\?text=/);
    expect(decodeURIComponent(wa.split("text=")[1])).toBe(
      "Hola Ana, tu plan vence el 15 de octubre. Si quieres seguir, te ayudamos a renovarlo.",
    );

    await w
      .findAll("button")
      .find((b) => b.text() === "Copiar mensaje")!
      .trigger("click");
    await flushPromises();
    expect(escribir).toHaveBeenCalledWith(
      "Hola Ana, tu plan vence el 15 de octubre. Si quieres seguir, te ayudamos a renovarlo.",
    );
  });

  it("registra con qué terminó, con su nota, en lugar de solo completarla", async () => {
    const w = montar();
    await flushPromises();
    expect(w.findAll("button").some((b) => b.text() === "Completar")).toBe(
      false,
    );

    await w
      .findAll("button")
      .find((b) => b.text() === "Registrar resultado")!
      .trigger("click");
    await w.get('input[value="no_desea_contacto"]').setValue(true);
    expect(w.text()).toContain("Deja de recibir promociones");
    await w.get("#s-nota").setValue("Pidió no recibir mensajes");
    await w
      .findAll("button")
      .find((b) => b.text() === "Guardar resultado")!
      .trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/tareas/t1/resultado",
      { resultado: "no_desea_contacto", nota: "Pidió no recibir mensajes" },
    );
    expect(toast.exito).toHaveBeenCalledWith("Resultado guardado");
  });

  it("filtra solo los seguimientos", async () => {
    const w = montar();
    await flushPromises();

    await w.get('select[aria-label="Tipo"]').setValue("seguimiento");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/tareas", {
      params: {
        estado: "pendiente",
        page: 1,
        per_page: 20,
        tipo: "seguimiento",
      },
    });
  });
});

describe("la bandeja de tareas", () => {
  const tarea = (id: string, titulo: string, vence_en: string | null) => ({
    ...seguimiento,
    id,
    titulo,
    detalle: null,
    vence_en,
    motivo: null,
    persona: null,
    persona_id: null,
    responsable: "Ana",
  });
  const respuesta = (data: unknown[], total = data.length, ultima = 1) => ({
    data: {
      data,
      pendientes: total,
      alcance: "equipo",
      meta: {
        total,
        page: 1,
        per_page: 20,
        ultima_pagina: ultima,
        conteos: { vencidas: 1, hoy: 0, semana: 1, sin_fecha: 1 },
      },
    },
  });

  it("agrupa por cuándo vencen, dice a quién están asignadas y pagina", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-10-12T18:00:00Z"));
    const w = montar();
    api.get.mockResolvedValue(
      respuesta(
        [
          tarea("a", "Revisar el tubo 6", "2026-10-10T05:59:59+00:00"),
          tarea("b", "Publicar el horario", "2026-10-15T05:59:59+00:00"),
          tarea("c", "Preparar el showcase", null),
        ],
        45,
        3,
      ),
    );
    await w.get('[data-prueba="ver-completadas"]').trigger("click");
    await w.get('[data-prueba="ver-pendientes"]').trigger("click");
    await flushPromises();

    expect(w.findAll(".ta-grupo").map((g) => g.text())).toEqual([
      "Vencidas",
      "Próximos 7 días",
      "Sin fecha",
    ]);
    const cuando = w
      .findAll('[data-prueba="cuando-tarea"]')
      .map((c) => c.text());
    expect(cuando[0]).toMatch(/^Venció el \d+ oct/);
    expect(cuando[1]).toMatch(/^Vence el \d+ oct/);
    expect(cuando[2]).toBe("Sin fecha");
    expect(w.text()).toContain("Asignada a Ana");
    expect(w.text()).toContain("1–20 de 45");
    vi.useRealTimers();
  });

  it("un indicador de vencimiento filtra; otro clic lo quita", async () => {
    const w = montar(respuesta([tarea("a", "Revisar", null)]));
    await flushPromises();

    await w.get('[data-prueba="conteo-vencidas"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/tareas", {
      params: {
        estado: "pendiente",
        page: 1,
        per_page: 20,
        vencimiento: "vencidas",
      },
    });
    expect(
      w.get('[data-prueba="conteo-vencidas"]').attributes("aria-pressed"),
    ).toBe("true");

    await w.get('[data-prueba="conteo-vencidas"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/tareas", {
      params: { estado: "pendiente", page: 1, per_page: 20 },
    });
  });

  it("sin resultados con filtros, ofrece quitarlos", async () => {
    const w = montar();
    api.get.mockResolvedValue(respuesta([], 0));
    await w.get('[data-prueba="tareas-desde"]').setValue("2026-10-01");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/tareas", {
      params: {
        estado: "pendiente",
        page: 1,
        per_page: 20,
        desde: "2026-10-01",
      },
    });
    expect(w.text()).toContain("Ninguna tarea coincide");

    await w
      .findAll("button")
      .find((b) => b.text() === "Quitar filtros")!
      .trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/tareas", {
      params: { estado: "pendiente", page: 1, per_page: 20 },
    });
  });

  it("marcar una tarea la completa al confirmar; un seguimiento pide su resultado", async () => {
    const w = montar();
    api.get.mockResolvedValue(
      respuesta([tarea("a", "Revisar el tubo", null), seguimiento]),
    );
    await w.get('[data-prueba="ver-completadas"]').trigger("click");
    await w.get('[data-prueba="ver-pendientes"]').trigger("click");
    await flushPromises();

    const marcas = w.findAll('[data-prueba="marcar-tarea"]');
    expect(marcas[0]!.classes()).toContain("ta-check-completar");
    // Si no confirma, no cambia nada.
    confirmar.mockResolvedValueOnce(false);
    await marcas[0]!.trigger("click");
    await flushPromises();
    expect(api.post).not.toHaveBeenCalled();

    await marcas[0]!.trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenLastCalledWith(
      "¿Marcar «Revisar el tubo» como completada?",
      { aceptar: "Sí, completar", tono: "exito" },
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/tareas/a/completar",
      {},
    );
    expect(toast.exito).toHaveBeenCalledWith("Tarea completada");

    // El seguimiento no pide confirmación: su panel de resultado ya lo es.
    confirmar.mockClear();
    await marcas[1]!.trigger("click");
    expect(confirmar).not.toHaveBeenCalled();
    expect(w.text()).toContain("Guardar resultado");
  });

  it("una completada se regresa a pendientes al confirmar", async () => {
    const w = montar();
    api.get.mockResolvedValue(
      respuesta([
        {
          ...tarea("a", "Revisar el tubo", null),
          estado: "completada",
          completada_en: "2026-10-01T15:00:00+00:00",
        },
      ]),
    );
    await w.get('[data-prueba="ver-completadas"]').trigger("click");
    await flushPromises();

    const marca = w.get('[data-prueba="marcar-tarea"]');
    expect(marca.classes()).toContain("ta-check-regresar");
    expect(marca.attributes("title")).toBe("Regresar a pendientes");
    await marca.trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenLastCalledWith(
      "¿Regresar «Revisar el tubo» a pendientes?",
      { aceptar: "Sí, regresar", tono: "aviso" },
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/tareas/a/reabrir",
      {},
    );
  });
});
