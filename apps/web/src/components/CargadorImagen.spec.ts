import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import CargadorImagen from "./CargadorImagen.vue";

const api = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));

function montar(props: Record<string, unknown>) {
  return mount(CargadorImagen, {
    props,
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { perfilPublico } },
        }),
      ],
    },
  });
}
async function elegirArchivo(
  w: ReturnType<typeof montar>,
  archivo: File,
): Promise<void> {
  const entrada = w.get('input[type="file"]');
  Object.defineProperty(entrada.element, "files", {
    value: [archivo],
    configurable: true,
  });
  await entrada.trigger("change");
  await flushPromises();
}

beforeEach(() => vi.clearAllMocks());

describe("cargador de imagen", () => {
  it("sube la foto de una sede a su ruta y avisa la nueva URL", async () => {
    api.post.mockResolvedValue({
      data: { data: { id: "s1", foto_url: "/storage/s1.webp" } },
    });
    const w = montar({
      url: null,
      ruta: "sucursales/s1/foto",
      campo: "foto",
      clave: "foto_url",
    });
    await elegirArchivo(w, new File(["x"], "sede.png", { type: "image/png" }));

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/sucursales/s1/foto",
      expect.any(FormData),
    );
    const cuerpo = api.post.mock.calls[0][1] as FormData;
    expect(cuerpo.get("foto")).toBeInstanceOf(File);
    expect(w.emitted("update:url")).toEqual([["/storage/s1.webp"]]);
  });

  it("la quita con DELETE en la misma ruta", async () => {
    api.delete.mockResolvedValue({});
    const w = montar({
      url: "/storage/s1.webp",
      ruta: "sucursales/s1/foto",
      quitarTexto: "Quitar foto",
    });
    await w
      .findAll("button")
      .find((b) => b.text() === "Quitar foto")!
      .trigger("click");
    await flushPromises();
    expect(api.delete).toHaveBeenCalledWith(
      "/api/v1/app/demo/sucursales/s1/foto",
    );
    expect(w.emitted("update:url")).toEqual([[null]]);
  });

  it("sin ruta es la portada del negocio, y no sube lo que no es imagen", async () => {
    const w = montar({ url: null });
    await elegirArchivo(
      w,
      new File(["<svg/>"], "logo.svg", { type: "image/svg+xml" }),
    );
    expect(api.post).not.toHaveBeenCalled();
    expect(w.text()).toContain("Usa una imagen PNG, JPG o WebP.");

    api.post.mockResolvedValue({ data: { data: { portada_url: "/p.webp" } } });
    await elegirArchivo(w, new File(["x"], "p.jpg", { type: "image/jpeg" }));
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/marca/portada",
      expect.any(FormData),
    );
    expect(w.emitted("update:url")).toEqual([["/p.webp"]]);
  });
});
