import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useAparienciaStore, type Apariencia } from "@/stores/apariencia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/*
| Con sesión abierta, las páginas públicas se ven como para cualquier visitante
| (sin el panel ni el tema del usuario) y entrar a OTRO negocio sigue siendo posible.
*/

const usuario = {
  ulid: "u1",
  nombre: "Dueño Demo",
  email: "duena@demo.test",
  rol: "propietario",
  roles: ["propietario"],
  permisos: ["*"],
};
const estudio = { slug: "demo", nombre: "Estudio Demo", estado: "active" };

vi.mock("@/lib/api", async (original) => {
  const real = await original<typeof import("@/lib/api")>();
  return {
    ...real,
    api: {
      ...real.api,
      get: vi.fn(async () => ({ data: { data: { usuario, estudio } } })),
    },
  };
});

async function routerConSesion() {
  setActivePinia(createPinia());
  const sesion = useSesionTenantStore();
  sesion.$patch({ slug: "demo", bearer: "simulado" });
  const { default: router } = await import("@/router");
  await router.push("/panel");
  return router;
}

beforeEach(() => {
  vi.resetModules();
  // jsdom no desplaza la ventana (el router lo pide al navegar).
  window.scrollTo = vi.fn() as unknown as typeof window.scrollTo;
});

describe("entrar con una sesión abierta", () => {
  it("al acceso de su propio negocio (o sin negocio) va a su inicio", async () => {
    const router = await routerConSesion();
    await router.push("/entrar?estudio=demo");
    expect(router.currentRoute.value.name).toBe("panel");
    await router.push("/entrar");
    expect(router.currentRoute.value.name).toBe("panel");
  });

  it("con `volver`, al acceso de su negocio regresa a donde venía", async () => {
    const router = await routerConSesion();
    await router.push("/entrar?estudio=demo&volver=/agendar/demo");
    expect(router.currentRoute.value.fullPath).toBe("/agendar/demo");
    // Solo rutas internas: un enlace no manda a otro sitio.
    await router.push("/entrar?estudio=demo&volver=//otro.sitio");
    expect(router.currentRoute.value.name).toBe("panel");
  });

  it("al acceso de OTRO negocio sí entra, para cambiar de negocio", async () => {
    const router = await routerConSesion();
    await router.push("/entrar?estudio=barberia");
    expect(router.currentRoute.value.name).toBe("entrar");
  });

  it("las páginas públicas de un negocio no piden sesión: se ven como públicas", async () => {
    const router = await routerConSesion();
    for (const ruta of [
      "/agendar/barberia",
      "/estudio/barberia",
      "/sucursales/barberia",
      "/barberia",
      "/barberia/enlaces",
      "/negocios",
    ]) {
      await router.push(ruta);
      expect(router.currentRoute.value.meta.requiereSesion).not.toBe(true);
    }
  });
});

describe("palabras del negocio fuera del panel", () => {
  it("en pausa rigen los textos base; al volver al panel, las del negocio", async () => {
    const { aplicarTerminologia, i18n, pausarTerminologia } =
      await import("@/i18n");
    const t = (llave: string) => i18n.global.t(llave);
    aplicarTerminologia({
      sesion: "Cita",
      miembro: "Cliente",
      instructor: "Barbero",
    });
    expect(t("agenda.nuevaClase")).toBe("Nueva cita");

    pausarTerminologia(true);
    expect(t("agenda.nuevaClase")).toBe("Nueva clase");
    // Lo que llegue mientras tanto (p. ej. /yo) no se pinta hasta volver.
    aplicarTerminologia({
      sesion: "Sesión",
      miembro: "Paciente",
      instructor: "Terapeuta",
    });
    expect(t("agenda.nuevaClase")).toBe("Nueva clase");

    pausarTerminologia(false);
    expect(t("agenda.nuevaClase")).toBe("Nueva sesión");
    aplicarTerminologia(null);
  });
});

describe("tema del usuario fuera del panel", () => {
  const tema: Apariencia = {
    clave: "oscuro",
    nombre: "Oscuro",
    oscuro: true,
    permite_personalizar: false,
    tokens: { acento: "#123456" },
    personalizacion: {},
  };

  it("en pausa no se pinta; al volver al panel, sí", () => {
    setActivePinia(createPinia());
    const raiz = document.documentElement;
    const apariencia = useAparienciaStore();

    apariencia.pausar(true);
    apariencia.activar(tema);
    expect(raiz.style.getPropertyValue("--acento")).toBe("");

    apariencia.pausar(false);
    expect(raiz.style.getPropertyValue("--acento")).toBe("#123456");
    expect(raiz.classList.contains("dark")).toBe(true);

    apariencia.pausar(true);
    expect(raiz.style.getPropertyValue("--acento")).toBe("");
    apariencia.desactivar();
  });
});
