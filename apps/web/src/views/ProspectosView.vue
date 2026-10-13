<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import BarraListado from "@/components/BarraListado.vue";
import BotonWhatsApp from "@/components/BotonWhatsApp.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import {
  abierto,
  MOTIVOS_CIERRE,
  textoCampana,
  whatsappDe,
  type EstadoProspecto,
  type EventoProspecto,
  type MotivoCierre,
  type Prospecto,
} from "@/lib/captacion";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Prospectos (ADR 0117): quien todavía no es cliente y dejó sus datos, aparte de
 * Miembros. Primero lo que pide atención (nuevos, sin atender y seguimientos vencidos);
 * en el detalle, contactarlo, darle seguimiento, descartarlo con un motivo o darle
 * acceso (su ficha en Miembros y la invitación a su cuenta). No reserva hasta que es
 * miembro.
 *
 * Responsable (ADR 0138): se elige con un buscador entre el equipo que atiende
 * prospectos, o «Asignármelo», y se cambia o se quita cuando se quiera. Escribirle o
 * llamarle queda en su historia; si nadie lo atiende a tiempo, se marca «sin atender».
 * La historia distingue cada cosa con su ícono: notas, contactos, cambios de estado,
 * responsable, seguimiento.
 *
 * Filtros como en Miembros (buscador, «Filtros» y lo aplicado): estado, responsable,
 * sin atender, seguimiento vencido, cuándo llegó, origen y formulario, campaña y sede.
 * La lista va por páginas.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("prospectos.gestionar"));
const puedeConvertir = computed(() => sesion.puede("prospectos.convertir"));
const puedeEliminar = computed(() => sesion.puede("prospectos.eliminar"));

const lista = ref<Prospecto[]>([]);
const resumen = ref({
  nuevos: 0,
  seguimientos_vencidos: 0,
  abiertos: 0,
  sin_atender: 0,
});
const cargando = ref(true);
const error = ref<string | null>(null);
// El inicio abre las solicitudes nuevas ya filtradas (`?estado=nuevo`; M08).
const estadoInicial = useRoute()?.query.estado;
// `estado` vacío: los abiertos (lo de siempre).
const filtros = reactive({
  estado:
    typeof estadoInicial === "string" && estadoInicial !== "abiertos"
      ? estadoInicial
      : "",
  responsable: "",
  // `sin_atender`: su responsable no lo atendió a tiempo.
  atencion: "",
  // `vencido`: su próximo seguimiento ya pasó.
  seguimiento: "",
  // `hoy`, `7` o `30` días.
  periodo: "",
  origen: "",
  formulario: "",
  campana: "",
  sucursal: "",
  q: "",
});
const pagina = ref(1);
const paginacion = ref<{
  page: number;
  per_page: number;
  total: number;
  ultima_pagina: number;
} | null>(null);
const POR_PAGINA = 25;
const ESTADOS: EstadoProspecto[] = [
  "nuevo",
  "en_seguimiento",
  "aprobado",
  "invitacion_enviada",
  "convertido",
  "descartado",
];

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: Prospecto[];
      meta: {
        resumen: typeof resumen.value;
        page?: number;
        per_page?: number;
        total?: number;
        ultima_pagina?: number;
      };
    }>(`${base.value}/prospectos`, {
      params: {
        estado: filtros.estado || "abiertos",
        responsable: filtros.responsable || undefined,
        atencion: filtros.atencion || undefined,
        seguimiento: filtros.seguimiento || undefined,
        periodo: filtros.periodo || undefined,
        origen: filtros.origen || undefined,
        formulario_id: filtros.formulario || undefined,
        campana: filtros.campana || undefined,
        sucursal_id: filtros.sucursal || undefined,
        q: filtros.q.trim() || undefined,
        page: pagina.value,
        per_page: POR_PAGINA,
      },
    });
    lista.value = data.data;
    resumen.value = data.meta.resumen;
    paginacion.value =
      data.meta.ultima_pagina !== undefined
        ? {
            page: data.meta.page ?? 1,
            per_page: data.meta.per_page ?? POR_PAGINA,
            total: data.meta.total ?? 0,
            ultima_pagina: data.meta.ultima_pagina,
          }
        : null;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

let espera: number | undefined;
watch(
  () => filtros.q,
  () => {
    window.clearTimeout(espera);
    espera = window.setTimeout(() => {
      pagina.value = 1;
      void cargar();
    }, 300);
  },
);
// Otro filtro vuelve a la primera página.
watch(
  () => [
    filtros.estado,
    filtros.responsable,
    filtros.atencion,
    filtros.seguimiento,
    filtros.periodo,
    filtros.origen,
    filtros.formulario,
    filtros.campana,
    filtros.sucursal,
  ],
  () => {
    pagina.value = 1;
    void cargar();
  },
);
function irAPagina(n: number): void {
  pagina.value = n;
  void cargar();
}
onMounted(() => {
  void cargar();
  void cargarResponsables();
  void cargarOpcionesFiltro();
});

