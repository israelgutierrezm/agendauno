import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import MedicionPlataforma from "./MedicionPlataforma.vue";

/*
| Medición del SaaS en el superadmin (ADR 0143): un producto a la vez, con sus dos
| destinos lado a lado, y los últimos envíos en su propia pestaña, por páginas (antes
| todo iba seguido en casi tres pantallas).
*/

const http = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("axios", () => ({ default: { create: () => http } }));
vi.mock("@/lib/api", () => ({ mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(() => true) }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const destino = (producto: string, proveedor: "ga4" | "meta") => ({
  producto,
  proveedor,
  identificador: null,
  secreto_configurado: false,
  codigo_prueba: null,
  activo: false,
  activo_desde: null,
  aviso_confirmado_en: null,
  estado: "apagado",
  ultimo_envio_en: null,
  ultimo_error: null,
  ultimo_error_en: null,
});

const envio = (i: number) => ({
  id: `e${i}`,
  producto: "agendauno",
  proveedor: "ga4",
  evento: "negocio_creado",
  nombre: `evento_${i}`,
  negocio: "demo",
  ocurrido_en: "2026-10-12T15:00:00Z",
  estado: "enviado",
  motivo: null,
  intentos: 1,
  proximo_intento_en: null,
  enviado_en: "2026-10-12T15:00:05Z",
  ultimo_error: null,
});

beforeEach(() => {
  vi.clearAllMocks();
  http.get.mockResolvedValue({
    data: {
      data: {
        destinos: [
          destino("agendauno", "ga4"),
          destino("agendauno", "meta"),
          destino("turnouno", "ga4"),
          destino("turnouno", "meta"),
        ],
        envios: Array.from({ length: 20 }, (_, i) => envio(i + 1)),
      },
    },
  });
});

const IDENTIFICADOR_AGENDA =
  '[data-producto="agendauno"] [data-prueba="identificador-destino"]';

function montar() {
  return mount(MedicionPlataforma, {
    props: { apiUrl: "http://api", token: "tk" },
    global: { plugins: [i18n] },
  });
}

describe("medición de la plataforma", () => {
  it("muestra un producto a la vez y los envíos en su pestaña, por páginas", async () => {
    const w = montar();
    await flushPromises();

    expect(http.get).toHaveBeenCalledWith("/api/v1/plataforma/medicion");
    const agenda = w.get('[data-producto="agendauno"]');
    const turno = w.get('[data-producto="turnouno"]');
    expect(agenda.findAll('[data-prueba="destino-medicion"]')).toHaveLength(2);
    expect(turno.findAll('[data-prueba="destino-medicion"]')).toHaveLength(2);
    expect(agenda.attributes("style") ?? "").not.toContain("display: none");
    expect(turno.attributes("style")).toContain("display: none");
    expect(w.find('[data-prueba="envios-medicion"]').exists()).toBe(false);

    await w.get('[data-pestana="turnouno"]').trigger("click");
    expect(turno.attributes("style") ?? "").not.toContain("display: none");
    expect(agenda.attributes("style")).toContain("display: none");

    await w.get('[data-pestana="envios"]').trigger("click");
    expect(agenda.attributes("style")).toContain("display: none");
    expect(turno.attributes("style")).toContain("display: none");
    expect(w.findAll('[data-prueba="envio-medicion"]')).toHaveLength(15);
    // La pestaña ya se llama «Últimos envíos»: la tarjeta no lo repite como título, y
    // la paginación va dentro de ella.
    const tarjeta = w.get('[data-prueba="envios-medicion"]');
    expect(tarjeta.find("h3").exists()).toBe(false);
    expect(tarjeta.text()).not.toContain("Últimos envíos");
    expect(tarjeta.find('[data-prueba="paginas-envios"]').exists()).toBe(true);

    await w.get('button[aria-label="Página 2"]').trigger("click");
    const envios = w.findAll('[data-prueba="envio-medicion"]');
    expect(envios).toHaveLength(5);
    expect(envios[0]!.text()).toContain("evento_16");
  });

  it("conserva lo escrito en un destino al cambiar de pestaña", async () => {
    const w = montar();
    await flushPromises();
    const campo = () => w.get(IDENTIFICADOR_AGENDA);

    await campo().setValue("G-AGENDA123");
    await w.get('[data-pestana="envios"]').trigger("click");
    await w.get('[data-pestana="agendauno"]').trigger("click");

    expect((campo().element as HTMLInputElement).value).toBe("G-AGENDA123");
  });
});
