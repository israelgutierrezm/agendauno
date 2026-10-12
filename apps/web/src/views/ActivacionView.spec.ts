import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import ActivacionView from "./ActivacionView.vue";

/*
| Activar la cuenta (ADR 0137): solo la de quien registró el negocio cuenta como
| negocio activado (`tenant_activated`); el equipo o un cliente invitados activan su
| cuenta (`account_activated`), sin inflar las altas.
*/

const mocks = vi.hoisted(() => ({
  trackEvent: vi.fn(),
  push: vi.fn(),
  sesion: {
    error: null as string | null,
    cargando: false,
    usuario: null as Record<string, unknown> | null,
    activar: vi.fn(async () => undefined),
  },
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: mocks.trackEvent }));
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

async function activarCon(usuario: Record<string, unknown>): Promise<void> {
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
}

describe("activar la cuenta", () => {
  beforeEach(() => vi.clearAllMocks());

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
});