// ---------------------------------------------------------------- filtros
// Los formularios de captación y las sedes, para filtrar por ellos (solo si hay más
// de uno).
const formularios = ref<{ id: string; nombre: string }[]>([]);
const sucursales = ref<{ id: string; nombre: string }[]>([]);
async function cargarOpcionesFiltro(): Promise<void> {
  const [f, s] = await Promise.allSettled([
    api.get<{ data: { id: string; nombre: string }[] }>(
      `${base.value}/captacion/formularios`,
    ),
    api.get<{ data: { id: string; nombre: string }[] }>(
      `${base.value}/sucursales`,
    ),
  ]);
  const lista = (r: typeof f) =>
    r.status === "fulfilled" && Array.isArray(r.value?.data?.data)
      ? r.value.data.data.map((x) => ({ id: x.id, nombre: x.nombre }))
      : [];
  formularios.value = lista(f);
  sucursales.value = lista(s);
}
const filtrosDef = computed(() => [
  {
    clave: "estado",
    etiqueta: t("captacion.prospectos.filtros.estado"),
    opciones: [
      { valor: "todos", texto: t("captacion.prospectos.filtros.todos") },
      ...ESTADOS.map((e) => ({
        valor: e,
        texto: t(`captacion.prospectos.estados.${e}`),
      })),
    ],
  },
  {
    clave: "responsable",
    etiqueta: t("captacion.prospectos.filtros.responsable"),
    opciones: [
      { valor: "yo", texto: t("captacion.prospectos.filtros.yo") },
      { valor: "sin", texto: t("captacion.prospectos.filtros.sin") },
      ...responsables.value.map((r) => ({ valor: r.id, texto: r.nombre })),
    ],
  },
  {
    clave: "atencion",
    etiqueta: t("captacion.prospectos.filtros.sinAtender"),
    tipo: "booleano" as const,
  },
  {
    clave: "seguimiento",
    etiqueta: t("captacion.prospectos.filtros.seguimientoVencido"),
    tipo: "booleano" as const,
  },
  {
    clave: "periodo",
    etiqueta: t("captacion.prospectos.filtros.llego"),
    opciones: [
      { valor: "hoy", texto: t("captacion.prospectos.filtros.hoy") },
      { valor: "7", texto: t("captacion.prospectos.filtros.ultimos7") },
      { valor: "30", texto: t("captacion.prospectos.filtros.ultimos30") },
    ],
  },
  {
    clave: "origen",
    etiqueta: t("captacion.prospectos.filtros.origen"),
    opciones: [
      {
        valor: "formulario",
        texto: t("captacion.prospectos.filtros.porFormulario"),
      },
      { valor: "manual", texto: t("captacion.prospectos.origen.manual") },
    ],
  },
  ...(formularios.value.length > 1
    ? [
        {
          clave: "formulario",
          etiqueta: t("captacion.prospectos.filtros.formulario"),
          opciones: formularios.value.map((f) => ({
            valor: f.id,
            texto: f.nombre,
          })),
        },
      ]
    : []),
  {
    clave: "campana",
    etiqueta: t("captacion.prospectos.filtros.campana"),
    opciones: [
      { valor: "con", texto: t("captacion.prospectos.filtros.conCampana") },
      { valor: "sin", texto: t("captacion.prospectos.filtros.sinCampana") },
    ],
  },
  ...(sucursales.value.length > 1
    ? [
        {
          clave: "sucursal",
          etiqueta: t("captacion.prospectos.filtros.sede"),
          opciones: sucursales.value.map((s) => ({
            valor: s.id,
            texto: s.nombre,
          })),
        },
      ]
    : []),
]);
// Los de un toque («Sin atender», «Seguimiento vencido») van como «1».
const valoresFiltro = computed<Record<string, string>>(() => ({
  estado: filtros.estado,
  responsable: filtros.responsable,
  atencion: filtros.atencion ? "1" : "",
  seguimiento: filtros.seguimiento ? "1" : "",
  periodo: filtros.periodo,
  origen: filtros.origen,
  formulario: filtros.formulario,
  campana: filtros.campana,
  sucursal: filtros.sucursal,
}));
function cambioFiltro(clave: string, valor: string): void {
  if (clave === "atencion") {
    filtros.atencion = valor ? "sin_atender" : "";
  } else if (clave === "seguimiento") {
    filtros.seguimiento = valor ? "vencido" : "";
  } else if (clave in filtros && clave !== "q") {
    filtros[clave as Exclude<keyof typeof filtros, "q">] = valor;
  }
}
function limpiarFiltros(): void {
  Object.assign(filtros, {
    estado: "",
    responsable: "",
    atencion: "",
    seguimiento: "",
    periodo: "",
    origen: "",
    formulario: "",
    campana: "",
    sucursal: "",
  });
}
const filtrando = computed(
  () =>
    filtros.q.trim() !== "" ||
    Object.values(valoresFiltro.value).some((v) => v !== ""),
);

// ---------------------------------------------------------------- responsables
interface Responsable {
  id: string;
  nombre: string;
  email: string | null;
}
const responsables = ref<Responsable[]>([]);
const buscarResponsable = ref("");
const eligiendo = ref(false);
async function cargarResponsables(): Promise<void> {
  if (!puedeGestionar.value) {
    return;
  }
  try {
    const { data } = await api.get<{ data: Responsable[] }>(
      `${base.value}/prospectos/responsables`,
    );
    responsables.value = data.data;
  } catch {
    responsables.value = [];
  }
}
const normalizar = (s: string) =>
  s
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase();
// A quién puede pasarlo: el equipo que atiende prospectos, menos quien ya lo tiene.
// Al salir del buscador (tocar fuera en el teléfono) se cierra, después de dejar que
// el toque en una opción la elija.
function cerrarOpciones(): void {
  window.setTimeout(() => {
    eligiendo.value = false;
  }, 200);
}
const candidatos = computed(() => {
  const q = normalizar(buscarResponsable.value.trim());
  return responsables.value
    .filter((r) => r.id !== actual.value?.responsable?.id)
    .filter(
      (r) => q === "" || normalizar(`${r.nombre} ${r.email ?? ""}`).includes(q),
    )
    .slice(0, 8);
});

