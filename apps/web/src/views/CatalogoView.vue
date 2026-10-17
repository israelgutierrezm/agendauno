<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import CargadorImagen from "@/components/CargadorImagen.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import SelectorOpciones from "@/components/SelectorOpciones.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import {
  aMinor,
  aTexto,
  dinero as dineroDelPais,
  ejemploPrecio,
  simboloMoneda,
} from "@/lib/formato";
import { useDescartarCambios } from "@/lib/editorFormulario";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

type Politica = "entitlement" | "pago";

interface Oferta {
  id: string;
  nombre: string;
  modalidad: string;
  capacidad: number | null;
  lugares: number;
  precio_clase_minor: number | null;
  politica_reserva: Politica;
  duracion_minutos: number | null;
  // Preparación antes y limpieza después: ocupan la agenda, no se le dicen al cliente.
  preparacion_min?: number;
  limpieza_min?: number;
  // Cada cuántos días suele regresar un cliente (retención, ADR 0159).
  regreso_dias?: number | null;
  // Espacios o equipos que puede usar (2.4); vacío = no requiere.
  recursos?: string[];
  actividad: string | null;
  // Lo que ve quien la elige en línea (página pública y agendar).
  descripcion?: string | null;
  // Paquete: servicios que incluye, en orden (ADR 0063).
  incluye?: string[];
  // Foto que ve quien lo elige (ADR 0066).
  foto_url?: string | null;
  // Para quien llega por primera vez (C03).
  para_principiantes?: boolean;
  requisitos?: string | null;
  que_llevar?: string | null;
  // Retirado del catálogo: ya no se agenda ni se vende; su historial sigue (ADR 0154).
  archivado?: boolean;
}
interface Recurso {
  id: string;
  nombre: string;
  sucursal: string | null;
  activo: boolean;
}

/**
 * Catálogo con el patrón de los listados: indicadores, la tabla de servicios (o
 * clases) y, en diálogos, configurar uno o dar de alta varios en una línea
 * (nombre, duración y precio o lugares; ADR 0088).
 */
const { t } = useI18n();
const toast = useToastStore();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("catalogo.gestionar"));
const esCitas = computed(() => sesion.esCitas === true);

// Cómo se reserva: con citas, «se paga» o «con bono o membresía», y lo que cambia
// en la página para agendar al pasar de uno a otro (ADR 0091).
const OPCIONES_POLITICA = ["pago", "entitlement"] as const;
const textosPolitica = computed(() =>
  esCitas.value ? "catalogo.politicaCitas" : "catalogo.politica",
);
function efectoPolitica(o: Oferta): string {
  if (!esCitas.value || form.value.politica === o.politica_reserva) {
    return "";
  }
  return form.value.politica === "entitlement"
    ? t("catalogo.politicaCitas.dejaDeAparecer", { servicio: o.nombre })
    : t("catalogo.politicaCitas.apareceConPrecio", { servicio: o.nombre });
}

// Todo el catálogo, con lo archivado; lo que se ofrece es lo demás (ADR 0154).
const todas = ref<Oferta[]>([]);
const ofertas = computed(() => todas.value.filter((o) => !o.archivado));
const archivados = computed(() => todas.value.filter((o) => o.archivado));
const verArchivados = ref(false);
const listado = computed(() =>
  verArchivados.value ? archivados.value : ofertas.value,
);
// Sin archivados que ver, de vuelta a lo que se ofrece.
watch(archivados, (lista) => {
  if (lista.length === 0) verArchivados.value = false;
});
// El catálogo de una clínica o un salón junta decenas de servicios: se busca por nombre
// o actividad y se ve por páginas.
const buscarServicio = ref("");
const paginaServicios = ref(1);
const SERVICIOS_POR_PAGINA = 20;
const serviciosVisibles = computed(() => {
  const q = buscarServicio.value.trim().toLowerCase();
  return q === ""
    ? listado.value
    : listado.value.filter(
        (o) =>
          o.nombre.toLowerCase().includes(q) ||
          (o.actividad ?? "").toLowerCase().includes(q),
      );
});
const ultimaPaginaServicios = computed(() =>
  Math.max(1, Math.ceil(serviciosVisibles.value.length / SERVICIOS_POR_PAGINA)),
);
const serviciosEnPagina = computed(() =>
  serviciosVisibles.value.slice(
    (paginaServicios.value - 1) * SERVICIOS_POR_PAGINA,
    paginaServicios.value * SERVICIOS_POR_PAGINA,
  ),
);
watch([buscarServicio, verArchivados], () => {
  paginaServicios.value = 1;
});
const recursos = ref<Recurso[]>([]);
function nombresDe(ids: string[] | undefined): string {
  return (ids ?? [])
    .map((id) => recursos.value.find((r) => r.id === id)?.nombre)
    .filter(Boolean)
    .join(", ");
}
function serviciosDe(ids: string[] | undefined): string {
  return (ids ?? [])
    .map((id) => ofertas.value.find((x) => x.id === id)?.nombre)
    .filter(Boolean)
    .join(", ");
}
// Lo que puede incluir un paquete: los demás servicios que no son paquete.
function incluibles(o: Oferta): Oferta[] {
  return ofertas.value.filter(
    (x) => x.id !== o.id && (x.incluye ?? []).length === 0,
  );
}
// Paquetes que ya lo incluyen (entonces no puede incluir otros).
function paquetesCon(o: Oferta): string {
  return ofertas.value
    .filter((x) => (x.incluye ?? []).includes(o.id))
    .map((x) => x.nombre)
    .join(", ");
}
const cargando = ref(true);
const error = ref<string | null>(null);
// Sin ofertas porque no se pudieron consultar no es «aún no hay servicios».
const errorCarga = ref(false);

