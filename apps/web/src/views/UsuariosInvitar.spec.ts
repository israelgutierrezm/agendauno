import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import UsuariosView from "./UsuariosView.vue";

/*
| Invitar a alguien del equipo desde Usuarios: a donde lleva «Invitar a recepción o
| administración» de la configuración inicial. Se elige su rol (no el de dueño ni el de
| cliente) y, con varias sedes, su sucursal; el servidor revisa que se pueda dar.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/lib/filtrosEnUrl", () => ({ useFiltrosEnUrl: () => undefined }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    usuario: { rol: "propietario" },
    terminologia: { sesion: "Clase", miembro: "Alumno", instructor: "Coach" },
    puede: () => true,
  }),
}));

function responder(sucursales: { id: string; nombre: string }[]): void {
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/usuarios")
        ? {
            data: [],
            roles: [
              "propietario",
              "admin",
              "recepcionista",
              "instructor",
              "miembro",
            ],
            meta: { conteos: { equipo: 0, clientes: 0 } },
          }
        : { data: url.endsWith("/sucursales") ? sucursales : [] },
    }),
  );
}

function montar() {
  return mount(UsuariosView, {
    global: {
      plugins: [i18n],
      stubs: {
        teleport: true,
        ModalDialogo: {
          props: ["abierto"],
          template:
            "<div v-if='abierto' data-prueba='dialogo'><slot /><slot name='pie' /></div>",
        },
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  api.post.mockResolvedValue({ data: { data: { activacion: null } } });
});

describe("invitar a alguien del equipo", () => {
  it("elige su rol (sin dueño ni cliente) y lo invita", async () => {
    responder([{ id: "s1", nombre: "Centro" }]);
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="invitar-equipo"]').trigger("click");

    const roles = w
      .get('[data-prueba="rol-invitar"]')
      .findAll("option")
      .map((o) => o.attributes("value"));
    expect(roles).toEqual(["", "admin", "recepcionista", "instructor"]);

    await w.get("#ie-nombre").setValue("Rosa");
    await w.get("#ie-email").setValue("rosa@correo.mx");
    await w.get('[data-prueba="rol-invitar"]').setValue("recepcionista");
    await w.get("#form-invitar-equipo").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/usuarios/invitar", {
      nombre: "Rosa",
      email: "rosa@correo.mx",
      rol: "recepcionista",
      sucursal_id: null,
    });
    expect(toast.exito).toHaveBeenCalled();
    expect(w.find('[data-prueba="dialogo"]').exists()).toBe(false);
  });

  it("con varias sucursales, la suya es obligatoria", async () => {
    responder([
      { id: "s1", nombre: "Centro" },
      { id: "s2", nombre: "Norte" },
    ]);
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="invitar-equipo"]').trigger("click");
    await w.get("#ie-nombre").setValue("Rosa");
    await w.get("#ie-email").setValue("rosa@correo.mx");
    await w.get('[data-prueba="rol-invitar"]').setValue("recepcionista");
    const enviar = w
      .findAll("button")
      .find((b) => b.attributes("form") === "form-invitar-equipo")!;
    expect(enviar.attributes("disabled")).toBeDefined();

    await w.get("#ie-sede").setValue("s2");
    expect(enviar.attributes("disabled")).toBeUndefined();
  });
});
