import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { createMemoryHistory, createRouter } from "vue-router";

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
      seccion: "Procesos",
      punto: "Sin trabajos fallidos en 24 h",
      estado: "aviso",
      detalle: "2 trabajo(s) fallido(s).",
    },
    {
      seccion: "Respaldos",
      punto: "Copias fuera del servidor",
      estado: "falta",
      detalle: "Ahora: local. Usa RESPALDOS_DISCO=s3 con un bucket externo.",
    },
  ],
  respaldos: {
    // El peor estado de los puntos de respaldos de la verificación (lo da el API).
    estado: "falta",
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
        {
          respaldo: "respaldos/estudio-a/estudio-a-20260927-031000.sql.gz",
          ok: true,
          detalle: "",
          comprobaciones: [],
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
  alertas_total: 1,
  alertas_por_avisar: 1,
  revisado_en: "2026-09-28T18:01:00Z",
};

// Lo que la paginación necesita (vive en el locale general).
const tabla = {
  anterior: "Anterior",
  siguiente: "Siguiente",
  mostrando: "{desde}–{hasta} de {total}",
  paginacion: "Páginas del listado",
  pagina: "Página {n}",
};

async function montar(ruta = "/plataforma?pestana=operacion") {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: "/plataforma", component: { template: "<div />" } }],
  });
  await router.push(ruta);
  await router.isReady();
  const w = mount(OperacionPlataforma, {
    props: { apiUrl: "http://api", token: "tk" },
    global: {
      plugins: [
        router,
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { plataformaAdmin, tabla } },
        }),
      ],
    },
  });
  await flushPromises();
  return { w, router };
}

