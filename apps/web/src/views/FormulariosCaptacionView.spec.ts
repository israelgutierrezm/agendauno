import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import FormulariosCaptacionView from "./FormulariosCaptacionView.vue";

/*
| Formularios de captación: cada tarjeta comparte o edita; con varios, un buscador; si
| la captación está apagada, se avisa; compartir abre el modal con el enlace, su QR y el
| código para insertarlo.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("qrcode", () => ({
  default: { toDataURL: vi.fn(async () => "data:image/png;base64,QR") },
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: () => true,
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

enableAutoUnmount(afterEach);

function formulario(id: string, nombre: string, extra: object = {}) {
  return {
    id,
    nombre,
    titulo: `Título de ${nombre}`,
    activo: true,
    predeterminado: false,
    prospectos: 0,
    campos: [],
    pedir_telefono: false,
    telefono_obligatorio: false,
    ...extra,
  };
}

function montar() {
  return mount(FormulariosCaptacionView, {
    global: {
      plugins: [i18n],
      stubs: {
        EncabezadoSeccion: { template: "<div />" },
        PanelLateral: { template: "<div><slot /></div>" },
        ModalDialogo: {
          props: ["abierto", "titulo"],
          template:
            '<div v-if="abierto" data-prueba="modal"><h2>{{ titulo }}</h2><slot /></div>',
        },
      },
    },
  });
}

function responder(lista: object[], activa = true): void {
  api.get.mockResolvedValue({
    data: { data: lista, meta: { captacion_activa: activa } },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("formularios de captación", () => {
  it("cada tarjeta comparte o edita, y compartir muestra el enlace, el QR y el código", async () => {
    responder([formulario("f1", "Pedir acceso")]);
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="editar-tarjeta"]').exists()).toBe(true);
    await w.get('[data-prueba="compartir-tarjeta"]').trigger("click");
    await flushPromises();
    const modal = w.get('[data-prueba="modal"]');
    expect(modal.text()).toContain("Compartir «Pedir acceso»");
    expect(modal.get('[data-prueba="enlace-formulario"]').text()).toContain(
      "/f/demo/f1",
    );
    expect(modal.find('img[alt="Código QR"]').exists()).toBe(true);
    expect(modal.get('[data-prueba="codigo-formulario"]').text()).toContain(
      "<iframe",
    );
  });

  it("con más de tres, busca por nombre o título", async () => {
    responder([
      formulario("f1", "Pedir acceso"),
      formulario("f2", "Clase muestra"),
      formulario("f3", "Evento de verano"),
      formulario("f4", "Taller"),
    ]);
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="buscar-formulario"] input').setValue("muestra");
    const tarjetas = w.findAll('[data-prueba="formulario-captacion-tarjeta"]');
    expect(tarjetas).toHaveLength(1);
    expect(tarjetas[0]!.text()).toContain("Clase muestra");
  });

  it("si la captación está apagada lo avisa", async () => {
    responder([formulario("f1", "Pedir acceso")], false);
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="captacion-apagada"]').text()).toContain(
      "La captación está apagada",
    );
  });
});