// ---------------------------------------------------------------- formato
function fecha(iso: string | null, conHora = false): string {
  if (!iso) {
    return "";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    ...(conHora ? { hour: "numeric", minute: "2-digit" } : {}),
    timeZone: sesion.zonaHoraria ?? undefined,
  }).format(new Date(iso));
}
function tono(estado: EstadoProspecto): string {
  return {
    nuevo: "var(--acento)",
    en_seguimiento: "var(--aviso)",
    aprobado: "var(--aviso)",
    invitacion_enviada: "var(--aviso)",
    convertido: "var(--exito)",
    descartado: "var(--texto-suave)",
  }[estado];
}
function interes(p: Prospecto): string {
  const i = p.interes;
  if (i.sesion) {
    return t("captacion.prospectos.detalle.clase", {
      clase: i.sesion.clase ?? "",
      fecha: fecha(i.sesion.inicia_en, true),
    });
  }
  if (i.producto) {
    return t("captacion.prospectos.detalle.plan", {
      nombre: i.producto.nombre,
    });
  }
  if (i.actividad) {
    return i.actividad.nombre;
  }
  return i.sucursal
    ? t("captacion.prospectos.detalle.sede", { nombre: i.sucursal.nombre })
    : "";
}
function vencido(p: Prospecto): boolean {
  return (
    abierto(p.estado) &&
    p.proximo_seguimiento_en !== null &&
    new Date(p.proximo_seguimiento_en).getTime() <= Date.now()
  );
}
function textoEvento(e: EventoProspecto): string {
  const d = e.detalle;
  switch (e.tipo) {
    case "estado":
      return t("captacion.prospectos.eventos.estado", {
        estado: t(`captacion.prospectos.estados.${String(d.a)}`),
      });
    case "asignado":
      if (d.responsable && d.anterior) {
        return t("captacion.prospectos.eventos.reasignado", {
          anterior: String(d.anterior),
          responsable: String(d.responsable),
        });
      }
      if (d.responsable) {
        return t("captacion.prospectos.eventos.asignado", {
          responsable: String(d.responsable),
        });
      }
      return d.anterior
        ? t("captacion.prospectos.eventos.dejoDeAtender", {
            anterior: String(d.anterior),
          })
        : t("captacion.prospectos.eventos.sinAsignar");
    case "contacto":
      return t(`captacion.prospectos.eventos.contacto.${String(d.canal)}`);
    case "sin_atender":
      return d.responsable
        ? t("captacion.prospectos.eventos.sinAtender", {
            responsable: String(d.responsable),
            horas: Number(d.horas ?? 0),
          })
        : t("captacion.prospectos.eventos.sinAtenderNadie", {
            horas: Number(d.horas ?? 0),
          });
    case "seguimiento":
      return d.cuando
        ? t("captacion.prospectos.eventos.seguimiento", {
            fecha: fecha(String(d.cuando), true),
          })
        : t("captacion.prospectos.eventos.seguimientoQuitado");
    case "nota":
      return String(d.texto ?? "");
    default:
      return t(`captacion.prospectos.eventos.${e.tipo}`);
  }
}

// Cada cosa de la historia con su ícono, para distinguirla de un vistazo.
const ICONO_EVENTO: Record<EventoProspecto["tipo"], string> = {
  creado: "captacion",
  reenvio: "formularios",
  estado: "etiqueta",
  asignado: "usuarios",
  nota: "documentos",
  seguimiento: "agenda",
  invitado: "correo",
  convertido: "hecho",
  contacto: "mensaje",
  sin_atender: "reloj",
};
function iconoEvento(e: EventoProspecto): string {
  if (e.tipo === "contacto") {
    return e.detalle.canal === "llamada"
      ? "telefono"
      : e.detalle.canal === "correo"
        ? "correo"
        : "mensaje";
  }
  return ICONO_EVENTO[e.tipo] ?? "punto";
}

// ---------------------------------------------------------------- detalle
const actual = ref<Prospecto | null>(null);
const accionando = ref(false);
const nota = ref("");
const seguimiento = ref("");
const descartando = reactive({
  abierto: false,
  motivo: "" as MotivoCierre | "",
  comentario: "",
});
const acceso = reactive({
  abierto: false,
  invitar: true,
  buscar: "",
  fichas: [] as { id: string; nombre: string; email: string | null }[],
  ficha: "" as string,
});