describe("operación de la plataforma", () => {
  beforeEach(() => {
    get.mockReset();
    get.mockResolvedValue({ data: { data: operacion } });
  });

  it("muestra procesos, lo que falta, el simulacro y las alertas con nombres legibles", async () => {
    const { w } = await montar();

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

    await w.find('[data-pestana="respaldos"]').trigger("click");
    const simulacro = w.find('[data-prueba="simulacro"]').text();
    expect(simulacro).toContain("Falló");
    expect(simulacro).toContain("plataforma-20260927-030500.sql.gz");
    // Solo las comprobaciones que fallaron.
    expect(simulacro).toContain("Negocios registrados: Faltan 1.");
    expect(simulacro).not.toContain("Tablas esenciales");

    await w.find('[data-pestana="alertas"]').trigger("click");
    const alertas = w.find('[data-prueba="alertas"]').text();
    expect(alertas).toContain("Respaldo");
    expect(alertas).not.toContain("respaldo_fallido");
    expect(alertas).toContain("Por enviar");
  });

  it("resume arriba el estado de todo, con lo que pide atención en color", async () => {
    const { w } = await montar();

    const respaldos = w.find('[data-prueba="franja-respaldos"]');
    // Falta el de los archivos y el simulacro falló.
    expect(respaldos.text()).toContain("Falta un respaldo");
    expect(respaldos.text()).toContain("Simulacro: Falló");
    expect(respaldos.find(".tu-estado").attributes("style")).toContain(
      "var(--error)",
    );
    expect(w.find('[data-prueba="franja-verificacion"]').text()).toContain(
      "1 punto por resolver",
    );
    const alertas = w.find('[data-prueba="franja-alertas"]');
    expect(alertas.text()).toContain("1 alerta");
    expect(alertas.find(".tu-estado").attributes("style")).toContain(
      "var(--aviso)",
    );
  });

  it("la celda de respaldos toma el juicio de la verificación aunque haya fechas", async () => {
    // Hay respaldo de la base y de los archivos (de hace 4 días) y ningún simulacro:
    // el API dice que faltan, y la franja también.
    get.mockResolvedValue({
      data: {
        data: {
          ...operacion,
          respaldos: {
            estado: "falta",
            plataforma: "2026-09-24T03:05:00Z",
            archivos: "2026-09-24T03:10:00Z",
            simulacro: null,
          },
        },
      },
    });
    const { w } = await montar();

    const respaldos = w.find('[data-prueba="franja-respaldos"]');
    expect(respaldos.text()).not.toContain("Falta un respaldo");
    expect(respaldos.text()).toContain("Sin simulacro");
    expect(respaldos.find(".tu-estado").attributes("style")).toContain(
      "var(--error)",
    );

    // Un aviso en los respaldos: tono de aviso.
    get.mockResolvedValue({
      data: {
        data: {
          ...operacion,
          respaldos: { ...operacion.respaldos, estado: "aviso" },
        },
      },
    });
    const conAviso = await montar();
    expect(
      conAviso.w
        .find('[data-prueba="franja-respaldos"] .tu-estado')
        .attributes("style"),
    ).toContain("var(--aviso)");

    // En orden según el API: gris.
    get.mockResolvedValue({
      data: {
        data: {
          ...operacion,
          respaldos: {
            estado: "ok",
            plataforma: "2026-09-28T03:05:00Z",
            archivos: "2026-09-28T03:10:00Z",
            simulacro: null,
          },
        },
      },
    });
    const enOrden = await montar();
    expect(
      enOrden.w
        .find('[data-prueba="franja-respaldos"] .tu-estado')
        .attributes("style"),
    ).toContain("var(--texto-suave)");
  });

  it("deja a la vista lo que falla (primero lo que falta) y pliega lo que está en orden", async () => {
    const { w } = await montar();

    const problemas = w.findAll('[data-prueba="por-resolver"] li');
    expect(problemas).toHaveLength(2);
    expect(problemas[0]!.text()).toContain("Copias fuera del servidor");
    expect(problemas[0]!.text()).toContain("Falta");
    expect(problemas[1]!.text()).toContain("Sin trabajos fallidos en 24 h");
    expect(w.find('[data-prueba="por-resolver"]').text()).not.toContain(
      "APP_ENV es production",
    );

    const enOrden = w.find('[data-prueba="puntos-en-orden"]');
    expect(enOrden.attributes("open")).toBeUndefined();
    expect(enOrden.find("summary").text()).toContain("1");
    expect(enOrden.text()).toContain("APP_ENV es production");
  });

  it("agrupa lo que falla por sección (primero lo que falta) sin repetirla en el renglón", async () => {
    get.mockResolvedValue({
      data: {
        data: {
          ...operacion,
          verificacion: [
            {
              seccion: "Entorno",
              punto: "APP_ENV es production",
              estado: "ok",
              detalle: "",
            },
            {
              seccion: "Entorno",
              punto: "APP_DEBUG apagado",
              estado: "falta",
              detalle:
                "Con APP_DEBUG=true los errores muestran datos internos.",
            },
            {
              seccion: "Procesos",
              punto: "Sin trabajos fallidos en 24 h",
              estado: "aviso",
              detalle: "2 trabajo(s) fallido(s).",
            },
            {
              seccion: "Pagos",
              punto: "Stripe en modo producción",
              estado: "aviso",
              detalle: "Modo de prueba.",
            },
            {
              seccion: "Pagos",
              punto: "Secreto del webhook de Stripe",
              estado: "falta",
              detalle: "Copia el «Signing secret» del endpoint.",
            },
            {
              seccion: "Respaldos",
              punto: "Copias fuera del servidor",
              estado: "falta",
              detalle: "Ahora: local.",
            },
          ],
        },
      },
    });
    const { w } = await montar();

    const secciones = w.findAll('[data-prueba="seccion-por-resolver"]');
    // Primero las secciones con algo que falta (en el orden en que llegan); al final,
    // las que solo tienen avisos.
    expect(secciones.map((s) => s.find("h3").text())).toEqual([
      "Entorno",
      "Pagos",
      "Respaldos",
      "Procesos",
    ]);
    // Dentro de la sección, lo que falta antes que los avisos.
    const pagos = secciones[1]!.findAll("li");
    expect(pagos).toHaveLength(2);
    expect(pagos[0]!.text()).toContain("Secreto del webhook de Stripe");
    expect(pagos[0]!.text()).toContain("Falta");
    expect(pagos[0]!.attributes("style")).toContain("var(--error)");
    expect(pagos[1]!.text()).toContain("Stripe en modo producción");
    expect(pagos[1]!.text()).toContain("Aviso");
    expect(pagos[1]!.attributes("style")).toContain("var(--aviso)");
    // La sección es el título: el renglón ya no la repite.
    for (const s of secciones) {
      const nombre = s.find("h3").text();
      for (const li of s.findAll("li")) {
        expect(li.text()).not.toContain(nombre);
      }
    }
    // Lo que está en orden no se cuela entre lo que falla.
    expect(w.find('[data-prueba="por-resolver"]').text()).not.toContain(
      "APP_ENV es production",
    );
  });

  it("guarda la pestaña en la dirección y abre la que trae", async () => {
    const { w, router } = await montar();

    // La primera es la de siempre y no se escribe.
    expect(w.find('[data-prueba="panel-verificacion"]').exists()).toBe(true);
    expect(router.currentRoute.value.query.operacion_ver).toBeUndefined();

    await w.find('[data-pestana="respaldos"]').trigger("click");
    await flushPromises();
    expect(router.currentRoute.value.query.operacion_ver).toBe("respaldos");
    // La sección del superadmin se queda como estaba.
    expect(router.currentRoute.value.query.pestana).toBe("operacion");
    expect(w.find('[data-prueba="panel-verificacion"]').exists()).toBe(false);
    // Las pruebas que pasaron, plegadas.
    const pasaron = w.find('[data-prueba="pruebas-ok"]');
    expect(pasaron.attributes("open")).toBeUndefined();
    expect(pasaron.text()).toContain("estudio-a-20260927-031000.sql.gz");

    const directo = await montar(
      "/plataforma?pestana=operacion&operacion_ver=alertas",
    );
    const panel = directo.w.find('[data-prueba="panel-alertas"]');
    expect(panel.exists()).toBe(true);
  });

  it("pagina las alertas de 10 en 10", async () => {
    const alertas = Array.from({ length: 12 }, (_, i) => ({
      ...operacion.alertas[0]!,
      clave: `estudio-${i}`,
      estudio: `estudio-${i}`,
      avisada: true,
    }));
    get.mockResolvedValue({
      data: {
        data: {
          ...operacion,
          alertas,
          alertas_total: 12,
          alertas_por_avisar: 0,
        },
      },
    });
    const { w } = await montar(
      "/plataforma?pestana=operacion&operacion_ver=alertas",
    );

    expect(w.findAll('[data-prueba="alertas"] tbody tr')).toHaveLength(10);
    // Llegaron todas: no hay aviso de lista recortada.
    expect(w.find('[data-prueba="alertas-recortadas"]').exists()).toBe(false);
    await w.find('[aria-label="Página 2"]').trigger("click");
    const filas = w.findAll('[data-prueba="alertas"] tbody tr');
    expect(filas).toHaveLength(2);
    expect(filas[0]!.text()).toContain("estudio-10");
  });

  it("cuenta las alertas del historial aunque la lista llegue recortada a 50", async () => {
    // 120 en los 30 días; llegan las 50 más recientes, todas ya enviadas, pero quedan
    // 3 por enviar entre las que no llegaron.
    const alertas = Array.from({ length: 50 }, (_, i) => ({
      ...operacion.alertas[0]!,
      clave: `estudio-${i}`,
      estudio: `estudio-${i}`,
      avisada: true,
    }));
    get.mockResolvedValue({
      data: {
        data: {
          ...operacion,
          alertas,
          alertas_total: 120,
          alertas_por_avisar: 3,
        },
      },
    });
    const { w } = await montar(
      "/plataforma?pestana=operacion&operacion_ver=alertas",
    );

    const franja = w.find('[data-prueba="franja-alertas"]');
    expect(franja.text()).toContain("120 alertas");
    expect(franja.find(".tu-estado").attributes("style")).toContain(
      "var(--aviso)",
    );
    expect(w.find('[data-prueba="alertas-recortadas"]').text()).toBe(
      "Se muestran las 50 más recientes de 120",
    );
  });

  it("el mensaje de una alerta se recorta a dos líneas y queda completo en el title", async () => {
    const { w } = await montar(
      "/plataforma?pestana=operacion&operacion_ver=alertas",
    );

    const mensaje = w.find('[data-prueba="alertas"] tbody tr .op-mensaje');
    expect(mensaje.text()).toBe("No se pudo respaldar estudio-a.");
    expect(mensaje.attributes("title")).toBe("No se pudo respaldar estudio-a.");
  });

  it("si no puede leer la operación lo dice, sin mostrar datos viejos ni vacíos", async () => {
    get.mockRejectedValue(new Error("No autorizado"));
    const { w } = await montar();

    expect(w.text()).toContain("No autorizado");
    expect(w.find('[data-prueba="franja"]').exists()).toBe(false);
  });
});
