import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import ActivacionView from "./ActivacionView.vue";

/*
| Activar la cuenta (ADR 0137): solo la de quien registró el negocio cuenta como
| negocio activado (`tenant_activated`); el equipo o un cliente invitados activan su
| cuenta (`account_activated`), sin inflar las altas. Si el enlace venció, se pide
| otro al mismo correo (ADR 0169).
*/

const mocks = vi.hoisted(() => ({
  trackEvent: vi.fn(),
  push: vi.fn(),
  post: vi.fn(async () => ({ data: { data: { ok: true } } })),
  sesion: {
    error: null as string | null,
    cargando: false,
    usuario: null as Record<string, unknown> | null,
    activar: vi.fn(async () => undefined),
  },
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: mocks.trackEvent }));
vi.mock("@/lib/api", () => ({
  api: { post: mocks.post },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => mocks.sesion,
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({
    params: { slug: "fluo" },
    query: { email: "ana@correo.mx", token: "t" },
  }),
  useRouter: () => ({ push: mocks.push }),
  RouterLink: { template: "<a><slot /></a>" },
}));

async function activarCon(
  usuario: Record<string, unknown>,
): Promise<ReturnType<typeof mount>> {
  mocks.sesion.usuario = usuario;
  const w = mount(ActivacionView, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
    },
  });
  const campos = w.findAll('input[type="password"]');
  for (const campo of campos) {
    await campo.setValue("contraseña-segura-1");
  }
  await w.get("form").trigger("submit");
  await flushPromises();
  return w;
}

describe("activar la cuenta", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.sesion.error = null;
    mocks.sesion.activar.mockImplementation(async () => undefined);
  });

  it("la del dueño cuenta como negocio activado", async () => {
    await activarCon({ rol: "propietario", roles: ["propietario"] });

    expect(mocks.trackEvent).toHaveBeenCalledWith("tenant_activated");
  });

  it("la de un cliente o del equipo invitados, no", async () => {
    await activarCon({ rol: "miembro", roles: ["miembro"] });
    expect(mocks.trackEvent).toHaveBeenCalledWith("account_activated", {
      faceta: "miembro",
    });

    vi.clearAllMocks();
    await activarCon({ rol: "instructor", roles: ["instructor"] });
    expect(mocks.trackEvent).toHaveBeenCalledWith("account_activated", {
      faceta: "instructor",
    });
    expect(mocks.trackEvent).not.toHaveBeenCalledWith("tenant_activated");
  });

  it("si el enlace venció, pide uno nuevo al mismo correo", async () => {
    // El store no es reactivo en la prueba: el error ya está al montar.
    mocks.sesion.error = "El enlace de activación ya venció. Pide uno nuevo.";
    mocks.sesion.activar.mockImplementation(async () => {
      throw new Error("vencido");
    });
    const w = await activarCon({ rol: "miembro", roles: ["miembro"] });

    expect(w.text()).toContain("ya venció");
    await w.get('[data-prueba="activacion-pedir-otro"]').trigger("click");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/fluo/reenviar-activacion",
      { email: "ana@correo.mx" },
    );
    expect(w.get('[data-prueba="activacion-reenviada"]').text()).toContain(
      "ana@correo.mx",
    );
    expect(mocks.push).not.toHaveBeenCalled();
  });
});
