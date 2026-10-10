import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { plataformaAdmin } from "@/i18n/locales/gestion.es-MX";
import OperacionPlataforma from "./OperacionPlataforma.vue";

const get = vi.hoisted(() => vi.fn());
vi.mock("axios", () => ({ default: { create: () => ({ get }) } }));
vi.mock("@/lib/api", () => ({ mensajeDeError: (e: unknown) => String(e) }));

const operacion = {
  version: "abc1234",
  entorno: "production",
  mantenimiento: false,
  procesos: {
    programador: { estado: "ok", ultimo: "2026-09-28T18:00:00Z" },
    cola: { estado: "atrasado", ultimo: "2026-09-28T17:40:00Z" },
  },
  verificacion: [
    {
      seccion: "Entorno",
      punto: "APP_ENV es production",
      estado: "ok",
      detalle: "",
    },
    {
      seccion: "Respaldos",
      punto: "Copias fuera del servidor",
      estado: "falta",
      detalle: "Ahora: local. Usa RESPALDOS_DISCO=s3 con un bucket externo.",
    },
    {
      seccion: "Procesos",
      punto: "Sin trabajos fallidos en 24 h",
      estado: "aviso",
      detalle: "2 trabajo(s) fallido(s).",
    },
  ],
  respaldos: {
    plataforma: "2026-09-28T03:05:00Z",
    archivos: null,
    simulacro: {
      fecha: "2026-09-27T04:30:00Z",
      ok: false,
      pruebas: [
        {
          respaldo: "respaldos/_plataforma/plataforma-20260927-030500.sql.gz",
          ok: false,
          detalle: "Negocios registrados: Faltan 1.",
          comprobaciones: [
            { nombre: "Tablas esenciales", ok: true, detalle: "" },
            { nombre: "Negocios registrados", ok: false, detalle: "Faltan 1." },
          ],
        },
      ],
    },
  },
  alertas: [
    {
      tipo: "respaldo_fallido",
      clave: "estudio-a",
      estudio: "estudio-a",
      mensaje: "No se pudo respaldar estudio-a.",
      veces: 3,
      primera_en: "2026-09-28T03:15:00Z",
      ultima_en: "2026-09-28T03:20:00Z",
      avisada: false,
    },
  ],
  revisado_en: "2026-09-28T18:01:00Z",
};

function montar() {
  return mount(OperacionPlataforma, {
    props: { apiUrl: "http://api", token: "tk" },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { plataformaAdmin } },
        }),
      ],
    },
  });
}

describe("operación de la plataforma", () => {
  beforeEach(() => {
    get.mockReset();
    get.mockResolvedValue({ data: { data: operacion } });
  });

  it("muestra procesos, lo que falta, el simulacro y las alertas con nombres legibles", async () => {
    const w = montar();
    await flushPromises();

    expect(get).toHaveBeenCalledWith("/api/v1/plataforma/operacion", {
      headers: { Authorization: "Bearer tk" },
    });
    expect(w.find('[data-prueba="franja"]').text()).toContain("abc1234");
    // APP_ENV en palabras, no la llave cruda.
    expect(w.find('[data-prueba="entorno"]').text()).toBe("Producción");
    expect(w.find('[data-prueba="proceso-cola"]').text()).toContain("Atrasado");
    expect(w.find('[data-prueba="resumen-verificacion"]').text()).toBe(
      "1 punto por resolver · 1 aviso",
    );
    expect(w.text()).toContain("Usa RESPALDOS_DISCO=s3");

    const simulacro = w.find('[data-prueba="simulacro"]').text();
    expect(simulacro).toContain("Falló");
    expect(simulacro).toContain("plataforma-20260927-030500.sql.gz");
    // Solo las comprobaciones que fallaron.
    expect(simulacro).toContain("Negocios registrados: Faltan 1.");
    expect(simulacro).not.toContain("Tablas esenciales");

    const alertas = w.find('[data-prueba="alertas"]').text();
    expect(alertas).toContain("Respaldo");
    expect(alertas).not.toContain("respaldo_fallido");
    expect(alertas).toContain("Por enviar");
  });

  it("si no puede leer la operación lo dice, sin mostrar datos viejos ni vacíos", async () => {
    get.mockRejectedValue(new Error("No autorizado"));
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("No autorizado");
    expect(w.find('[data-prueba="franja"]').exists()).toBe(false);
  });
});
