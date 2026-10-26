import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useSesionTenantStore } from "@/stores/sesionTenant";

/*
| El portal de quien atiende tiene dos direcciones: /mis-citas en un negocio de citas y
| /mis-clases en uno de clases. Los enlaces por nombre (el menú, los accesos del Inicio)
| llevan a la de su negocio.
*/

const usuario = {
  ulid: "u1",
  nombre: "Leo Barbero",
  email: "leo@demo.test",
  rol: "instructor",
  roles: ["instructor"],
  permisos: ["agenda.ver"],
};
const datos = vi.hoisted(() => ({ modalidad: "citas" as "citas" | "clases" }));

vi.mock("@/lib/api", async (original) => {
  const real = await original<typeof import("@/lib/api")>();
  return {
    ...real,
    api: {
      ...real.api,
      get: vi.fn(async () => ({
        data: {
          data: {
            usuario,
            estudio: {
              slug: "demo",
              nombre: "Barbería Demo",
              estado: "active",
              modalidad: datos.modalidad,
            },
          },
        },
      })),
    },
  };
});

// Aquí solo importa a dónde lleva el router, no las pantallas.
const pantallaVacia = vi.hoisted(() => () => ({
  default: { name: "PantallaVacia", render: () => null },
}));
vi.mock("@/views/InicioInstructorView.vue", pantallaVacia);
vi.mock("@/views/MisClasesView.vue", pantallaVacia);
vi.mock("@/views/MiPerfilView.vue", pantallaVacia);

async function routerConSesion() {
  setActivePinia(createPinia());
  const sesion = useSesionTenantStore();
  sesion.$patch({ slug: "demo", bearer: "simulado" });
  const { default: router } = await import("@/router");
  return router;
}

beforeEach(() => {
  vi.resetModules();
  // jsdom no desplaza la ventana (el router lo pide al navegar).
  window.scrollTo = vi.fn() as unknown as typeof window.scrollTo;
});

describe("el portal de quien atiende, con la dirección de su negocio", () => {
  it(
    "en citas, el menú y los accesos llevan a /mis-citas",
    { timeout: 20_000 },
    async () => {
      datos.modalidad = "citas";
      const router = await routerConSesion();
      await router.push({ name: "mis-clases", query: { modo: "dia" } });
      expect(router.currentRoute.value.fullPath).toBe(
        "/mis-citas/calendario?modo=dia",
      );
      expect(router.currentRoute.value.name).toBe("mis-clases");
      await router.push({ name: "inicio-instructor" });
      expect(router.currentRoute.value.path).toBe("/mis-citas");
    },
  );

  it(
    "en clases sigue en /mis-clases, aunque se escriba /mis-citas",
    { timeout: 20_000 },
    async () => {
      datos.modalidad = "clases";
      const router = await routerConSesion();
      await router.push({ name: "mis-clases" });
      expect(router.currentRoute.value.path).toBe("/mis-clases/calendario");
      await router.push("/mis-citas");
      expect(router.currentRoute.value.path).toBe("/mis-clases");
    },
  );
});
