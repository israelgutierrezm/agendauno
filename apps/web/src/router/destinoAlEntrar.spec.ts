import { createPinia, setActivePinia } from "pinia";
import { describe, expect, it, vi } from "vitest";

/*
| Un enlace a una pantalla privada sin sesión lleva a Entrar sin perder el destino:
| al entrar, se vuelve ahí y no a un inicio sin contexto (M11).
*/

vi.mock("@/lib/api", async (original) => {
  const real = await original<typeof import("@/lib/api")>();
  return {
    ...real,
    api: { ...real.api, get: vi.fn(async () => ({ data: { data: {} } })) },
  };
});

describe("sin sesión, a Entrar con el destino", () => {
  // Carga el enrutador completo y la pantalla de Entrar: con toda la suite en paralelo
  // tarda más de los 5 s por omisión.
  it(
    "conserva la pantalla y su filtro en `volver`",
    { timeout: 20_000 },
    async () => {
      setActivePinia(createPinia());
      const { default: router } = await import("@/router");

      await router.push("/miembros?tipo=miembro");

      expect(router.currentRoute.value.name).toBe("entrar");
      expect(router.currentRoute.value.query.volver).toBe(
        "/miembros?tipo=miembro",
      );
    },
  );
});
