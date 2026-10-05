import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { misDocumentos } from "@/i18n/locales/gestion.es-MX";
import portal from "@/i18n/locales/portal.es-MX";
import MisDocumentos from "./MisDocumentos.vue";

enableAutoUnmount(afterEach);
const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
function montar() {
  return mount(MisDocumentos, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...esMX, misDocumentos, portal } },
        }),
      ],
      stubs: { ZonaArchivo: true },
    },
  });
}
describe("documentos del miembro", () => {
  beforeEach(() => vi.clearAllMocks());
  it("distingue carga inicial de documentos no solicitados", async () => {
    api.get.mockResolvedValue({ data: { data: { requisitos: [] } } });
    const w = montar();
    expect(w.text()).not.toContain(portal.expediente.sinDocumentos);
    await flushPromises();
    expect(w.text()).toContain(portal.expediente.sinDocumentos);
    expect(api.post).not.toHaveBeenCalled();
  });
  it("un error no aparenta ausencia de documentos y permite reintentar", async () => {
    api.get.mockRejectedValueOnce(new Error("Sin conexión"));
    const w = montar();
    await flushPromises();
    expect(w.text()).toContain(portal.expediente.errorDocumentos);
    expect(w.text()).not.toContain(portal.expediente.sinDocumentos);
    api.get.mockResolvedValueOnce({ data: { data: { requisitos: [] } } });
    await w.get("button").trigger("click");
    await flushPromises();
    expect(w.text()).toContain(portal.expediente.sinDocumentos);
    expect(w.find('[role="alert"]').exists()).toBe(false);
  });
  it("conserva estados de revisión y no ofrece subir de nuevo lo aprobado", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          requisitos: [
            {
              tipo: { id: "t1", nombre: "Identificación", obligatorio: true },
              documento: { id: "d1", estado: "aprobado" },
            },
            {
              tipo: { id: "t2", nombre: "Certificado", obligatorio: false },
              documento: {
                id: "d2",
                estado: "rechazado",
                motivo: "Archivo ilegible",
              },
            },
          ],
        },
      },
    });
    const w = montar();
    await flushPromises();
    expect(w.text()).toContain("Aprobado");
    expect(w.text()).toContain("Archivo ilegible");
    expect(w.findAllComponents({ name: "ZonaArchivo" })).toHaveLength(1);
    expect(api.post).not.toHaveBeenCalled();
  });
});
