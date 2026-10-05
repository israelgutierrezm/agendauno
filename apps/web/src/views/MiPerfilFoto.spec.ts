import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { reactive } from "vue";

import esMX from "@/i18n/locales/es-MX";
import { miPerfil } from "@/i18n/locales/equipo.es-MX";
import zonaArchivo from "@/i18n/locales/zonaArchivo.es-MX";
import MiPerfilView from "./MiPerfilView.vue";

/**
 * La foto de Mi perfil se suelta en cualquier parte de la tarjeta del nombre (o en
 * su zona de carga) o se elige con un clic; tipo y peso se revisan antes de subirla
 * y lo que no se acepta se dice debajo de la zona. Datos sintéticos.
 */
const mocks = vi.hoisted(() => ({
  post: vi.fn(),
  error: vi.fn(),
  actualizarUsuario: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: { get: vi.fn(), post: mocks.post, put: vi.fn(), delete: vi.fn() },
  mensajeDeError: () => "Error",
}));
const sesion = reactive({
  slug: "demo",
  usuario: {
    rol: "instructor",
    nombre: "Ana Demo",
    nombre_pila: "Ana",
    primer_apellido: "Demo",
    email: "ana@example.test",
    foto_url: null,
    roles_disponibles: [{ clave: "instructor", faceta: "instructor" }],
  },
  actualizarUsuario: mocks.actualizarUsuario,
});
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: mocks.error }),
}));
vi.mock("@/lib/google", () => ({
  clientIdGoogle: () => undefined,
  renderizarBotonGoogle: vi.fn(),
}));

function montar() {
  return mount(MiPerfilView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...esMX, miPerfil, zonaArchivo } },
          missingWarn: false,
          fallbackWarn: false,
        }),
      ],
      stubs: { PanelApariencia: true, MiPrivacidad: true },
    },
  });
}

function soltar(archivo: File) {
  return { dataTransfer: { files: [archivo] } };
}

beforeEach(() => vi.clearAllMocks());

describe("foto de Mi perfil", () => {
  it("soltar una imagen en cualquier parte de la tarjeta la sube", async () => {
    mocks.post.mockResolvedValue({
      data: { data: { usuario: { ...sesion.usuario, foto_url: "/f.webp" } } },
    });
    const w = montar();
    const tarjeta = w.get('[data-prueba="identidad-perfil"]');
    const zona = w.get('[data-prueba="zona-archivo"]');
    expect(zona.text()).toContain("Arrastra tu foto aquí o elígela");
    expect(zona.text()).toContain(miPerfil.fotoFormatos);

    // Sobre el nombre: toda la tarjeta la recibe y la zona lo dice.
    const nombre = w.get(".mp-nombre");
    await nombre.trigger("dragenter");
    expect(tarjeta.classes()).toContain("mp-identidad-activa");
    expect(w.get('[data-prueba="zona-foto"]').classes()).toContain(
      "mp-foto-zona-activa",
    );
    expect(zona.text()).toContain(miPerfil.fotoSuelta);

    const foto = new File(["x"], "yo.png", { type: "image/png" });
    await nombre.trigger("drop", soltar(foto));
    await flushPromises();

    expect(tarjeta.classes()).not.toContain("mp-identidad-activa");
    expect(mocks.post).toHaveBeenCalledTimes(1);
    const [url, cuerpo] = mocks.post.mock.calls[0];
    expect(url).toBe("/api/v1/app/demo/yo/foto");
    expect((cuerpo as FormData).get("foto")).toBe(foto);
    expect(mocks.actualizarUsuario).toHaveBeenCalled();
    w.unmount();
  });

  it("soltarla en la zona de carga la sube una sola vez", async () => {
    mocks.post.mockResolvedValue({
      data: { data: { usuario: sesion.usuario } },
    });
    const w = montar();
    const foto = new File(["x"], "yo.webp", { type: "image/webp" });
    await w.get('[data-prueba="zona-archivo"]').trigger("drop", soltar(foto));
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledTimes(1);
    w.unmount();
  });

  it("un clic en la foto abre el selector de archivo", async () => {
    const w = montar();
    const entrada = w.get('input[type="file"]').element as HTMLInputElement;
    const clic = vi.spyOn(entrada, "click");
    await w.get('[data-prueba="zona-foto"]').trigger("click");
    expect(clic).toHaveBeenCalled();
    w.unmount();
  });

  it("no sube otro tipo de archivo ni una imagen de más de 4 MB, y lo dice", async () => {
    const w = montar();
    const tarjeta = w.get('[data-prueba="identidad-perfil"]');

    await tarjeta.trigger(
      "drop",
      soltar(new File(["x"], "cv.pdf", { type: "application/pdf" })),
    );
    expect(w.get("[role=alert]").text()).toBe(miPerfil.fotoTipo);

    const grande = new File([new Uint8Array(4 * 1024 * 1024 + 1)], "g.jpg", {
      type: "image/jpeg",
    });
    await tarjeta.trigger("drop", soltar(grande));
    expect(w.get("[role=alert]").text()).toBe(miPerfil.fotoPeso);
    expect(mocks.post).not.toHaveBeenCalled();
    w.unmount();
  });
});