// Oferta en edición (una a la vez) + su formulario.
const editandoId = ref<string | null>(null);
const form = ref<{
  nombre: string;
  // Lugares por clase (cupo base): lo que se agende desde ahora; vacío = sin cupo.
  capacidad: string;
  descripcion: string;
  politica: Politica;
  precio: string;
  duracion: string;
  lugares: string;
  preparacion: string;
  limpieza: string;
  recursos: string[];
  incluye: string[];
  principiantes: boolean;
  requisitos: string;
  queLlevar: string;
  regreso: string;
}>({
  nombre: "",
  capacidad: "",
  descripcion: "",
  politica: "entitlement",
  precio: "",
  duracion: "",
  lugares: "0",
  preparacion: "0",
  limpieza: "0",
  recursos: [],
  incluye: [],
  principiantes: false,
  requisitos: "",
  queLlevar: "",
  regreso: "",
});
const guardando = ref(false);
const guardadoId = ref<string | null>(null);
// Lo que no se pudo guardar, dentro de su diálogo (no en la página de atrás).
const errorEdicion = ref<string | null>(null);
// Con cambios sin guardar, cerrar o salir pregunta antes (patrón de formularios).
const configurando = computed({
  get: () => editandoId.value !== null,
  set: (v: boolean) => {
    if (!v) editandoId.value = null;
  },
});
const descartarEdicion = useDescartarCambios({
  abierto: configurando,
  valor: () => form.value,
  guardando,
});
// La foto se sube aparte, al momento.
function fotoCambiada(o: Oferta, url: string | null): void {
  o.foto_url = url;
}

function dinero(minor: number | null): string {
  return dineroDelPais(minor ?? 0, sesion.moneda, sesion.pais);
}
// Junto a los campos de precio: el símbolo de la moneda del negocio.
const simbolo = computed(() => simboloMoneda(sesion.moneda, sesion.pais));

