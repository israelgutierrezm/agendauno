import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DOMINIO_PUBLICO } from "@/lib/tenant";

/*
| Rutas comerciales del router de la app: /clases y /citas con su modo, el registro con
| `?modo=` como prop, la landing que nunca se ve en el subdominio de un negocio y el
| enlace corto que en producción lleva al subdominio.
*/

// El host del navegador que «ve» el router: jsdom no deja cambiarlo.
const navegador = vi.hoisted(() => ({
  host: "localhost",
  salirA: vi.fn<(url: string) => void>(),
}));

vi.mock("@/lib/tenant", async (original) => {
  const real = await original<typeof import("@/lib/tenant")>();
  return {
    ...real,
    urlEnSubdominioDelNegocio: (
      ruta: Parameters<typeof real.urlEnSubdominioDelNegocio>[0],
    ) => real.urlEnSubdominioDelNegocio(ruta, navegador.host),
    salirA: navegador.salirA,
  };
});

vi.mock("@/lib/api", async (original) => {
  const real = await original<typeof import("@/lib/api")>();
  return { ...real, api: { ...real.api, get: vi.fn() } };
});

// Importar el router completo (con todas sus pantallas) tarda.
vi.setConfig({ testTimeout: 20_000 });

beforeEach(() => {
  vi.resetModules();
  setActivePinia(createPinia());
  navegador.host = "localhost";
  navegador.salirA.mockClear();
  window.scrollTo = vi.fn() as unknown as typeof window.scrollTo;
});

async function routerComercial() {
  const { default: router } = await import("@/router");
  return router;
}

describe("rutas comerciales", () => {
  it("/clases y /citas montan la vista de modalidad con su modo", async () => {
    const router = await routerComercial();
    for (const modo of ["clases", "citas"] as const) {
      await router.push(`/${modo}`);
      const ruta = router.currentRoute.value;
      expect(ruta.name).toBe(`modalidad-${modo}`);
      expect(ruta.meta).toMatchObject({ marketing: true, modo });
      expect(ruta.matched[0]?.props.default).toEqual({ modo });
    }
    await router.push("/software-para-barberias");
    expect(router.currentRoute.value.meta).toMatchObject({
      marketing: true,
      modo: "citas",
      // Junta dos giros (barbería y estética): sin giro.
      giro: null,
    });
    await router.push("/software-para-pilates");
    expect(router.currentRoute.value.meta).toMatchObject({
      marketing: true,
      modo: "clases",
      giro: "pilates",
    });
    await router.push("/");
    expect(router.currentRoute.value.meta).toMatchObject({
      marketing: true,
      modo: null,
    });
    // Las pantallas de acceso no son comerciales.
    await router.push("/entrar");
    expect(router.currentRoute.value.meta.marketing).toBeUndefined();
  });

  it("el registro recibe el ?modo= y el ?giro= normalizados como props, o null", async () => {
    const router = await routerComercial();
    const props = (query: string) => {
      const ruta = router.resolve(`/registro${query}`);
      const crear = ruta.matched[0]?.props.default as (
        r: typeof ruta,
      ) => unknown;
      return crear(ruta);
    };
    expect(props("?modo=citas")).toEqual({ modo: "citas", giro: null });
    expect(props("?modo=CLASES")).toEqual({ modo: "clases", giro: null });
    expect(props("?modo=otro")).toEqual({ modo: null, giro: null });
    expect(props("")).toEqual({ modo: null, giro: null });
    expect(props("?modo=citas&giro=barberia")).toEqual({
      modo: "citas",
      giro: "barberia",
    });
    expect(props("?giro=General_Citas")).toEqual({
      modo: null,
      giro: "general_citas",
    });
    // Un negocio del carrusel o el slug de una página no son giros del registro.
    expect(props("?modo=citas&giro=wellness")).toEqual({
      modo: "citas",
      giro: null,
    });
  });

  it("en el subdominio de un negocio, ninguna página comercial muestra la landing", async () => {
    const router = await routerComercial();
    // En desarrollo, `?estudio=` hace las veces del subdominio.
    for (const ruta of [
      "/?estudio=demo",
      "/clases?estudio=demo",
      "/citas?estudio=demo",
      "/software-para-pilates?estudio=demo",
    ]) {
      await router.push(ruta);
      expect(router.currentRoute.value.name, ruta).toBe("sucursales-estudio");
      expect(router.currentRoute.value.params.slug).toBe("demo");
    }
    await router.push("/clases");
    expect(router.currentRoute.value.name).toBe("modalidad-clases");
  });

  it("en desarrollo, el enlace corto y la página del negocio siguen en la app", async () => {
    const router = await routerComercial();
    await router.push("/barberia");
    expect(router.currentRoute.value.name).toBe("estudio-corto");
    await router.push("/estudio/barberia");
    expect(router.currentRoute.value.name).toBe("estudio-publico");
    expect(navegador.salirA).not.toHaveBeenCalled();
  });

  it("en el dominio principal, el enlace corto y la página del negocio van a su subdominio", async () => {
    navegador.host = DOMINIO_PUBLICO;
    const router = await routerComercial();
    await router.push("/clases");
    expect(router.currentRoute.value.name).toBe("modalidad-clases");

    await router.push("/barberia?utm_source=ig");
    expect(navegador.salirA).toHaveBeenLastCalledWith(
      `https://barberia.${DOMINIO_PUBLICO}/?utm_source=ig`,
    );
    // La página del negocio conserva su ruta, la query y el ancla.
    await router.push("/estudio/foo?x=1#precios");
    expect(navegador.salirA).toHaveBeenLastCalledWith(
      `https://foo.${DOMINIO_PUBLICO}/estudio/foo?x=1#precios`,
    );
    await router.push("/barberia/enlaces");
    expect(navegador.salirA).toHaveBeenLastCalledWith(
      `https://barberia.${DOMINIO_PUBLICO}/enlaces`,
    );
    // La navegación se cancela: la app no muestra la página del negocio.
    expect(router.currentRoute.value.name).toBe("modalidad-clases");

    // /agendar/:slug no cambia (lo generan el API, los correos y los pagos).
    navegador.salirA.mockClear();
    await router.push("/agendar/barberia");
    expect(router.currentRoute.value.name).toBe("agendar-cita");
    expect(navegador.salirA).not.toHaveBeenCalled();
  });
});