async function abrir(p: Prospecto): Promise<void> {
  actual.value = p;
  descartando.abierto = false;
  acceso.abierto = false;
  nota.value = "";
  try {
    const { data } = await api.get<{ data: Prospecto }>(
      `${base.value}/prospectos/${p.id}`,
    );
    actual.value = data.data;
    seguimiento.value = data.data.proximo_seguimiento_en
      ? data.data.proximo_seguimiento_en.slice(0, 10)
      : "";
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function aplicar(
  peticion: () => Promise<{ data: { data: Prospecto } }>,
  exito?: string,
): Promise<void> {
  accionando.value = true;
  try {
    const { data } = await peticion();
    actual.value = data.data;
    if (exito) {
      toast.exito(exito);
    }
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = false;
  }
}

function cambiarEstado(estado: EstadoProspecto): Promise<void> {
  const p = actual.value;
  if (!p) {
    return Promise.resolve();
  }
  return aplicar(() =>
    api.put(`${base.value}/prospectos/${p.id}/estado`, { estado }),
  );
}
async function descartar(): Promise<void> {
  const p = actual.value;
  if (!p || descartando.motivo === "") {
    return;
  }
  await aplicar(() =>
    api.put(`${base.value}/prospectos/${p.id}/estado`, {
      estado: "descartado",
      motivo_cierre: descartando.motivo,
      comentario: descartando.comentario || null,
    }),
  );
  descartando.abierto = false;
}
function asignarA(id: string | null): Promise<void> {
  const p = actual.value;
  if (!p) {
    return Promise.resolve();
  }
  eligiendo.value = false;
  buscarResponsable.value = "";
  return aplicar(() =>
    api.put(`${base.value}/prospectos/${p.id}/responsable`, {
      responsable_id: id,
    }),
  );
}
// Al abrir WhatsApp, llamar o escribir: queda en su historia (ADR 0138). No detiene
// el enlace; si falla, el contacto se hizo igual.
async function registrarContacto(
  canal: "whatsapp" | "llamada" | "correo",
): Promise<void> {
  const p = actual.value;
  if (!p || !puedeGestionar.value) {
    return;
  }
  try {
    const { data } = await api.post<{ data: Prospecto }>(
      `${base.value}/prospectos/${p.id}/contactos`,
      { canal },
    );
    if (actual.value?.id === p.id) {
      actual.value = data.data;
    }
    void cargar();
  } catch {
    // Sin registro: el contacto ya se abrió.
  }
}
function guardarSeguimiento(quitar = false): Promise<void> {
  const p = actual.value;
  if (!p) {
    return Promise.resolve();
  }
  return aplicar(() =>
    api.put(`${base.value}/prospectos/${p.id}/seguimiento`, {
      cuando:
        quitar || seguimiento.value === ""
          ? null
          : `${seguimiento.value}T10:00:00`,
    }),
  );
}
async function anotar(): Promise<void> {
  const p = actual.value;
  if (!p || nota.value.trim() === "") {
    return;
  }
  await aplicar(() =>
    api.post(`${base.value}/prospectos/${p.id}/notas`, { texto: nota.value }),
  );
  nota.value = "";
}

function abrirAcceso(): void {
  acceso.abierto = true;
  acceso.invitar = !!actual.value?.email;
  acceso.buscar = actual.value?.email ?? actual.value?.nombre ?? "";
  acceso.fichas = [];
  acceso.ficha = "";
  void buscarFichas();
}
async function buscarFichas(): Promise<void> {
  if (acceso.buscar.trim().length < 2) {
    acceso.fichas = [];
    return;
  }
  try {
    const { data } = await api.get<{
      data: {
        id: string;
        nombre: string;
        nombre_completo?: string;
        email: string | null;
      }[];
    }>(`${base.value}/miembros`, { params: { q: acceso.buscar.trim() } });
    acceso.fichas = data.data.slice(0, 5).map((m) => ({
      id: m.id,
      nombre: m.nombre_completo ?? m.nombre,
      email: m.email,
    }));
  } catch {
    acceso.fichas = [];
  }
}
async function darAcceso(): Promise<void> {
  const p = actual.value;
  if (!p) {
    return;
  }
  accionando.value = true;
  try {
    const { data } = await api.post<{ data: Prospecto }>(
      `${base.value}/prospectos/${p.id}/convertir`,
      { invitar: acceso.invitar, persona_id: acceso.ficha || null },
    );
    actual.value = data.data;
    acceso.abierto = false;
    toast.exito(
      acceso.invitar && p.email
        ? t("captacion.prospectos.acceso.invitado", { email: p.email })
        : t("captacion.prospectos.acceso.listo"),
    );
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = false;
  }
}
async function eliminar(): Promise<void> {
  const p = actual.value;
  if (
    !p ||
    !(await confirmar(t("captacion.prospectos.eliminar.texto"), {
      aceptar: t("captacion.prospectos.eliminar.confirmar"),
      peligro: true,
    }))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/prospectos/${p.id}`);
    actual.value = null;
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

// ---------------------------------------------------------------- alta manual
const alta = reactive({ abierto: false, nombre: "", email: "", telefono: "" });
async function crear(): Promise<void> {
  if (
    alta.nombre.trim() === "" ||
    (alta.email.trim() === "" && alta.telefono.trim() === "")
  ) {
    toast.error(t("captacion.prospectos.alta.requiereContacto"));
    return;
  }
  accionando.value = true;
  try {
    const { data } = await api.post<{ data: Prospecto }>(
      `${base.value}/prospectos`,
      {
        nombre: alta.nombre,
        email: alta.email || null,
        telefono: alta.telefono || null,
      },
    );
    Object.assign(alta, {
      abierto: false,
      nombre: "",
      email: "",
      telefono: "",
    });
    await cargar();
    await abrir(data.data);
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = false;
  }
}
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('captacion.prospectos.titulo')"
      :subtitulo="$t('adminUsabilidad.prospectosAyuda')"
    />

    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
      <div
        class="flex flex-wrap gap-2 text-sm"
        data-prueba="resumen-prospectos"
      >
        <button
          type="button"
          class="tu-chip"
          :aria-pressed="filtros.estado === 'nuevo'"
          @click="filtros.estado = filtros.estado === 'nuevo' ? '' : 'nuevo'"
        >
          {{ $t("captacion.prospectos.resumen.nuevos") }}:
          <strong>{{ resumen.nuevos }}</strong>
        </button>
        <button
          type="button"
          class="tu-chip"
          data-prueba="chip-sin-atender"
          :aria-pressed="filtros.atencion === 'sin_atender'"
          @click="
            filtros.atencion =
              filtros.atencion === 'sin_atender' ? '' : 'sin_atender'
          "
        >
          {{ $t("captacion.prospectos.resumen.sinAtender") }}:
          <strong>{{ resumen.sin_atender }}</strong>
        </button>
        <button
          type="button"
          class="tu-chip"
          data-prueba="chip-vencidos"
          :aria-pressed="filtros.seguimiento === 'vencido'"
          @click="
            filtros.seguimiento =
              filtros.seguimiento === 'vencido' ? '' : 'vencido'
          "
        >
          {{ $t("captacion.prospectos.resumen.vencidos") }}:
          <strong>{{ resumen.seguimientos_vencidos }}</strong>
        </button>
        <span class="tu-chip">
          {{ $t("captacion.prospectos.resumen.abiertos") }}:
          <strong>{{ resumen.abiertos }}</strong>
        </span>
      </div>
    </div>

    <!-- Buscador, filtros y «Agregar», como en Miembros. -->
    <BarraListado
      v-model:busqueda="filtros.q"
      class="mt-4"
      :filtros="filtrosDef"
      :valores="valoresFiltro"
      :placeholder="$t('captacion.prospectos.buscar')"
      :puede-crear="puedeGestionar"
      :nuevo-texto="$t('captacion.prospectos.nuevo')"
      @cambio-filtro="cambioFiltro"
      @limpiar="limpiarFiltros"
      @nuevo="alta.abierto = true"
    />

    <div class="tu-card mt-4">
      <p
        v-if="error"
        class="p-4 text-sm"
        style="color: var(--error)"
        role="alert"
      >
        {{ error }}
      </p>
      <p
        v-else-if="cargando"
        class="p-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>
      <EstadoVacio
        v-else-if="lista.length === 0"
        icono="captacion"
        :titulo="
          filtrando
            ? $t('captacion.prospectos.vacioFiltro')
            : $t('captacion.prospectos.vacio')
        "
        compacto
      />
      <ul v-else class="divide-y" :style="{ borderColor: 'var(--borde)' }">
        <li v-for="p in lista" :key="p.id">
          <button
            type="button"
            class="prospecto-fila"
            data-prueba="prospecto"
            @click="abrir(p)"
          >
            <span class="min-w-0">
              <span class="block font-medium truncate">{{ p.nombre }}</span>
              <span class="tu-sub truncate">
                {{ [p.email, p.telefono].filter(Boolean).join(" · ") }}
              </span>
              <span v-if="interes(p)" class="tu-sub truncate">{{
                interes(p)
              }}</span>
            </span>
            <span class="prospecto-meta">
              <span
                class="tu-estado text-sm"
                :style="{ '--tono': tono(p.estado) }"
              >
                {{ $t(`captacion.prospectos.estados.${p.estado}`) }}
              </span>
              <span
                v-if="vencido(p)"
                class="tu-sub"
                style="color: var(--aviso)"
              >
                {{ $t("captacion.prospectos.vencido") }}
              </span>
              <span
                v-if="p.sin_atender_desde"
                class="tu-estado text-xs"
                :style="{ '--tono': 'var(--aviso)' }"
                data-prueba="fila-sin-atender"
              >
                {{ $t("captacion.prospectos.resumen.sinAtender") }}
              </span>
              <span class="tu-sub">{{ fecha(p.creado_en) }}</span>
            </span>
          </button>
        </li>
      </ul>
      <PaginacionListado
        v-if="paginacion && paginacion.ultima_pagina > 1"
        :page="paginacion.page"
        :ultima-pagina="paginacion.ultima_pagina"
        :total="paginacion.total"
        :per-page="paginacion.per_page"
        @ir="irAPagina"
      />
    </div>

    <!-- Detalle -->
    <PanelLateral
      :abierto="actual !== null"
      :titulo="actual?.nombre ?? ''"
      @cerrar="actual = null"
    >
      <div
        v-if="actual"
        class="grid gap-5 text-sm"
        data-prueba="detalle-prospecto"
      >
        <div class="flex flex-wrap items-center gap-2">
          <span class="tu-estado" :style="{ '--tono': tono(actual.estado) }">
            {{ $t(`captacion.prospectos.estados.${actual.estado}`) }}
          </span>
          <span :style="{ color: 'var(--texto-suave)' }">
            ·
            {{
              actual.origen === "manual"
                ? $t("captacion.prospectos.origen.manual")
                : $t("captacion.prospectos.origen.formulario", {
                    nombre: actual.formulario ?? "",
                  })
            }}
            · {{ fecha(actual.creado_en, true) }}
          </span>
        </div>

        <section>
          <h3 class="tu-label">
            {{ $t("captacion.prospectos.detalle.contacto") }}
          </h3>
          <p v-if="actual.email">{{ actual.email }}</p>
          <p v-if="actual.telefono">{{ actual.telefono }}</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <BotonWhatsApp
              v-if="whatsappDe(actual.telefono)"
              :href="whatsappDe(actual.telefono) ?? ''"
              :texto="$t('captacion.prospectos.acciones.escribir')"
              @abrir="registrarContacto('whatsapp')"
            />
            <a
              v-if="actual.telefono"
              class="tu-btn tu-btn-fantasma"
              :href="`tel:${actual.telefono}`"
              data-prueba="llamar"
              @click="registrarContacto('llamada')"
              ><IconoNav nombre="telefono" :tam="16" />{{
                $t("captacion.prospectos.acciones.llamar")
              }}</a
            >
            <a
              v-if="actual.email"
              class="tu-btn tu-btn-fantasma"
              :href="`mailto:${actual.email}`"
              data-prueba="enviar-correo"
              @click="registrarContacto('correo')"
              ><IconoNav nombre="correo" :tam="16" />{{
                $t("captacion.prospectos.acciones.correo")
              }}</a
            >
          </div>
          <p
            v-if="actual.fuente"
            class="mt-2"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t("captacion.prospectos.detalle.fuente", {
                fuente: actual.fuente,
              })
            }}
          </p>
          <!-- De dónde llegó (ADR 0142): lo que se le acredita y su primer origen. -->
          <template v-if="actual.eventos !== null">
            <p
              class="mt-1 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
              data-prueba="campana-prospecto"
            >
              {{
                actual.campana && textoCampana(actual.campana)
                  ? $t("captacion.prospectos.detalle.campana", {
                      campana: textoCampana(actual.campana),
                    })
                  : $t("captacion.prospectos.detalle.sinAtribucion")
              }}
            </p>
            <p
              v-if="actual.campana?.primer_origen"
              class="mt-1 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                $t("captacion.prospectos.detalle.primerOrigen", {
                  origen: textoCampana(actual.campana.primer_origen),
                })
              }}
            </p>
          </template>
        </section>

        <section>
          <h3 class="tu-label">
            {{ $t("captacion.prospectos.detalle.interes") }}
          </h3>
          <p>
            {{
              interes(actual) || $t("captacion.prospectos.detalle.sinInteres")
            }}
          </p>
        </section>

        <section v-if="actual.respuestas && actual.respuestas.length > 0">
          <h3 class="tu-label">
            {{ $t("captacion.prospectos.detalle.respuestas") }}
          </h3>
          <dl class="grid gap-1">
            <div v-for="r in actual.respuestas" :key="r.campo">
              <dt :style="{ color: 'var(--texto-suave)' }">{{ r.etiqueta }}</dt>
              <dd>
                {{
                  typeof r.valor === "boolean"
                    ? r.valor
                      ? $t("captacion.publico.si")
                      : $t("captacion.publico.no")
                    : String(r.valor)
                }}
              </dd>
            </div>
          </dl>
        </section>

        <section v-if="actual.persona">
          <RouterLink
            class="tu-enlace"
            :to="{ name: 'miembros', query: { q: actual.persona.nombre } }"
          >
            {{ actual.persona.nombre }}
          </RouterLink>
        </section>

        <template v-if="puedeGestionar && abierto(actual.estado)">
          <section>
            <h3 class="tu-label">
              {{ $t("captacion.prospectos.detalle.responsable") }}
            </h3>
            <p>
              {{
                actual.responsable?.nombre ??
                $t("captacion.prospectos.sinResponsable")
              }}
            </p>
            <p
              v-if="actual.sin_atender_desde"
              class="tu-estado mt-1 text-sm"
              :style="{ '--tono': 'var(--aviso)' }"
              data-prueba="aviso-sin-atender"
            >
              {{
                $t("captacion.prospectos.detalle.sinAtenderDesde", {
                  fecha: fecha(actual.sin_atender_desde, true),
                })
              }}
            </p>
            <div class="mt-2 grid gap-2 sm:flex sm:flex-wrap sm:items-start">
              <div class="relative w-full sm:w-72">
                <input
                  v-model="buscarResponsable"
                  class="tu-input"
                  type="search"
                  autocomplete="off"
                  data-prueba="buscar-responsable"
                  :placeholder="
                    $t('captacion.prospectos.acciones.buscarResponsable')
                  "
                  :aria-label="
                    $t('captacion.prospectos.acciones.buscarResponsable')
                  "
                  @focus="eligiendo = true"
                  @blur="cerrarOpciones"
                  @keydown.escape="eligiendo = false"
                />
                <ul
                  v-if="eligiendo"
                  class="tu-card pr-opciones"
                  role="listbox"
                  data-prueba="opciones-responsable"
                >
                  <li v-for="r in candidatos" :key="r.id">
                    <button
                      type="button"
                      role="option"
                      class="pr-opcion"
                      :disabled="accionando"
                      @click="asignarA(r.id)"
                    >
                      <span>{{ r.nombre }}</span>
                      <span v-if="r.email" class="tu-sub">{{ r.email }}</span>
                    </button>
                  </li>
                  <li v-if="candidatos.length === 0" class="pr-vacio">
                    {{ $t("captacion.prospectos.acciones.sinCoincidencias") }}
                  </li>
                </ul>
              </div>
              <button
                v-if="actual.responsable?.id !== sesion.usuario?.ulid"
                type="button"
                class="tu-btn tu-btn-fantasma"
                :disabled="accionando"
                data-prueba="asignarme"
                @click="asignarA(sesion.usuario?.ulid ?? null)"
              >
                {{ $t("captacion.prospectos.acciones.asignarme") }}
              </button>
              <button
                v-if="actual.responsable"
                type="button"
                class="tu-enlace self-center"
                :disabled="accionando"
                @click="asignarA(null)"
              >
                {{ $t("captacion.prospectos.acciones.quitarResponsable") }}
              </button>
            </div>
          </section>

          <section>
            <label class="tu-label" for="p-seguimiento">{{
              $t("captacion.prospectos.detalle.seguimiento")
            }}</label>
            <div class="flex flex-wrap items-center gap-2">
              <input
                id="p-seguimiento"
                v-model="seguimiento"
                type="date"
                class="tu-input w-auto"
              />
              <button
                type="button"
                class="tu-btn tu-btn-fantasma"
                :disabled="accionando"
                @click="guardarSeguimiento()"
              >
                {{ $t("captacion.prospectos.acciones.guardarFecha") }}
              </button>
              <button
                v-if="actual.proximo_seguimiento_en"
                type="button"
                class="tu-enlace"
                :disabled="accionando"
                @click="guardarSeguimiento(true)"
              >
                {{ $t("captacion.prospectos.acciones.quitarFecha") }}
              </button>
            </div>
          </section>

          <section class="flex flex-wrap gap-2">
            <button
              v-if="actual.estado === 'nuevo'"
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="accionando"
              @click="cambiarEstado('en_seguimiento')"
            >
              {{ $t("captacion.prospectos.acciones.seguimiento") }}
            </button>
            <button
              v-if="
                actual.estado !== 'aprobado' &&
                actual.estado !== 'invitacion_enviada'
              "
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="accionando"
              @click="cambiarEstado('aprobado')"
            >
              {{ $t("captacion.prospectos.acciones.aprobar") }}
            </button>
            <button
              v-if="puedeConvertir && actual.estado !== 'invitacion_enviada'"
              type="button"
              class="tu-btn tu-btn-primario"
              :disabled="accionando"
              @click="abrirAcceso"
            >
              {{ $t("captacion.prospectos.acciones.darAcceso") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="accionando"
              @click="descartando.abierto = !descartando.abierto"
            >
              {{ $t("captacion.prospectos.acciones.descartar") }}
            </button>
          </section>

          <form
            v-if="descartando.abierto"
            class="tu-card p-4 grid gap-3"
            @submit.prevent="descartar"
          >
            <label class="block">
              <span class="tu-label">{{
                $t("captacion.prospectos.descartar.motivo")
              }}</span>
              <select v-model="descartando.motivo" class="tu-input" required>
                <option value="" disabled />
                <option v-for="m in MOTIVOS_CIERRE" :key="m" :value="m">
                  {{ $t(`captacion.prospectos.motivos.${m}`) }}
                </option>
              </select>
            </label>
            <label class="block">
              <span class="tu-label">{{
                $t("captacion.prospectos.descartar.comentario")
              }}</span>
              <input
                v-model="descartando.comentario"
                class="tu-input"
                maxlength="300"
              />
            </label>
            <button
              type="submit"
              class="tu-btn tu-btn-primario justify-self-start"
              :disabled="accionando || descartando.motivo === ''"
            >
              {{ $t("captacion.prospectos.descartar.confirmar") }}
            </button>
          </form>

          <form
            v-if="acceso.abierto"
            class="tu-card p-4 grid gap-3"
            data-prueba="dar-acceso"
            @submit.prevent="darAcceso"
          >
            <h3 class="font-medium">
              {{ $t("captacion.prospectos.acceso.titulo") }}
            </h3>
            <p :style="{ color: 'var(--texto-suave)' }">
              {{ $t("captacion.prospectos.acceso.texto") }}
            </p>
            <label v-if="actual.email" class="flex items-start gap-2">
              <input v-model="acceso.invitar" type="checkbox" class="mt-1" />
              <span>{{ $t("captacion.prospectos.acceso.invitar") }}</span>
            </label>
            <p v-else :style="{ color: 'var(--texto-suave)' }">
              {{ $t("captacion.prospectos.acceso.sinCorreo") }}
            </p>
            <label class="block">
              <span class="tu-label">{{
                $t("captacion.prospectos.acceso.buscarFicha")
              }}</span>
              <input
                v-model="acceso.buscar"
                class="tu-input"
                type="search"
                @input="buscarFichas"
              />
            </label>
            <div v-if="acceso.fichas.length > 0" class="grid gap-1">
              <span class="tu-label">{{
                $t("captacion.prospectos.acceso.fichaExistente")
              }}</span>
              <label
                v-for="f in acceso.fichas"
                :key="f.id"
                class="flex items-center gap-2"
              >
                <input v-model="acceso.ficha" type="radio" :value="f.id" />
                <span
                  >{{ f.nombre
                  }}<span v-if="f.email" class="tu-sub">{{
                    f.email
                  }}</span></span
                >
              </label>
              <label class="flex items-center gap-2">
                <input v-model="acceso.ficha" type="radio" value="" />
                <span>{{ $t("captacion.prospectos.acceso.fichaNueva") }}</span>
              </label>
            </div>
            <button
              type="submit"
              class="tu-btn tu-btn-primario justify-self-start"
              :disabled="accionando"
            >
              {{ $t("captacion.prospectos.acceso.confirmar") }}
            </button>
          </form>

          <form class="grid gap-2" @submit.prevent="anotar">
            <label class="tu-label" for="p-nota">{{
              $t("captacion.prospectos.nota")
            }}</label>
            <textarea
              id="p-nota"
              v-model="nota"
              class="tu-input"
              rows="2"
              maxlength="1000"
              :placeholder="$t('captacion.prospectos.notaPlaceholder')"
            />
            <button
              type="submit"
              class="tu-btn tu-btn-fantasma justify-self-start"
              :disabled="accionando || nota.trim() === ''"
            >
              {{ $t("captacion.prospectos.acciones.anotar") }}
            </button>
          </form>
        </template>
        <button
          v-else-if="puedeGestionar && actual.estado === 'descartado'"
          type="button"
          class="tu-btn tu-btn-fantasma justify-self-start"
          :disabled="accionando"
          @click="cambiarEstado('en_seguimiento')"
        >
          {{ $t("captacion.prospectos.acciones.reabrir") }}
        </button>

        <section v-if="actual.eventos && actual.eventos.length > 0">
          <h3 class="tu-label">
            {{ $t("captacion.prospectos.detalle.historial") }}
          </h3>
          <ol class="pr-historia" data-prueba="historial-prospecto">
            <li
              v-for="(e, i) in actual.eventos"
              :key="i"
              class="pr-evento"
              :data-tipo="e.tipo"
            >
              <span class="pr-icono">
                <IconoNav :nombre="iconoEvento(e)" :tam="15" />
              </span>
              <div class="min-w-0">
                <p
                  :class="{
                    'pr-nota': e.tipo === 'nota',
                    'pr-alerta': e.tipo === 'sin_atender',
                  }"
                >
                  <span
                    v-if="e.tipo === 'estado'"
                    class="tu-estado"
                    :style="{
                      '--tono': tono(String(e.detalle.a) as EstadoProspecto),
                    }"
                    >{{ textoEvento(e) }}</span
                  >
                  <template v-else>{{ textoEvento(e) }}</template>
                </p>
                <span class="tu-sub">
                  {{ fecha(e.en, true)
                  }}<template v-if="e.autor"> · {{ e.autor }}</template>
                </span>
              </div>
            </li>
          </ol>
        </section>

        <button
          v-if="puedeEliminar"
          type="button"
          class="tu-enlace justify-self-start"
          style="color: var(--error)"
          @click="eliminar"
        >
          {{ $t("captacion.prospectos.acciones.eliminar") }}
        </button>
      </div>
    </PanelLateral>

    <!-- Alta manual -->
    <PanelLateral
      :abierto="alta.abierto"
      :titulo="$t('captacion.prospectos.alta.titulo')"
      @cerrar="alta.abierto = false"
    >
      <form class="grid gap-4" @submit.prevent="crear">
        <label class="block">
          <span class="tu-label">{{
            $t("captacion.prospectos.alta.nombre")
          }}</span>
          <input
            v-model="alta.nombre"
            class="tu-input"
            required
            maxlength="160"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{
            $t("captacion.prospectos.alta.email")
          }}</span>
          <input
            v-model="alta.email"
            class="tu-input"
            type="email"
            maxlength="190"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{
            $t("captacion.prospectos.alta.telefono")
          }}</span>
          <input
            v-model="alta.telefono"
            class="tu-input"
            type="tel"
            maxlength="40"
          />
        </label>
      </form>
      <template #pie>
        <div class="flex justify-end">
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="accionando"
            @click="crear"
          >
            {{ $t("captacion.prospectos.alta.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>

<style scoped>
.prospecto-fila {
  display: flex;
  width: 100%;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1rem;
  text-align: left;
}
.prospecto-fila:hover,
.prospecto-fila:focus-visible {
  background: var(--superficie-2);
}
.prospecto-meta {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.15rem;
  flex-shrink: 0;
  text-align: right;
}
.tu-sub {
  display: block;
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.tu-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.3rem 0.7rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  background: var(--superficie);
}
.tu-chip[aria-pressed="true"] {
  border-color: var(--acento);
}
/* Elegir responsable: la lista se abre al enfocar o escribir (sin depender del mouse). */
.pr-opciones {
  position: absolute;
  z-index: 20;
  margin-top: 0.25rem;
  width: 100%;
  max-height: 14rem;
  overflow: auto;
  padding: 0.25rem;
}
.pr-opcion {
  display: grid;
  width: 100%;
  gap: 0.05rem;
  padding: 0.55rem 0.75rem;
  border-radius: 0.6rem;
  text-align: left;
}
.pr-opcion:hover,
.pr-opcion:focus-visible {
  background: var(--superficie-2);
}
.pr-vacio {
  padding: 0.55rem 0.75rem;
  color: var(--texto-suave);
}
/* Historia: cada cosa con su ícono; las notas, como nota. */
.pr-historia {
  display: grid;
  gap: 0.75rem;
}
.pr-evento {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.65rem;
  align-items: start;
}
.pr-icono {
  display: inline-flex;
  width: 1.9rem;
  height: 1.9rem;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--borde);
  border-radius: 999px;
  color: var(--texto-suave);
  background: var(--superficie);
}
.pr-nota {
  padding: 0.5rem 0.7rem;
  border-left: 3px solid var(--borde);
  border-radius: 0.5rem;
  background: var(--superficie-2);
  white-space: pre-line;
}
.pr-alerta {
  color: var(--aviso);
  font-weight: 500;
}
</style>
