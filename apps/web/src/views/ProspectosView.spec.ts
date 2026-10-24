import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import BarraListado from "@/components/BarraListado.vue";
import { i18n } from "@/i18n";
import ProspectosView from "./ProspectosView.vue";

/*
| Prospectos (ADR 0138): el responsable se elige con un buscador entre el equipo que
| atiende prospectos y se cambia cuando se quiera; escribirle por WhatsApp (con su
| botón) queda en su historia; la historia distingue cada cosa con su ícono; los que
| nadie atendió a tiempo se marcan y se filtran.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => true,
    zonaHoraria: "America/Mexico_City",
    usuario: { ulid: "u-dueno" },
  }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
const confirmar = vi.hoisted(() => vi.fn());
vi.mock("@/lib/confirmar", () => ({ confirmar }));
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
  useRoute: () => ({ query: {} }),
  // Sin router, los filtros viven en memoria (lib/filtrosEnUrl).
  useRouter: () => undefined,
  // El alta pregunta antes de descartar lo capturado (lib/cambiosPendientes).
  onBeforeRouteLeave: vi.fn(),
}));

const prospecto = {
  id: "p1",
  nombre: "Andrea Campos",
  email: "andrea@correo.mx",
  telefono: "5531204414",
  estado: "en_seguimiento",
  origen: "manual",
  fuente: null,
  formulario: null,
  interes: {
    sucursal: null,
    actividad: null,
    sesion: null,
    producto: null,
  },
  responsable: { id: "u-admin", nombre: "Administración" },
  proximo_seguimiento_en: null,
  primer_contacto_en: null,
  sin_atender_desde: "2026-10-09T15:00:00+00:00",
  motivo_cierre: null,
  comentario_cierre: null,
  persona: null,
  convertido_en: null,
  creado_en: "2026-10-07T16:00:00+00:00",
  respuestas: [],
  eventos: [
    {
      tipo: "sin_atender",
      detalle: { responsable: "Administración", horas: 24 },
      autor: null,
      en: "2026-10-09T15:00:00+00:00",
    },
    {
      tipo: "nota",
      detalle: { texto: "Le mandé el horario." },
      autor: "Administración",
      en: "2026-10-07T17:00:00+00:00",
    },
    {
      tipo: "asignado",
      detalle: { responsable: "Administración", anterior: "Fernanda Ruiz" },
      autor: "Constantino",
      en: "2026-10-07T16:30:00+00:00",
    },
  ],
};

function montar(paginas: Record<string, number> = {}) {
  api.get.mockImplementation((url: string) => {
    if (url.endsWith("/prospectos/responsables")) {
      return Promise.resolve({
        data: {
          data: [
            { id: "u-dueno", nombre: "Constantino", email: "c@correo.mx" },
            { id: "u-admin", nombre: "Administración", email: "a@correo.mx" },
            { id: "u-rec", nombre: "Fernanda Ruiz", email: "f@correo.mx" },
          ],
        },
      });
    }
    if (url.endsWith("/prospectos/p1")) {
      return Promise.resolve({ data: { data: prospecto } });
    }
    return Promise.resolve({
      data: {
        data: [prospecto],
        meta: {
          ...paginas,
          resumen: {
            nuevos: 0,
            seguimientos_vencidos: 0,
            abiertos: 1,
            sin_atender: 1,
          },
        },
      },
    });
  });
  api.put.mockResolvedValue({ data: { data: prospecto } });
  api.post.mockResolvedValue({ data: { data: prospecto } });
  return mount(ProspectosView, {
    global: {
      plugins: [i18n],
      stubs: {
        EncabezadoSeccion: true,
        PanelLateral: {
          props: ["abierto", "titulo"],
          template:
            '<div v-if="abierto"><button type="button" data-prueba="cerrar-panel" @click="$emit(\'cerrar\')" /><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

async function abrirDetalle(w: ReturnType<typeof montar>) {
  await flushPromises();
  await w.get("tr.prospecto-fila").trigger("click");
  await flushPromises();
}

beforeEach(() => vi.clearAllMocks());

describe("prospectos: filtros como en Miembros y por páginas", () => {
  it("filtra con la barra (también de un toque) y vuelve a la primera página", async () => {
    const w = montar();
    await flushPromises();

    // Por omisión, los abiertos.
    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/prospectos",
      expect.objectContaining({
        params: expect.objectContaining({ estado: "abiertos", page: 1 }),
      }),
    );
    const barra = w.findComponent(BarraListado);
    barra.vm.$emit("cambioFiltro", "origen", "manual");
    barra.vm.$emit("cambioFiltro", "seguimiento", "1");
    barra.vm.$emit("cambioFiltro", "periodo", "7");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/prospectos",
      expect.objectContaining({
        params: expect.objectContaining({
          origen: "manual",
          seguimiento: "vencido",
          periodo: "7",
          page: 1,
        }),
      }),
    );
    expect(barra.props("valores")).toMatchObject({
      origen: "manual",
      seguimiento: "1",
      periodo: "7",
    });

    barra.vm.$emit("limpiar");
    await flushPromises();
    expect(barra.props("valores")).toMatchObject({ origen: "", periodo: "" });
  });

  it("con más de una página, se pasa a la siguiente", async () => {
    const w = montar({ total: 60, ultima_pagina: 3, page: 1, per_page: 25 });
    await flushPromises();

    await w
      .findAll("nav button")
      .find((b) => b.text() === "2")!
      .trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/prospectos",
      expect.objectContaining({
        params: expect.objectContaining({ page: 2, per_page: 25 }),
      }),
    );
  });
});

describe("prospectos: responsable, contacto e historia", () => {
  it("marca y filtra los que nadie atendió a tiempo", async () => {
    const w = montar();
    await flushPromises();

    // La tarjeta de «Sin atender» filtra la lista (propuesta del dueño, 2026-10-20).
    expect(w.get('[data-prueba="indicador-sin-atender"]').text()).toContain(
      "Sin atender",
    );
    expect(w.find('[data-prueba="fila-sin-atender"]').exists()).toBe(true);
    await w
      .get('[data-prueba="indicador-sin-atender"] button')
      .trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/prospectos",
      expect.objectContaining({
        params: expect.objectContaining({ atencion: "sin_atender" }),
      }),
    );
  });

  it("busca a alguien del equipo para pasárselo; quien ya lo tiene no aparece", async () => {
    const w = montar();
    await abrirDetalle(w);

    const buscar = w.get('[data-prueba="buscar-responsable"]');
    await buscar.trigger("focus");
    await buscar.setValue("fer");
    const opciones = w.findAll('[data-prueba="opciones-responsable"] button');
    expect(opciones.map((o) => o.text())).toEqual([
      expect.stringContaining("Fernanda Ruiz"),
    ]);
    await opciones[0].trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/prospectos/p1/responsable",
      { responsable_id: "u-rec" },
    );
  });

  it("si la nota no se guarda, lo escrito se queda para reintentar", async () => {
    const w = montar();
    await abrirDetalle(w);
    api.post.mockRejectedValueOnce("Sin conexión");

    await w.get("#p-nota").setValue("Llamar el lunes");
    await w.get("#p-nota").element.form!.dispatchEvent(new Event("submit"));
    await flushPromises();
    expect(toast.error).toHaveBeenCalledWith("Sin conexión");
    expect((w.get("#p-nota").element as HTMLTextAreaElement).value).toBe(
      "Llamar el lunes",
    );

    await w.get("#p-nota").element.form!.dispatchEvent(new Event("submit"));
    await flushPromises();
    expect((w.get("#p-nota").element as HTMLTextAreaElement).value).toBe("");
  });

  it("cerrar el detalle con una nota sin guardar pregunta antes de descartarla", async () => {
    const w = montar();
    await abrirDetalle(w);
    await w.get("#p-nota").setValue("Llamar el lunes");

    confirmar.mockResolvedValueOnce(false);
    await w.get('[data-prueba="cerrar-panel"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalledTimes(1);
    expect(w.find('[data-prueba="detalle-prospecto"]').exists()).toBe(true);

    confirmar.mockResolvedValueOnce(true);
    await w.get('[data-prueba="cerrar-panel"]').trigger("click");
    await flushPromises();
    expect(w.find('[data-prueba="detalle-prospecto"]').exists()).toBe(false);
  });

  it("escribirle por WhatsApp (botón verde) queda en su historia", async () => {
    const w = montar();
    await abrirDetalle(w);

    const wa = w.get('[data-prueba="whatsapp"]');
    expect(wa.attributes("href")).toBe("https://wa.me/5531204414");
    await wa.trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/prospectos/p1/contactos",
      { canal: "whatsapp" },
    );
  });

  it("el detalle dice de qué campaña llegó, o que no tiene atribución (ADR 0142)", async () => {
    const w = montar();
    await abrirDetalle(w);
    expect(w.get('[data-prueba="campana-prospecto"]').text()).toBe(
      "Sin atribución de campaña",
    );

    Object.assign(prospecto, {
      campana: {
        fuente: "instagram",
        medio: "social",
        campana: "octubre",
        referente: null,
        primer_origen: { fuente: "google", campana: "busqueda" },
      },
    });
    const conCampana = montar();
    await abrirDetalle(conCampana);
    expect(conCampana.get('[data-prueba="campana-prospecto"]').text()).toBe(
      "Campaña: octubre · instagram / social",
    );
    expect(conCampana.text()).toContain(
      "La primera vez llegó por busqueda · google",
    );
    delete (prospecto as { campana?: unknown }).campana;
  });

  it("la historia distingue cada cosa: sin atender, nota y cambio de responsable", async () => {
    const w = montar();
    await abrirDetalle(w);

    const eventos = w.findAll('[data-prueba="historial-prospecto"] li');
    expect(eventos.map((e) => e.attributes("data-tipo"))).toEqual([
      "sin_atender",
      "nota",
      "asignado",
    ]);
    expect(eventos[0].text()).toContain(
      "Sin atender: Administración no lo atendió en 24 h",
    );
    expect(eventos[1].find(".pr-nota").text()).toBe("Le mandé el horario.");
    expect(eventos[2].text()).toContain(
      "Pasó de Fernanda Ruiz a Administración",
    );
    expect(w.get('[data-prueba="aviso-sin-atender"]').text()).toContain(
      "Sin atender desde",
    );
  });
});
