import { flushPromises, mount } from "@vue/test-utils";
import { reactive } from "vue";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import AvisoRenta from "./AvisoRenta.vue";

/*
| El pago de la suscripción por atender, arriba del panel (ADR 0152): solo para quien
| ve la facturación, nunca en «Mi suscripción» y con el tono de lo urgente que es.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api }));

const sesion = vi.hoisted(() => ({
  slug: "demo" as string | null,
  pais: "MX",
  suspendido: false,
  usuario: { rol: "propietario" },
  permisos: ["facturacion.ver"],
  puede(p: string) {
    return this.permisos.includes(p);
  },
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

const ruta = vi.hoisted(() => ({ actual: null as null | { name: string } }));
vi.mock("vue-router", () => ({
  useRoute: () => ruta.actual,
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
}));

const aviso = (extra: object) => ({
  data: {
    data: {
      nivel: "vencido",
      cargo_id: "c1",
      periodo: "2026-10",
      monto_minor: 45240,
      moneda: "MXN",
      vence_en: "2026-11-10",
      suspende_en: "2026-11-25",
      dias_para_suspension: 14,
      rechazado: false,
      proximo_intento_en: null,
      con_tarjeta: false,
      ...extra,
    },
  },
});

function montar() {
  return mount(AvisoRenta, { global: { plugins: [i18n] } });
}

beforeEach(() => {
  vi.clearAllMocks();
  sesion.permisos = ["facturacion.ver"];
  sesion.suspendido = false;
  ruta.actual = reactive({ name: "panel" });
});

describe("aviso de la renta en el panel", () => {
  it("vencida: cuándo se suspende y el botón para pagar", async () => {
    api.get.mockResolvedValue(aviso({}));
    const w = montar();
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/renta/aviso");
    const texto = w.get('[data-prueba="aviso-renta"]').text();
    expect(texto).toContain("Tu pago de $452.40 venció el 10 de noviembre");
    expect(texto).toContain("se suspenderá el 25 de noviembre (en 14 días)");
    expect(w.find(".tu-tono-aviso").exists()).toBe(true);
    expect(w.get('[data-prueba="aviso-renta-pagar"]').text()).toBe(
      "Pagar ahora",
    );
  });

  it("a días de suspender, en rojo", async () => {
    api.get.mockResolvedValue(
      aviso({ nivel: "por_suspender", dias_para_suspension: 2 }),
    );
    const w = montar();
    await flushPromises();

    expect(w.find(".tu-tono-peligro").exists()).toBe(true);
    expect(w.text()).toContain("Tu negocio se suspenderá el 25 de noviembre");
  });

  it("sin nada que avisar, no se ve", async () => {
    api.get.mockResolvedValue({ data: { data: null } });
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="aviso-renta"]').exists()).toBe(false);
  });

  it("a quien no ve la facturación ni se le pregunta", async () => {
    sesion.permisos = ["agenda.ver"];
    const w = montar();
    await flushPromises();

    expect(api.get).not.toHaveBeenCalled();
    expect(w.find('[data-prueba="aviso-renta"]').exists()).toBe(false);
  });

  it("en «Mi suscripción» no se repite y al salir se vuelve a preguntar", async () => {
    ruta.actual = reactive({ name: "renta" });
    api.get.mockResolvedValue(aviso({}));
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="aviso-renta"]').exists()).toBe(false);

    // Pagó: al volver al panel ya no hay aviso.
    api.get.mockResolvedValue({ data: { data: null } });
    ruta.actual!.name = "panel";
    await flushPromises();
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(w.find('[data-prueba="aviso-renta"]').exists()).toBe(false);
  });
});
