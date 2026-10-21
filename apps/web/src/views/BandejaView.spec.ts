import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { createMemoryHistory, createRouter } from "vue-router";

import bandejaMensajes from "@/i18n/locales/bandejaMensajes.es-MX";
import BandejaView from "./BandejaView.vue";

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (_e: unknown, porDefecto: string) => porDefecto,
}));
const confirmar = vi.hoisted(() => vi.fn(async () => true));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
const registro = vi.hoisted(() => ({ registrarWhatsApp: vi.fn() }));
vi.mock("@/lib/registroWhatsApp", () => registro);
const permisos = vi.hoisted(() => ({ lista: ["mensajes.atender"] }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    zonaHoraria: "America/Mexico_City",
    puede: (p: string) => permisos.lista.includes(p),
  }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

const conversacion = {
  id: "c1",
  canal: "whatsapp",
  contacto: "Lucía",
  telefono: "+5215511112222",
  persona: null,
  estado: "abierta",
  no_leidos: 2,
  ultimo_texto: "¿Tienen clase mañana?",
  ultimo_mensaje_en: "2026-10-01T12:00:00Z",
  puede_contestar: true,
  contestar_hasta: "2026-10-02T12:00:00Z",
};
const detalle = {
  ...conversacion,
  no_leidos: 0,
  mensajes: [
    {
      id: "m1",
      direccion: "entrante",
      tipo: "texto",
      texto: "¿Tienen clase mañana?",
      estado: "recibido",
      enviado_en: "2026-10-01T12:00:00Z",
      enviado_por: null,
    },
  ],
};
const canales = {
  whatsapp: {
    disponible: true,
    registro: { app_id: "app", config_id: "cfg", version: "v23.0" },
    conexion: null,
  },
  instagram: { disponible: true, conexion: null },
};

function responderGet(extra: Record<string, unknown> = {}) {
  api.get.mockImplementation(async (url: string) => {
    if (url.endsWith("/bandeja/conversaciones")) {
      return {
        data: {
          data: [conversacion],
          meta: { page: 1, ultima_pagina: 1, total: 1, per_page: 30 },
        },
      };
    }
    if (url.endsWith("/bandeja/conversaciones/c1")) {
      return { data: { data: { ...detalle, ...extra } } };
    }
    if (url.endsWith("/bandeja/canales")) {
      return { data: { data: canales } };
    }
    throw new Error(url);
  });
}

async function montar(ruta = "/bandeja") {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/bandeja", component: { template: "<div />" } },
      {
        path: "/miembros/:id",
        name: "ficha-miembro",
        component: { template: "<div />" },
      },
    ],
  });
  await router.push(ruta);
  await router.isReady();
  const w = mount(BandejaView, {
    global: {
      plugins: [
        router,
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { bandejaMensajes } },
          missingWarn: false,
          fallbackWarn: false,
        }),
      ],
      stubs: { EncabezadoSeccion: true, BuscarPersona: true },
    },
  });
  await flushPromises();
  return { w, router };
}

function boton(w: Awaited<ReturnType<typeof montar>>["w"], texto: string) {
  return w.findAll("button").find((b) => b.text() === texto);
}

describe("bandeja de WhatsApp e Instagram", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    permisos.lista = ["mensajes.atender"];
    responderGet();
  });

  it("lista las conversaciones, abre una y contesta", async () => {
    api.post.mockResolvedValue({
      data: {
        data: {
          id: "m2",
          direccion: "saliente",
          tipo: "texto",
          texto: "Sí, a las 7.",
          estado: "enviado",
          enviado_en: "2026-10-01T12:05:00Z",
          enviado_por: "Ana López",
        },
      },
    });
    const { w } = await montar();
    expect(w.find('[data-prueba="canales"]').exists()).toBe(false);
    const lista = w.find('[data-prueba="conversaciones"]');
    expect(lista.text()).toContain("Lucía");
    expect(lista.text()).toContain("2");

    await w.find(".bm-conversacion").trigger("click");
    await flushPromises();
    const hilo = w.find('[data-prueba="conversacion"]');
    expect(hilo.text()).toContain("¿Tienen clase mañana?");
    expect(hilo.text()).toContain("+5215511112222");

    await w.find("textarea").setValue("Sí, a las 7.");
    await w.find("form.bm-redactar").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/bandeja/conversaciones/c1/mensajes",
      { texto: "Sí, a las 7." },
    );
    expect(w.find('[data-prueba="conversacion"]').text()).toContain(
      "Ana López · Enviado",
    );
  });

  it("pasadas las 24 horas no deja escribir y explica por qué", async () => {
    responderGet({ puede_contestar: false });
    const { w } = await montar();
    await w.find(".bm-conversacion").trigger("click");
    await flushPromises();
    expect(w.find("textarea").exists()).toBe(false);
    expect(w.find('[data-prueba="fuera-de-plazo"]').text()).toBe(
      bandejaMensajes.fueraDePlazo,
    );
  });

  it("quien configura conecta WhatsApp con el registro de Meta", async () => {
    permisos.lista = ["mensajes.atender", "integraciones.configurar"];
    registro.registrarWhatsApp.mockResolvedValue({
      codigo: "abc",
      waba_id: "123456",
      phone_number_id: "7654321",
    });
    api.post.mockResolvedValue({ data: { data: {} } });
    const { w } = await montar();
    const fila = w.find('[data-prueba="canal-whatsapp"]');
    await fila.findAll("button")[0]!.trigger("click");
    await flushPromises();
    expect(registro.registrarWhatsApp).toHaveBeenCalledWith(
      canales.whatsapp.registro,
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/bandeja/canales/whatsapp",
      { codigo: "abc", waba_id: "123456", phone_number_id: "7654321" },
    );
    expect(toast.exito).toHaveBeenCalledWith(
      bandejaMensajes.canales.whatsappListo,
    );
  });

  it("a la vuelta de Instagram confirma el código y lo quita de la URL", async () => {
    permisos.lista = ["mensajes.atender", "integraciones.configurar"];
    api.post.mockResolvedValue({ data: { data: {} } });
    const { router } = await montar(
      "/bandeja?instagram=conectar&codigo=xyz&estado=st",
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/bandeja/canales/instagram/confirmar",
      { codigo: "xyz", estado: "st" },
    );
    expect(router.currentRoute.value.query).toEqual({});
    expect(toast.exito).toHaveBeenCalledWith(
      bandejaMensajes.canales.instagramListo,
    );
  });

  it("archivar la saca de las abiertas", async () => {
    api.put.mockResolvedValue({
      data: { data: { ...conversacion, estado: "archivada" } },
    });
    const { w } = await montar();
    await w.find(".bm-conversacion").trigger("click");
    await flushPromises();
    await boton(w, bandejaMensajes.archivar)!.trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/bandeja/conversaciones/c1",
      { estado: "archivada" },
    );
    expect(w.find('[data-prueba="conversacion"]').exists()).toBe(false);
  });
});
