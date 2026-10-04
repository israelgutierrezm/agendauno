import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import { menuVisible } from "@/lib/menu";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import PanelRoles from "@/components/PanelRoles.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  fijarBearer: vi.fn(),
  mensajeDeError: (e: unknown) => String(e),
}));
const router = vi.hoisted(() => ({ replace: vi.fn(), push: vi.fn() }));
vi.mock("vue-router", () => ({ useRouter: () => router }));

const ROLES = [
  { clave: "admin", faceta: "equipo" },
  { clave: "instructor", faceta: "instructor" },
  { clave: "miembro", faceta: "miembro" },
];

function usuario(rol: string, permisos: string[]) {
  return {
    ulid: "u1",
    nombre: "Ana",
    email: "ana@correo.mx",
    rol,
    roles: ["admin", "instructor", "miembro"],
    roles_disponibles: ROLES,
    permisos,
  };
}

function i18n() {
  return createI18n({
    legacy: false,
    locale: "es-MX",
    messages: { "es-MX": { ...esMX, operacion } },
  });
}

describe("rol activo", () => {
  it("exige elegir al autenticar y conserva la elección pendiente hasta confirmarla", async () => {
    api.post.mockResolvedValue({
      data: {
        data: {
          token: "prueba",
          usuario: usuario("admin", ["*"]),
          estudio: { slug: "demo", nombre: "Demo", estado: "active" },
        },
      },
    });
    const sesion = useSesionTenantStore();
    await sesion.iniciarSesion("demo", "ana@correo.mx", "password");
    expect(sesion.requiereElegirRol).toBe(true);
    expect(localStorage.getItem("tu.tenant.rol-pendiente")).toBe("1");
    sesion.confirmarRolInicial();
    expect(sesion.requiereElegirRol).toBe(false);
    expect(localStorage.getItem("tu.tenant.rol-pendiente")).toBeNull();
  });
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it("tras entrar va al inicio de su rol; con varios, el panel lateral pregunta antes", () => {
    const sesion = useSesionTenantStore();
    sesion.usuario = usuario("instructor", ["agenda.ver"]);
    expect(sesion.tieneVariosRoles).toBe(true);
    expect(sesion.destinoAlEntrar).toBe("inicio-instructor");

    sesion.usuario = {
      ...usuario("miembro", []),
      roles_disponibles: [ROLES[2]],
    };
    expect(sesion.tieneVariosRoles).toBe(false);
    expect(sesion.destinoAlEntrar).toBe("mi-cuenta");
  });

  it("el inicio y el menú son del rol activo, no de la suma de roles", () => {
    const sesion = useSesionTenantStore();
    // Las pantallas que ofrece el menú: cada vista permitida de cada área.
    const visibles = () =>
      menuVisible(sesion).flatMap((g) =>
        g.areas.flatMap((a) => a.vistas.map((v) => v.ruta)),
      );

    sesion.usuario = usuario("miembro", ["formularios.responder"]);
    expect(sesion.rutaInicio).toBe("mi-cuenta");
    expect(visibles()).toContain("mis-reservas");
    expect(visibles()).not.toContain("miembros");
    expect(visibles()).not.toContain("inicio-instructor");

    sesion.usuario = usuario("admin", ["facturacion.ver", "miembros.ver"]);
    expect(sesion.rutaInicio).toBe("panel");
    expect(visibles()).toContain("miembros");
    expect(visibles()).not.toContain("mis-reservas");

    sesion.usuario = usuario("instructor", ["agenda.ver"]);
    expect(sesion.rutaInicio).toBe("inicio-instructor");
    expect(visibles()).toContain("inicio-instructor");
    expect(visibles()).not.toContain("mis-reservas");
  });

  // El panel lateral derecho al entrar (como en Acadion).
  function panelAlEntrar(destino: string | null = null) {
    return mount(PanelRoles, {
      props: { abierto: true, alEntrar: true, destino },
      global: { plugins: [i18n()], stubs: { teleport: true } },
    });
  }

  it("«¿Cómo quieres entrar?» en el panel lateral: marca el de la última vez y cambia al elegido", async () => {
    const sesion = useSesionTenantStore();
    sesion.slug = "demo";
    sesion.usuario = usuario("instructor", ["agenda.ver"]);
    api.put.mockResolvedValue({
      data: {
        data: {
          usuario: usuario("miembro", ["formularios.responder"]),
          estudio: { slug: "demo", nombre: "Demo", estado: "active" },
        },
      },
    });

    const w = panelAlEntrar();
    expect(w.get('[role="dialog"]').text()).toContain("¿Cómo quieres entrar?");
    const tarjetas = w.findAll(".lr-rol");
    expect(tarjetas.map((t) => t.find(".lr-nombre").text())).toEqual([
      "Administrador",
      "Instructor",
      "Miembro",
    ]);
    expect(tarjetas[1].classes()).toContain("lr-marcado");
    expect(tarjetas[1].text()).toContain("La última vez");

    await tarjetas[2].trigger("click");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/yo/rol-activo", {
      rol: "miembro",
    });
    expect(router.replace).toHaveBeenCalledWith({ name: "mi-cuenta" });
  });

  it("entrar con el rol que ya estaba marcado no pide nada a la API", async () => {
    const sesion = useSesionTenantStore();
    sesion.slug = "demo";
    sesion.usuario = usuario("instructor", ["agenda.ver"]);

    const w = panelAlEntrar();
    await w.findAll(".lr-rol")[1].trigger("click");
    await flushPromises();

    expect(api.put).not.toHaveBeenCalled();
    expect(router.replace).toHaveBeenCalledWith({ name: "inicio-instructor" });
  });

  it("cerrar el panel al entrar entra con el rol de la última vez y vuelve a donde venía", async () => {
    const sesion = useSesionTenantStore();
    sesion.slug = "demo";
    sesion.usuario = usuario("instructor", ["agenda.ver"]);
    sesion.requiereElegirRol = true;

    const w = panelAlEntrar("/agendar/demo");
    await w.get('[role="dialog"] button[aria-label="Cerrar"]').trigger("click");
    await flushPromises();

    expect(api.put).not.toHaveBeenCalled();
    expect(router.replace).toHaveBeenCalledWith("/agendar/demo");
    expect(sesion.requiereElegirRol).toBe(false);
    expect(w.emitted("cerrar")).toBeTruthy();
  });
});
