import { describe, expect, it } from "vitest";

import { POLITICAS, puedeEntrar } from "@/lib/acceso";
import type { useSesionTenantStore } from "@/stores/sesionTenant";

/*
| La política de acceso evalúa todo junto: el rol activo, la modalidad del negocio, las
| funciones de su perfil, su plan y los permisos. Antes, un portal «solo de alumno»
| dejaba entrar por el rol sin revisar la modalidad: un cliente de citas llegaba a «Mis
| videos», que es de clases.
*/

type Sesion = ReturnType<typeof useSesionTenantStore>;

function sesion(
  rol: string,
  modalidad: "clases" | "citas",
  permisos: string[] = [],
  extra: { flags?: Record<string, boolean>; sin?: string[] } = {},
): Sesion {
  return {
    suspendido: false,
    modalidad,
    usuario: { rol, roles: [rol], permisos },
    estudio: {
      perfil_config: {
        flags: {
          grupos: true,
          niveles: true,
          acceso_abierto: true,
          ...extra.flags,
        },
      },
      plan: { sin: extra.sin ?? [] },
    },
    puede: (p: string) => permisos.includes("*") || permisos.includes(p),
  } as unknown as Sesion;
}

describe("política de acceso", () => {
  it("un cliente de citas no entra a «Mis videos»; un alumno de clases sí", () => {
    expect(puedeEntrar("mis-videos", sesion("miembro", "citas"))).toBe(false);
    expect(puedeEntrar("mis-videos", sesion("miembro", "clases"))).toBe(true);
    // Los portales de siempre siguen abiertos para el cliente de citas.
    expect(puedeEntrar("mi-cuenta", sesion("miembro", "citas"))).toBe(true);
    expect(puedeEntrar("mis-reservas", sesion("miembro", "citas"))).toBe(true);
  });

  it("ninguna pantalla de una modalidad se abre en la otra, con ningún rol", () => {
    const roles = [
      sesion("miembro", "clases"),
      sesion("instructor", "clases"),
      sesion("propietario", "clases", ["*"]),
    ];
    for (const [ruta, politica] of Object.entries(POLITICAS)) {
      if (politica.modalidad === undefined) {
        continue;
      }
      const otra = politica.modalidad === "clases" ? "citas" : "clases";
      for (const s of roles) {
        const enLaOtra = { ...s, modalidad: otra } as Sesion;
        expect(puedeEntrar(ruta, enLaOtra), `${ruta} en ${otra}`).toBe(false);
      }
    }
  });

  it("el rol tampoco salta las funciones del perfil ni el plan", () => {
    // Una regla de portal con función de perfil y plan, como las demás pantallas.
    POLITICAS["portal-de-prueba"] = {
      soloMiembro: true,
      flag: "grupos",
      funcion: "reportes_avanzados" as never,
    };
    try {
      expect(puedeEntrar("portal-de-prueba", sesion("miembro", "clases"))).toBe(
        true,
      );
      expect(
        puedeEntrar(
          "portal-de-prueba",
          sesion("miembro", "clases", [], { flags: { grupos: false } }),
        ),
      ).toBe(false);
      expect(
        puedeEntrar(
          "portal-de-prueba",
          sesion("miembro", "clases", [], { sin: ["reportes_avanzados"] }),
        ),
      ).toBe(false);
      // Y sigue siendo solo del alumno.
      expect(
        puedeEntrar("portal-de-prueba", sesion("propietario", "clases", ["*"])),
      ).toBe(false);
    } finally {
      delete POLITICAS["portal-de-prueba"];
    }
  });

  it("la bitácora es de todo el negocio: no se ofrece a quien solo ve sus sucursales", () => {
    const admin = sesion("admin", "clases", ["auditoria.ver"]);
    expect(puedeEntrar("bitacora", admin)).toBe(true);
    const acotado = {
      ...admin,
      usuario: { ...admin.usuario, todo_el_negocio: false },
    } as unknown as Sesion;
    expect(puedeEntrar("bitacora", acotado)).toBe(false);
  });
});