// El precio se lee como en el país del negocio, igual que en el alta; lo que no se
// puede leer sin adivinar se marca y no se guarda.
const precioEdicion = computed(() => aMinor(form.value.precio, sesion.pais));
const precioEdicionIlegible = computed(
  () => form.value.precio.trim() !== "" && precioEdicion.value === null,
);
// Para el pago-al-agendar hace falta un precio mayor a 0.
const precioInvalido = computed(
  () =>
    !precioEdicionIlegible.value &&
    form.value.politica === "pago" &&
    (precioEdicion.value ?? 0) <= 0,
);
// El pago-al-agendar (cita) necesita duración para calcular los huecos.
const duracionInvalida = computed(
  () =>
    form.value.politica === "pago" && (Number(form.value.duracion) || 0) <= 0,
);
// Sin nombre no se guarda; el cupo base, un entero de 1 a 1000 (o vacío).
const nombreInvalido = computed(() => form.value.nombre.trim() === "");
const CUPO_MAXIMO = 1000;
const cupoBaseInvalido = computed(() => {
  const texto = form.value.capacidad.trim();
  if (esCitas.value || texto === "") return false;
  const n = Number(texto);
  return !Number.isInteger(n) || n < 1 || n > CUPO_MAXIMO;
});
const formInvalido = computed(
  () =>
    nombreInvalido.value ||
    cupoBaseInvalido.value ||
    precioEdicionIlegible.value ||
    precioInvalido.value ||
    duracionInvalida.value,
);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  errorCarga.value = true;
  try {
    const { data } = await api.get<{ data: Oferta[] }>(
      `${base.value}/ofertas`,
      { params: { archivados: 1 } },
    );
    todas.value = data.data;
    // Los espacios son secundarios: si fallan, el catálogo sí se muestra.
    errorCarga.value = false;
    // Espacios del negocio (si no hay, la sección no aparece).
    const r = await api.get<{ data: Recurso[] }>(`${base.value}/recursos`);
    recursos.value = r.data.data.filter((x) => x.activo);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function configurar(o: Oferta): void {
  editandoId.value = o.id;
  guardadoId.value = null;
  errorEdicion.value = null;
  form.value = {
    nombre: o.nombre,
    capacidad: o.capacidad !== null ? String(o.capacidad) : "",
    descripcion: o.descripcion ?? "",
    politica: o.politica_reserva,
    precio:
      o.precio_clase_minor !== null
        ? aTexto(o.precio_clase_minor, sesion.pais)
        : "",
    duracion: o.duracion_minutos !== null ? String(o.duracion_minutos) : "",
    lugares: String(o.lugares),
    preparacion: String(o.preparacion_min ?? 0),
    limpieza: String(o.limpieza_min ?? 0),
    recursos: [...(o.recursos ?? [])],
    incluye: [...(o.incluye ?? [])],
    principiantes: o.para_principiantes ?? false,
    requisitos: o.requisitos ?? "",
    queLlevar: o.que_llevar ?? "",
    regreso: o.regreso_dias ? String(o.regreso_dias) : "",
  };
  descartarEdicion.fijar();
}
function cerrar(): void {
  void descartarEdicion.cerrar();
}
// El que se configura (en un diálogo); como lista, para usar el mismo editor.
const editando = computed(
  () => ofertas.value.find((o) => o.id === editandoId.value) ?? null,
);
const editandoLista = computed(() => (editando.value ? [editando.value] : []));

// Una línea bajo el nombre: márgenes, espacios y lo que incluye un paquete.
function extras(o: Oferta): string {
  const partes: string[] = [];
  const margen = (o.preparacion_min ?? 0) + (o.limpieza_min ?? 0);
  if (margen > 0) {
    partes.push(t("margenesServicio.resumen", { n: margen }));
  }
  if ((o.recursos ?? []).length > 0) {
    partes.push(
      t("recursosServicio.resumen", { lista: nombresDe(o.recursos) }),
    );
  }
  if ((o.incluye ?? []).length > 0) {
    partes.push(
      t("perfilPublico.catalogo.incluyeResumen", {
        lista: serviciosDe(o.incluye),
      }),
    );
  }
  return partes.join(" · ");
}

const indicadores = computed<Indicador[]>(() => {
  const total = ofertas.value.length;
  const deTotal = (n: number): string =>
    t("listadosVisual.catalogo.deTotal", { n, total });
  const conPrecio = ofertas.value.filter(
    (o) => (o.precio_clase_minor ?? 0) > 0,
  );
  const conCupo = ofertas.value.filter((o) => (o.capacidad ?? 0) > 0);
  return [
    {
      clave: "servicios",
      etiqueta: esCitas.value
        ? t("listadosVisual.catalogo.kpi.servicios")
        : t("listadosVisual.catalogo.kpi.clases"),
      valor: String(total),
      icono: "etiqueta",
    },
    esCitas.value
      ? {
          clave: "precio",
          etiqueta: t("listadosVisual.catalogo.kpi.precioPromedio"),
          valor:
            conPrecio.length > 0
              ? dinero(
                  Math.round(
                    conPrecio.reduce(
                      (s, o) => s + (o.precio_clase_minor ?? 0),
                      0,
                    ) / conPrecio.length,
                  ),
                )
              : "—",
          icono: "dinero",
        }
      : {
          clave: "cupo",
          etiqueta: t("listadosVisual.catalogo.kpi.cupoPromedio"),
          valor:
            conCupo.length > 0
              ? String(
                  Math.round(
                    conCupo.reduce((s, o) => s + (o.capacidad ?? 0), 0) /
                      conCupo.length,
                  ),
                )
              : "—",
          icono: "personas",
        },
    {
      clave: "foto",
      etiqueta: t("listadosVisual.catalogo.kpi.conFoto"),
      valor: deTotal(ofertas.value.filter((o) => o.foto_url).length),
      icono: "contenido",
    },
    {
      clave: "descripcion",
      etiqueta: t("listadosVisual.catalogo.kpi.conDescripcion"),
      valor: deTotal(ofertas.value.filter((o) => o.descripcion).length),
      icono: "documentos",
    },
  ];
});

// ---- Alta en una línea (varios a la vez) ----
const DURACIONES = [15, 20, 30, 45, 60, 75, 90, 120, 150, 180];
interface FilaAlta {
  nombre: string;
  duracion: number;
  valor: string;
}
const filas = ref<FilaAlta[]>([]);
const dandoAlta = ref(false);
const dandoAltaGuardando = ref(false);
const errorAlta = ref<string | null>(null);
// Tras intentar guardar: se marcan las filas con nombre a las que les falta algo.
const revisarAlta = ref(false);
const descartarAlta = useDescartarCambios({
  abierto: dandoAlta,
  valor: () => filas.value,
  guardando: dandoAltaGuardando,
});
function agregarFila(): void {
  filas.value.push({
    nombre: "",
    duracion: esCitas.value ? 30 : 60,
    valor: esCitas.value ? "" : "10",
  });
}
function abrirAlta(): void {
  filas.value = [];
  agregarFila();
  errorAlta.value = null;
  revisarAlta.value = false;
  descartarAlta.fijar();
  dandoAlta.value = true;
}
function cerrarAlta(): void {
  void descartarAlta.cerrar();
}
// El precio se escribe como en el país del negocio («25.000» en Colombia, «12,50» en
// España); lo que no se puede leer sin adivinar se marca en su campo y no se guarda.
const precioDe = (f: FilaAlta): number | null => aMinor(f.valor, sesion.pais);
const precioIlegible = (f: FilaAlta): boolean =>
  esCitas.value && f.valor.trim() !== "" && precioDe(f) === null;
const errorPrecio = computed(() =>
  t("validacion.precioIlegible", { ejemplo: ejemploPrecio(sesion.pais) }),
);
const conNombre = (f: FilaAlta): boolean => f.nombre.trim() !== "";
// Con citas, un precio mayor a 0; con clases, lugares enteros desde 1.
const valorValido = (f: FilaAlta): boolean =>
  esCitas.value
    ? (precioDe(f) ?? 0) > 0
    : /^\d+$/.test(String(f.valor).trim()) && Number(f.valor) > 0;
// Una fila con nombre a la que le falta el precio (o los lugares) no se guarda
// callada: se marca y no deja guardar hasta completarla o quitarla.
const filaIncompleta = (f: FilaAlta): boolean =>
  conNombre(f) && !valorValido(f) && !precioIlegible(f);
const filasValidas = computed(() =>
  filas.value.filter((f) => conNombre(f) && valorValido(f)),
);
async function guardarAlta(): Promise<void> {
  if (filas.value.some(filaIncompleta)) {
    revisarAlta.value = true;
    errorAlta.value = t("listadosVisual.catalogo.filasIncompletas");
    await nextTick();
    document
      .querySelector<HTMLElement>('#ct-alta [aria-invalid="true"]')
      ?.focus();
    return;
  }
  dandoAltaGuardando.value = true;
  errorAlta.value = null;
  const items = filasValidas.value.length;
  try {
    await api.post(`${base.value}/ofertas/rapidas`, {
      items: filasValidas.value.map((f) =>
        esCitas.value
          ? {
              nombre: f.nombre.trim(),
              duracion_minutos: f.duracion,
              precio_minor: precioDe(f) ?? 0,
            }
          : {
              nombre: f.nombre.trim(),
              duracion_minutos: f.duracion,
              capacidad: Number(f.valor),
            },
      ),
    });
    descartarAlta.fijar();
    dandoAlta.value = false;
    toast.exito(t("listadosVisual.catalogo.altaGuardada", { n: items }, items));
    await cargar();
  } catch (e) {
    errorAlta.value = mensajeDeError(e);
  } finally {
    dandoAltaGuardando.value = false;
  }
}

async function guardar(o: Oferta): Promise<void> {
  if (!puedeGestionar.value || formInvalido.value) {
    return;
  }
  guardando.value = true;
  errorEdicion.value = null;
  try {
    const precioMinor = precioEdicion.value ?? 0;
    const duracion = Number(form.value.duracion) || 0;
    const nombre = form.value.nombre.trim();
    const cupo = form.value.capacidad.trim();
    await api.put(`${base.value}/ofertas/${o.id}`, {
      nombre,
      // Una cita es de una persona: solo las clases llevan cupo base.
      ...(esCitas.value
        ? {}
        : { capacidad: cupo === "" ? null : Number(cupo) }),
      lugares: Number(form.value.lugares) || 0,
      descripcion: form.value.descripcion.trim(),
      // Con pago, mandamos el precio; sin precio lo limpiamos (null).
      precio_clase_minor: precioMinor > 0 ? precioMinor : null,
      politica_reserva: form.value.politica,
      // La duración solo aplica a citas; sin valor la limpiamos (null).
      duracion_minutos: duracion > 0 ? duracion : null,
      preparacion_min: Math.max(0, Number(form.value.preparacion) || 0),
      limpieza_min: Math.max(0, Number(form.value.limpieza) || 0),
      ...(recursos.value.length > 0 ? { recursos: form.value.recursos } : {}),
      // En el orden en que se marcaron.
      incluye: form.value.incluye,
      ...(esCitas.value
        ? {}
        : { para_principiantes: form.value.principiantes }),
      requisitos: form.value.requisitos.trim() || null,
      que_llevar: form.value.queLlevar.trim() || null,
      // Citas: con esto, retención encuentra a quien ya debería haber regresado.
      ...(esCitas.value
        ? {
            regreso_dias:
              Number(form.value.regreso) > 0
                ? Number(form.value.regreso)
                : null,
          }
        : {}),
    });
    guardadoId.value = o.id;
    descartarEdicion.fijar();
    editandoId.value = null;
    toast.exito(t("listadosVisual.catalogo.servicioGuardado", { nombre }));
    await cargar();
  } catch (e) {
    errorEdicion.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

// Retirarlo del catálogo (ADR 0154): lo ya agendado sigue y su historial también.
const archivando = ref(false);
async function archivar(o: Oferta): Promise<void> {
  if (
    !(await confirmar(
      t("listadosVisual.catalogo.confirmarArchivar", { nombre: o.nombre }),
      { peligro: true, aceptar: t("listadosVisual.catalogo.archivar") },
    ))
  ) {
    return;
  }
  archivando.value = true;
  errorEdicion.value = null;
  try {
    const { data } = await api.post<{ meta: { sesiones_futuras: number } }>(
      `${base.value}/ofertas/${o.id}/archivar`,
    );
    const n = data.meta.sesiones_futuras;
    // Los cambios sin guardar se van con él: ya no se edita archivado.
    descartarEdicion.fijar();
    editandoId.value = null;
    toast.exito(
      n > 0
        ? t(
            "listadosVisual.catalogo.archivadoConFechas",
            { nombre: o.nombre, n },
            n,
          )
        : t("listadosVisual.catalogo.archivadoListo", { nombre: o.nombre }),
    );
    await cargar();
  } catch (e) {
    // Lo que lo impide (un paquete, una clase que se repite), dentro del diálogo.
    errorEdicion.value = mensajeDeError(e);
  } finally {
    archivando.value = false;
  }
}

const restaurandoId = ref<string | null>(null);
async function restaurar(o: Oferta): Promise<void> {
  restaurandoId.value = o.id;
  try {
    await api.post(`${base.value}/ofertas/${o.id}/restaurar`);
    toast.exito(t("listadosVisual.catalogo.restaurado", { nombre: o.nombre }));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    restaurandoId.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('catalogo.titulo')"
      :total="ofertas.length"
      :subtitulo="
        esCitas
          ? $t('listadosVisual.catalogo.subtituloCitas')
          : $t('listadosVisual.catalogo.subtitulo')
      "
    >
      <template #acciones>
        <button
          v-if="puedeGestionar"
          type="button"
          class="tu-btn tu-btn-primario tu-btn-crear"
          data-prueba="nuevo-servicio"
          @click="abrirAlta"
        >
          {{
            esCitas
              ? $t("listadosVisual.catalogo.nuevoServicio")
              : $t("listadosVisual.catalogo.nuevaClase")
          }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
      <template v-if="errorCarga" #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-contorno tu-tono-peligro tu-btn-chico"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>

    <template v-if="!cargando && !errorCarga">
      <EstadoVacio
        v-if="todas.length === 0"
        class="mt-8"
        icono="etiqueta"
        :titulo="esCitas ? $t('catalogo.vacio') : $t('catalogo.vacioClases')"
      />

      <template v-else>
        <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

        <div class="tu-card mt-5 overflow-x-auto">
          <div
            v-if="
              ofertas.length > SERVICIOS_POR_PAGINA / 2 || archivados.length > 0
            "
            class="tu-filtros"
          >
            <div
              v-if="archivados.length > 0"
              class="tu-segmentado"
              role="group"
              data-prueba="filtro-archivados"
            >
              <button
                type="button"
                :aria-pressed="!verArchivados"
                @click="verArchivados = false"
              >
                {{
                  $t("listadosVisual.catalogo.enCatalogo", {
                    n: ofertas.length,
                  })
                }}
              </button>
              <button
                type="button"
                :aria-pressed="verArchivados"
                data-prueba="ver-archivados"
                @click="verArchivados = true"
              >
                {{
                  $t("listadosVisual.catalogo.archivados", {
                    n: archivados.length,
                  })
                }}
              </button>
            </div>
            <label class="tu-buscar">
              <IconoNav nombre="buscar" :tam="16" />
              <input
                v-model="buscarServicio"
                type="search"
                class="tu-input"
                :placeholder="$t('listadosVisual.catalogo.buscar')"
                :aria-label="$t('listadosVisual.catalogo.buscar')"
                data-prueba="buscar-servicio"
              />
            </label>
          </div>
          <table class="ct-tabla">
            <thead>
              <tr>
                <th>{{ $t("listadosVisual.catalogo.servicio") }}</th>
                <th class="hidden sm:table-cell">
                  {{ $t("listadosVisual.catalogo.duracion") }}
                </th>
                <th class="text-right">
                  {{
                    esCitas
                      ? $t("listadosVisual.catalogo.precio")
                      : $t("listadosVisual.catalogo.cupo")
                  }}
                </th>
                <th class="hidden md:table-cell">
                  {{ $t("listadosVisual.catalogo.reserva") }}
                </th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="o in serviciosEnPagina"
                :key="o.id"
                data-prueba="servicio"
              >
                <td>
                  <div class="flex items-center gap-3">
                    <img
                      v-if="o.foto_url"
                      :src="o.foto_url"
                      alt=""
                      class="ct-foto"
                    />
                    <span v-else class="ct-foto ct-sin-foto" aria-hidden="true">
                      <IconoNav nombre="etiqueta" :tam="16" />
                    </span>
                    <div class="min-w-0">
                      <p class="font-medium">
                        {{ o.nombre }}
                        <span
                          v-if="guardadoId === o.id"
                          class="ml-2 text-sm"
                          :style="{ color: 'var(--exito)' }"
                          >{{
                            esCitas
                              ? $t("catalogo.guardado")
                              : $t("catalogo.guardadoClases")
                          }}</span
                        >
                      </p>
                      <p class="ct-sub">
                        <template v-if="o.descripcion">{{
                          o.descripcion
                        }}</template>
                        <template v-else>{{
                          $t("listadosVisual.catalogo.sinDescripcion")
                        }}</template>
                      </p>
                      <p v-if="extras(o)" class="ct-sub">{{ extras(o) }}</p>
                    </div>
                  </div>
                </td>
                <td class="hidden sm:table-cell whitespace-nowrap">
                  {{
                    o.duracion_minutos
                      ? $t("listadosVisual.catalogo.minutos", {
                          n: o.duracion_minutos,
                        })
                      : "—"
                  }}
                </td>
                <td class="text-right whitespace-nowrap tabular-nums">
                  <template v-if="o.politica_reserva === 'pago'">{{
                    dinero(o.precio_clase_minor)
                  }}</template>
                  <template v-else-if="o.capacidad">{{
                    $t("listadosVisual.catalogo.lugares", { n: o.capacidad })
                  }}</template>
                  <template v-else>—</template>
                </td>
                <td class="hidden md:table-cell whitespace-nowrap">
                  <span
                    class="tu-pildora"
                    :style="{
                      '--tono':
                        o.politica_reserva === 'pago'
                          ? 'var(--primario)'
                          : 'var(--texto-suave)',
                    }"
                    >{{
                      o.politica_reserva === "pago"
                        ? $t(
                            esCitas
                              ? "catalogo.badgePago"
                              : "catalogo.badgePagoClases",
                          )
                        : esCitas
                          ? $t("catalogo.badgeEntitlementCitas")
                          : $t("catalogo.badgeEntitlement")
                    }}</span
                  >
                </td>
                <td class="text-right whitespace-nowrap">
                  <template v-if="puedeGestionar">
                    <button
                      v-if="o.archivado"
                      class="tu-enlace text-sm"
                      type="button"
                      data-prueba="restaurar"
                      :disabled="restaurandoId === o.id"
                      @click="restaurar(o)"
                    >
                      {{ $t("listadosVisual.catalogo.restaurar") }}
                    </button>
                    <button
                      v-else
                      class="tu-enlace text-sm"
                      type="button"
                      data-prueba="configurar"
                      @click="configurar(o)"
                    >
                      {{ $t("catalogo.configurar") }}
                    </button>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="serviciosVisibles.length === 0" class="tu-sin-resultados">
            {{ $t("listadosVisual.catalogo.sinResultados") }}
          </p>
          <PaginacionListado
            v-if="ultimaPaginaServicios > 1"
            :page="paginaServicios"
            :ultima-pagina="ultimaPaginaServicios"
            :total="serviciosVisibles.length"
            :per-page="SERVICIOS_POR_PAGINA"
            @ir="paginaServicios = $event"
          />
        </div>
      </template>
    </template>

    <!-- Configurar un servicio -->
    <ModalDialogo
      :abierto="editando !== null"
      :titulo="editando?.nombre ?? ''"
      icono="etiqueta"
      :ocupado="guardando"
      @cerrar="cerrar"
    >
      <AvisoTono
        v-if="errorEdicion"
        tono="peligro"
        class="mb-4"
        role="alert"
        data-prueba="error-edicion"
      >
        {{ errorEdicion }}
      </AvisoTono>
      <template v-for="o in editandoLista" :key="o.id">
        <div class="mb-4 grid gap-3 sm:grid-cols-2">
          <div :class="{ 'sm:col-span-2': esCitas }">
            <label class="tu-label" :for="`c-nombre-${o.id}`">{{
              $t("listadosVisual.catalogo.nombre")
            }}</label>
            <input
              :id="`c-nombre-${o.id}`"
              v-model="form.nombre"
              class="tu-input"
              maxlength="255"
              :aria-invalid="nombreInvalido"
              data-prueba="nombre-servicio"
            />
            <span v-if="nombreInvalido" class="tu-error-campo">{{
              $t("listadosVisual.catalogo.nombreRequerido")
            }}</span>
          </div>
          <div v-if="!esCitas">
            <label class="tu-label" :for="`c-cupo-${o.id}`">{{
              $t("listadosVisual.catalogo.cupoBase")
            }}</label>
            <input
              :id="`c-cupo-${o.id}`"
              v-model="form.capacidad"
              class="tu-input"
              inputmode="numeric"
              :aria-invalid="cupoBaseInvalido"
              data-prueba="cupo-base"
            />
            <span v-if="cupoBaseInvalido" class="tu-error-campo">{{
              $t("listadosVisual.catalogo.cupoBaseInvalido", {
                max: CUPO_MAXIMO,
              })
            }}</span>
            <span v-else class="tu-hint">{{
              $t("listadosVisual.catalogo.cupoBaseAyuda")
            }}</span>
          </div>
        </div>
        <div class="mb-4" data-prueba="foto-servicio">
          <span class="tu-label">{{ $t("perfilPublico.catalogo.foto") }}</span>
          <CargadorImagen
            :url="o.foto_url ?? null"
            :ruta="`ofertas/${o.id}/foto`"
            campo="foto"
            clave="foto_url"
            proporcion="4 / 3"
            :arrastra="$t('perfilPublico.catalogo.fotoArrastra')"
            :ayuda="$t('perfilPublico.catalogo.fotoAyuda')"
            :quitar-texto="$t('perfilPublico.catalogo.fotoQuitar')"
            :puede-gestionar="puedeGestionar"
            @update:url="fotoCambiada(o, $event)"
          />
        </div>
        <label class="tu-label" :for="`c-desc-${o.id}`">{{
          $t("perfilPublico.catalogo.descripcion")
        }}</label>
        <textarea
          :id="`c-desc-${o.id}`"
          v-model="form.descripcion"
          class="tu-input mb-4"
          rows="3"
          maxlength="600"
          :placeholder="$t('perfilPublico.catalogo.descripcionPh')"
        />
        <!-- Para quien llega por primera vez (C03). -->
        <label
          v-if="!esCitas"
          class="mb-3 flex items-start gap-2 text-sm"
          data-prueba="para-principiantes"
        >
          <input v-model="form.principiantes" type="checkbox" class="mt-1" />
          <span>
            {{ $t("perfilPublico.catalogo.principiantes") }}
            <span
              class="block text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("perfilPublico.catalogo.principiantesAyuda") }}</span
            >
          </span>
        </label>
        <div class="mb-4 grid gap-3 sm:grid-cols-2">
          <div>
            <label class="tu-label" :for="`c-req-${o.id}`">{{
              $t("perfilPublico.catalogo.requisitos")
            }}</label>
            <input
              :id="`c-req-${o.id}`"
              v-model="form.requisitos"
              class="tu-input"
              maxlength="300"
              :placeholder="$t('perfilPublico.catalogo.requisitosPh')"
            />
          </div>
          <div>
            <label class="tu-label" :for="`c-llevar-${o.id}`">{{
              $t("perfilPublico.catalogo.queLlevar")
            }}</label>
            <input
              :id="`c-llevar-${o.id}`"
              v-model="form.queLlevar"
              class="tu-input"
              maxlength="300"
              :placeholder="$t('perfilPublico.catalogo.queLlevarPh')"
            />
          </div>
        </div>
        <label class="tu-label">{{ $t("catalogo.politica.etiqueta") }}</label>
        <div class="mt-1 space-y-2" data-prueba="politica-reserva">
          <label
            v-for="opcion in OPCIONES_POLITICA"
            :key="opcion"
            class="ct-opcion"
            :class="{ 'ct-opcion-activa': form.politica === opcion }"
          >
            <input
              v-model="form.politica"
              type="radio"
              :value="opcion"
              class="mt-1"
              :data-prueba="`politica-${opcion}`"
            />
            <span>
              <span class="font-medium">{{
                $t(`${textosPolitica}.${opcion}`)
              }}</span>
              <span
                class="block text-sm"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t(`${textosPolitica}.${opcion}Ayuda`) }}</span
              >
            </span>
          </label>
        </div>
        <!-- Lo que cambia en su página al guardar (ADR 0091). -->
        <AvisoTono
          v-if="efectoPolitica(o)"
          tono="aviso"
          class="mt-2"
          data-prueba="efecto-politica"
        >
          {{ efectoPolitica(o) }}
        </AvisoTono>

        <div class="mt-3 grid sm:grid-cols-2 gap-3">
          <div>
            <label class="tu-label" :for="`precio-${o.id}`">{{
              $t(esCitas ? "catalogo.precio" : "catalogo.precioClases")
            }}</label>
            <div class="flex items-center gap-2">
              <span
                :style="{ color: 'var(--texto-suave)' }"
                data-prueba="simbolo-precio"
                >{{ simbolo }}</span
              >
              <input
                :id="`precio-${o.id}`"
                v-model="form.precio"
                inputmode="decimal"
                class="tu-input"
                data-prueba="precio-edicion"
                :aria-invalid="
                  precioEdicionIlegible || precioInvalido ? 'true' : undefined
                "
                :style="
                  precioEdicionIlegible || precioInvalido
                    ? { borderColor: 'var(--error)' }
                    : {}
                "
              />
              <span class="text-sm" :style="{ color: 'var(--texto-suave)' }">{{
                sesion.moneda
              }}</span>
            </div>
            <span
              v-if="precioEdicionIlegible"
              class="tu-hint"
              style="color: var(--error)"
              data-prueba="precio-edicion-ilegible"
              >{{ errorPrecio }}</span
            >
            <span
              v-else-if="precioInvalido"
              class="tu-hint"
              style="color: var(--error)"
              >{{ $t("catalogo.precioReq") }}</span
            >
          </div>
          <div v-if="form.politica === 'pago' || esCitas">
            <label class="tu-label" :for="`duracion-${o.id}`">{{
              $t("catalogo.duracion")
            }}</label>
            <input
              :id="`duracion-${o.id}`"
              v-model="form.duracion"
              type="number"
              min="5"
              step="5"
              class="tu-input"
              :style="duracionInvalida ? { borderColor: 'var(--error)' } : {}"
            />
            <span
              v-if="duracionInvalida"
              class="tu-hint"
              style="color: var(--error)"
              >{{ $t("catalogo.duracionReq") }}</span
            >
            <span v-else class="tu-hint">{{
              esCitas
                ? $t("catalogo.duracionAyuda")
                : $t("catalogo.duracionAyudaClases")
            }}</span>
          </div>
          <!-- Lugares numerados (B06): solo en clases (ADR 0131). -->
          <div v-if="!esCitas">
            <label class="tu-label" :for="`lugares-${o.id}`">{{
              $t("catalogo.lugares")
            }}</label>
            <input
              :id="`lugares-${o.id}`"
              v-model="form.lugares"
              type="number"
              min="0"
              step="1"
              class="tu-input"
            />
            <span class="tu-hint">{{ $t("catalogo.lugaresAyuda") }}</span>
          </div>
          <!-- Preparación y limpieza (2.3): ocupan la agenda, no la cita. -->
          <div>
            <label class="tu-label" :for="`preparacion-${o.id}`">{{
              $t("margenesServicio.preparacion")
            }}</label>
            <input
              :id="`preparacion-${o.id}`"
              v-model="form.preparacion"
              type="number"
              min="0"
              max="240"
              step="5"
              class="tu-input"
            />
          </div>
          <div>
            <label class="tu-label" :for="`limpieza-${o.id}`">{{
              $t("margenesServicio.limpieza")
            }}</label>
            <input
              :id="`limpieza-${o.id}`"
              v-model="form.limpieza"
              type="number"
              min="0"
              max="240"
              step="5"
              class="tu-input"
            />
            <span class="tu-hint">{{ $t("margenesServicio.ayuda") }}</span>
          </div>
          <!-- Cada cuántos días suele regresar (retención, ADR 0159): solo citas. -->
          <div v-if="esCitas">
            <label class="tu-label" :for="`regreso-${o.id}`">{{
              $t("catalogo.regreso")
            }}</label>
            <input
              :id="`regreso-${o.id}`"
              v-model="form.regreso"
              type="number"
              min="1"
              max="730"
              class="tu-input"
              data-prueba="regreso-servicio"
            />
            <span class="tu-hint">{{ $t("catalogo.regresoAyuda") }}</span>
          </div>
          <!-- Espacios que usa (2.4): solo si el negocio tiene espacios. -->
          <fieldset v-if="recursos.length > 0" class="sm:col-span-2">
            <legend class="tu-label">
              {{ $t("recursosServicio.titulo") }}
            </legend>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
              <label
                v-for="r in recursos"
                :key="r.id"
                class="flex items-center gap-2"
              >
                <input v-model="form.recursos" type="checkbox" :value="r.id" />
                {{ r.nombre
                }}<span
                  v-if="r.sucursal"
                  :style="{ color: 'var(--texto-suave)' }"
                  >· {{ r.sucursal }}</span
                >
              </label>
            </div>
            <span class="tu-hint">{{ $t("recursosServicio.ayuda") }}</span>
          </fieldset>
          <!-- Paquete (ADR 0063): servicios que incluye, sin anidar. -->
          <fieldset
            v-if="paquetesCon(o) || incluibles(o).length > 0"
            class="sm:col-span-2"
            data-prueba="incluye"
          >
            <legend class="tu-label">
              {{ $t("perfilPublico.catalogo.incluye") }}
            </legend>
            <p v-if="paquetesCon(o)" class="tu-hint">
              {{
                $t("perfilPublico.catalogo.incluidoEn", {
                  lista: paquetesCon(o),
                })
              }}
            </p>
            <template v-else>
              <SelectorOpciones
                v-model="form.incluye"
                :opciones="incluibles(o)"
                class="mt-1"
              />
              <span class="tu-hint">{{
                $t("perfilPublico.catalogo.incluyeAyuda")
              }}</span>
            </template>
          </fieldset>
        </div>
      </template>
      <template #pie>
        <button
          v-if="editando"
          class="tu-btn tu-btn-contorno tu-tono-peligro mr-auto"
          type="button"
          data-prueba="archivar"
          :disabled="guardando || archivando"
          @click="archivar(editando)"
        >
          {{ $t("listadosVisual.catalogo.archivar") }}
        </button>
        <button
          class="tu-btn tu-btn-fantasma"
          type="button"
          :disabled="guardando || archivando"
          @click="cerrar"
        >
          {{ $t("catalogo.cerrar") }}
        </button>
        <button
          class="tu-btn tu-btn-primario"
          type="button"
          :disabled="guardando || archivando || formInvalido"
          @click="editando && guardar(editando)"
        >
          {{ guardando ? $t("catalogo.guardando") : $t("catalogo.guardar") }}
        </button>
      </template>
    </ModalDialogo>

    <!-- Alta en una línea: nombre, duración y precio (o lugares) -->
    <ModalDialogo
      :abierto="dandoAlta"
      :titulo="
        esCitas
          ? $t('listadosVisual.catalogo.nuevoServicio')
          : $t('listadosVisual.catalogo.nuevaClase')
      "
      icono="etiqueta"
      :ocupado="dandoAltaGuardando"
      @cerrar="cerrarAlta"
    >
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("listadosVisual.catalogo.altaAyuda") }}
      </p>
      <AvisoTono
        v-if="errorAlta"
        tono="peligro"
        class="mt-4"
        role="alert"
        data-prueba="error-alta"
      >
        {{ errorAlta }}
      </AvisoTono>
      <div id="ct-alta" class="ct-filas mt-4" data-prueba="filas-alta">
        <div class="ct-fila ct-fila-cabeza" aria-hidden="true">
          <span>{{ $t("listadosVisual.catalogo.nombre") }}</span>
          <span>{{ $t("listadosVisual.catalogo.duracion") }}</span>
          <span>{{
            esCitas
              ? $t("listadosVisual.catalogo.precio")
              : $t("listadosVisual.catalogo.cupo")
          }}</span>
          <span></span>
        </div>
        <div v-for="(f, i) in filas" :key="i" class="ct-fila">
          <input
            v-model="f.nombre"
            class="tu-input"
            :aria-label="$t('listadosVisual.catalogo.nombre')"
            maxlength="120"
          />
          <label class="tu-select-icono w-full">
            <IconoNav nombre="reloj" :tam="16" />
            <select
              v-model.number="f.duracion"
              class="tu-input w-full"
              :aria-label="$t('listadosVisual.catalogo.duracion')"
            >
              <option v-for="d in DURACIONES" :key="d" :value="d">
                {{ $t("listadosVisual.catalogo.minutos", { n: d }) }}
              </option>
            </select>
          </label>
          <div
            v-if="esCitas"
            class="ct-precio"
            :style="{ '--ancho-simbolo': `${simbolo.length}ch` }"
          >
            <span aria-hidden="true">{{ simbolo }}</span>
            <input
              v-model="f.valor"
              class="tu-input"
              inputmode="decimal"
              :aria-label="$t('listadosVisual.catalogo.precio')"
              :aria-invalid="
                precioIlegible(f) || (revisarAlta && filaIncompleta(f))
                  ? 'true'
                  : undefined
              "
              :style="
                precioIlegible(f) || (revisarAlta && filaIncompleta(f))
                  ? { borderColor: 'var(--error)' }
                  : {}
              "
              data-prueba="precio-alta"
            />
          </div>
          <input
            v-else
            v-model="f.valor"
            class="tu-input"
            type="number"
            min="1"
            step="1"
            :aria-label="$t('listadosVisual.catalogo.cupo')"
            :aria-invalid="
              revisarAlta && filaIncompleta(f) ? 'true' : undefined
            "
            :style="
              revisarAlta && filaIncompleta(f)
                ? { borderColor: 'var(--error)' }
                : {}
            "
          />
          <button
            type="button"
            class="tu-icono-btn"
            :aria-label="$t('listadosVisual.catalogo.quitar')"
            :disabled="filas.length === 1"
            @click="filas.splice(i, 1)"
          >
            <IconoNav nombre="cerrar" :tam="16" />
          </button>
          <p
            v-if="precioIlegible(f)"
            class="col-span-full text-xs"
            style="color: var(--error)"
            data-prueba="precio-ilegible"
          >
            {{ errorPrecio }}
          </p>
          <p
            v-else-if="revisarAlta && filaIncompleta(f)"
            class="col-span-full text-xs"
            style="color: var(--error)"
            data-prueba="fila-incompleta"
          >
            {{
              esCitas
                ? $t("listadosVisual.catalogo.faltaPrecio")
                : $t("listadosVisual.catalogo.cupoInvalido")
            }}
          </p>
        </div>
      </div>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma mt-3"
        @click="agregarFila"
      >
        <IconoNav nombre="mas" :tam="16" />
        {{ $t("listadosVisual.catalogo.otraFila") }}
      </button>
      <template #pie>
        <button
          class="tu-btn tu-btn-fantasma"
          type="button"
          :disabled="dandoAltaGuardando"
          @click="cerrarAlta"
        >
          {{ $t("catalogo.cerrar") }}
        </button>
        <button
          class="tu-btn tu-btn-primario"
          type="button"
          data-prueba="guardar-alta"
          :disabled="
            dandoAltaGuardando ||
            !filas.some(conNombre) ||
            filas.some(precioIlegible)
          "
          @click="guardarAlta"
        >
          {{ $t("catalogo.guardar") }}
        </button>
      </template>
    </ModalDialogo>
  </section>
</template>

<style scoped>
.ct-tabla {
  width: 100%;
  font-size: 0.9rem;
  border-collapse: collapse;
}
.ct-tabla th {
  padding: 0.7rem 1rem;
  border-bottom: 1px solid var(--borde);
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-weight: 500;
  text-align: left;
}
.ct-tabla th.text-right {
  text-align: right;
}
.ct-tabla td {
  padding: 0.8rem 1rem;
  border-top: 1px solid var(--borde);
  vertical-align: middle;
}
.ct-tabla tbody tr:first-child td {
  border-top: 0;
}
.ct-foto {
  width: 2.75rem;
  height: 2.75rem;
  flex-shrink: 0;
  border-radius: 0.5rem;
  object-fit: cover;
}
.ct-sin-foto {
  display: inline-grid;
  place-items: center;
  border: 1px solid var(--borde);
  color: var(--texto-suave);
}
.ct-sub {
  max-width: 36rem;
  overflow: hidden;
  color: var(--texto-suave);
  font-size: 0.8rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ct-opcion {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton);
  cursor: pointer;
}
.ct-opcion-activa {
  border-color: var(--primario);
}
.ct-filas {
  display: grid;
  gap: 0.6rem;
}
.ct-fila {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 8.5rem 8rem auto;
  gap: 0.6rem;
  align-items: center;
}
.ct-fila-cabeza {
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.ct-precio {
  position: relative;
}
.ct-precio > span {
  position: absolute;
  top: 50%;
  left: 0.75rem;
  transform: translateY(-50%);
  color: var(--texto-suave);
}
/* El símbolo de la moneda del negocio: «$», «€», «S/» o un código («USD»). */
.ct-precio > input {
  padding-left: calc(1.05rem + var(--ancho-simbolo, 1ch));
}
</style>
