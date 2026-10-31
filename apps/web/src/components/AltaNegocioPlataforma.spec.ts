import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import AltaNegocioPlataforma from "./AltaNegocioPlataforma.vue";
import ContactoDuenoPlataforma from "./ContactoDuenoPlataforma.vue";

/*
| Soporte desde el superadmin (ADR 0226): dar de alta un negocio a nombre de su dueño y
| corregir su contacto.
*/

const mocks = vi.hoisted(() => ({
  post: vi.fn(),
  put: vi.fn(),
  errores: vi.fn(() => ({})),
}));
vi.mock("@/lib/clientePlataforma", () => ({
  crearClientePlataforma: () => ({ post: mocks.post, put: mocks.put }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/lib/api", () => ({
  mensajeDeError: (e: Error) => e.message,
  erroresPorCampo: mocks.errores,
}));

enableAutoUnmount(afterEach);
beforeEach(() => vi.clearAllMocks());

describe("alta asistida y contacto del dueño", () => {
  it("el teléfono rechazado se dice junto a su campo y lo escrito se queda", async () => {
    mocks.post.mockRejectedValue(new Error("Revisa los datos"));
    mocks.errores.mockReturnValueOnce({
      contacto_telefono: "El teléfono no es válido.",
    });
    const w = mount(AltaNegocioPlataforma, {
      props: { apiUrl: "", token: "t" },
      global: { plugins: [i18n] },
    });
    await w.get('[data-prueba="abrir-alta"]').trigger("click");
    await w.get('input[inputmode="tel"]').setValue("5512345678");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(w.get('[role="alert"]').text()).toBe("El teléfono no es válido.");
    expect(
      (w.get('input[inputmode="tel"]').element as HTMLInputElement).value,
    ).toBe("5512345678");
    expect(w.emitted("creado")).toBeUndefined();
  });

  it("un rechazo sin campo se dice dentro del formulario, sin cerrarlo", async () => {
    mocks.post.mockRejectedValue(new Error("Sin conexión"));
    const w = mount(AltaNegocioPlataforma, {
      props: { apiUrl: "", token: "t" },
      global: { plugins: [i18n] },
    });
    await w.get('[data-prueba="abrir-alta"]').trigger("click");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(w.get('[data-prueba="error-alta"]').text()).toContain(
      "Sin conexión",
    );
    expect(w.find("form").exists()).toBe(true);
  });

  it("corregir el contacto: un error de un campo oculto también se dice", async () => {
    mocks.put.mockRejectedValue(new Error("Nombre muy largo"));
    mocks.errores.mockReturnValueOnce({
      contacto_segundo_nombre: "Es muy largo.",
    });
    const w = mount(ContactoDuenoPlataforma, {
      props: {
        apiUrl: "",
        token: "t",
        slug: "luna",
        campos: {
          contacto_nombre: "Ana",
          contacto_segundo_nombre: "x",
          contacto_primer_apellido: "Luna",
          contacto_segundo_apellido: null,
          contacto_email: "a@correo.mx",
          contacto_whatsapp_pais: "52",
          contacto_telefono: "5512345678",
        },
      },
      global: { plugins: [i18n] },
    });
    await w.get('[data-prueba="abrir-contacto"]').trigger("click");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(w.get('[data-prueba="error-contacto"]').text()).toContain(
      "Nombre muy largo",
    );
  });

  it("da de alta el negocio con su giro y su dueño, y abre su ficha", async () => {
    mocks.post.mockResolvedValue({ data: { data: { slug: "pilates-luna" } } });
    const w = mount(AltaNegocioPlataforma, {
      props: { apiUrl: "", token: "t" },
      global: { plugins: [i18n] },
    });
    await w.get('[data-prueba="abrir-alta"]').trigger("click");
    const campos = w.findAll("input");
    await campos[0]!.setValue("Pilates Luna");
    await w.get("select").setValue("pilates");
    await campos[1]!.setValue("Rocío");
    await campos[2]!.setValue("Luna");
    await campos[3]!.setValue("rocio@correo.mx");
    await campos[5]!.setValue("5512345678");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/plataforma/estudios",
      expect.objectContaining({
        nombre: "Pilates Luna",
        perfil_negocio: "pilates",
        contacto_email: "rocio@correo.mx",
        contacto_whatsapp_pais: "52",
      }),
      expect.anything(),
    );
    expect(w.emitted("creado")?.[0]).toEqual(["pilates-luna"]);
  });

  it("corrige el contacto del dueño por la ruta de su negocio", async () => {
    mocks.put.mockResolvedValue({ data: {} });
    const w = mount(ContactoDuenoPlataforma, {
      props: {
        apiUrl: "",
        token: "t",
        slug: "pilates-luna",
        campos: {
          contacto_nombre: "Rocío",
          contacto_segundo_nombre: null,
          contacto_primer_apellido: "Luna",
          contacto_segundo_apellido: null,
          contacto_email: "rocio@corre.mx",
          contacto_whatsapp_pais: "52",
          contacto_telefono: "5512345678",
        },
      },
      global: { plugins: [i18n] },
    });
    await w.get('[data-prueba="abrir-contacto"]').trigger("click");
    await w.get('input[type="email"]').setValue("rocio@correo.mx");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(mocks.put).toHaveBeenCalledWith(
      "/api/v1/plataforma/estudios/pilates-luna/contacto",
      expect.objectContaining({
        contacto_email: "rocio@correo.mx",
        contacto_nombre: "Rocío",
      }),
      expect.anything(),
    );
    expect(w.emitted("guardado")).toBeTruthy();
  });
});
