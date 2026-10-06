import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { ref } from "vue";

import { i18n } from "@/i18n";
import CuentaLateral from "./CuentaLateral.vue";

/*
| En el teléfono la barra de arriba solo lleva la sucursal: quién entró y sus
| opciones van al pie del menú lateral.
*/

const sesion = vi.hoisted(() => ({
  usuario: {
    nombre: "Constantino Escobar",
    nombre_corto: "Constantino Escobar",
    rol: "propietario",
    genero: "hombre",
    roles_disponibles: [{ clave: "propietario" }, { clave: "miembro" }],
  },
  tieneVariosRoles: true,
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
vi.mock("@/lib/acceso", () => ({ puedeEntrar: () => true }));
vi.mock("@/lib/pantallaCompleta", () => ({
  usePantallaCompleta: () => ({
    activa: ref(false),
    disponible: ref(false),
    pendiente: ref(false),
    alternar: vi.fn(),
  }),
}));
vi.mock("vue-router", () => ({
  RouterLink: { template: "<a><slot /></a>" },
}));

describe("cuenta al pie del menú lateral", () => {
  it("dice quién entró y abre sus opciones", async () => {
    const w = mount(CuentaLateral, { global: { plugins: [i18n] } });
    expect(w.text()).toContain("Constantino Escobar");
    expect(w.text()).toContain("Dueño");
    // Sin pantalla completa en este navegador, no se ofrece.
    expect(w.text()).not.toContain("pantalla completa");

    const boton = (texto: string) =>
      w.findAll("button").find((b) => b.text().includes(texto))!;
    await boton("Cambiar").trigger("click");
    await boton("Apariencia").trigger("click");
    await boton("Cerrar sesión").trigger("click");
    expect(Object.keys(w.emitted())).toEqual(
      expect.arrayContaining(["roles", "apariencia", "salir"]),
    );
  });

  it("con un solo rol no ofrece cambiarlo ni muestra el punto", () => {
    sesion.tieneVariosRoles = false;
    const w = mount(CuentaLateral, { global: { plugins: [i18n] } });
    expect(w.text()).not.toContain("Cambiar");
    expect(w.find(".tu-rol-punto").exists()).toBe(false);
    sesion.tieneVariosRoles = true;
  });

  it("con varios roles, el punto acompaña a «Cambiar de rol»", () => {
    const w = mount(CuentaLateral, { global: { plugins: [i18n] } });
    const boton = w
      .findAll("button")
      .find((b) => b.text().includes("Cambiar"))!;
    expect(boton.find(".tu-rol-punto").exists()).toBe(true);
  });
});
