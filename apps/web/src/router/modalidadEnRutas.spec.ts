import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useSesionTenantStore } from "@/stores/sesionTenant";

/*
| Una pantalla de clases no se abre en un negocio de citas, ni escribiendo su dirección
| ni con un enlace guardado (el `volver` que se recuerda al iniciar sesión): el router
| consulta la misma política que el menú (rol, modalidad, funciones y permisos).
*/

const sesionDe = vi.hoisted(() => ({
  modalidad: "citas" as "citas" | "clases",
}));
vi.mock("@/lib/api", async (original) => {
  const real = await original<typeof import("@/lib/api")>();
  return {
    ...real,
    api: {
      ...real.api,
      get: vi.fn(async () => ({
        data: {
          data: {
            usuario: {
              ulid: "u1",
              nombre: "Jorge Pineda",
              email: "jorge@correo.test",
              rol: "miembro",
              roles: ["miembro"],
              permisos: [],
            },
            estudio: {
              slug: "barberia",
              nombre: "La Navaja",
              estado: "active",
              modalidad: sesionDe.modalidad,
            },
          },
        },
      })),
    },
  };
});

// Solo importa a dónde lleva el router, no las pantallas.
const pantallaVacia = vi.hoisted(() => () => ({
  default: { name: "PantallaVacia", render: () => null },
}));
vi.mock("@/views/MiCuentaView.vue", pantallaVacia);
vi.mock("@/views/MisVideosView.vue", pantallaVacia);
vi.mock("@/views/EntrarView.vue", pantallaVacia);
vi.mock("@/views/ModalidadView.vue", pantallaVacia);

async function routerConSesion() {
  setActivePinia(createPinia());
  const sesion = useSesionTenantStore();
  sesion.$patch({ slug: "barberia", bearer: "simulado" });
  const { default: router } = await import("@/router");
  return router;
}

beforeEach(() => {
  vi.resetModules();
  window.scrollTo = vi.fn() as unknown as typeof window.scrollTo;
});

describe("pantallas de una sola modalidad", () => {
  it("un cliente de citas que escribe /mi-cuenta/videos llega a su cuenta", async () => {
    sesionDe.modalidad = "citas";
    const router = await routerConSesion();
    await router.push("/mi-cuenta/videos");
    expect(router.currentRoute.value.name).toBe("mi-cuenta");
  });

  it("con un enlace guardado al iniciar sesión, tampoco", async () => {
    sesionDe.modalidad = "citas";
    const router = await routerConSesion();
    await router.push("/entrar?estudio=barberia&volver=/mi-cuenta/videos");
    expect(router.currentRoute.value.name).toBe("mi-cuenta");
  });

  it("un alumno de un negocio de clases sí entra", async () => {
    sesionDe.modalidad = "clases";
    const router = await routerConSesion();
    await router.push("/mi-cuenta/videos");
    expect(router.currentRoute.value.name).toBe("mis-videos");
  });
});
