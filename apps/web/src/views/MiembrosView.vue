<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import BarraListado from "@/components/BarraListado.vue";
import BotonImportar from "@/components/BotonImportar.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import PanelEditarMiembro, {
  type MiembroEditable,
} from "@/components/PanelEditarMiembro.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import PanelMiembro from "@/components/PanelMiembro.vue";
import TarjetaMiembro from "@/components/TarjetaMiembro.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useAnchoMinimo } from "@/lib/pantalla";
import {
  creditosDe,
  cuandoReserva,
  diaCorto,
  estadoMembresia,
  type ResumenTarjeta,
} from "@/lib/resumenTarjeta";
import { plural } from "@/lib/terminologia";
import { useVistaListado } from "@/lib/vistaListado";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

const { t } = useI18n();
const toast = useToastStore();

interface Miembro {
  id: string;
  nombre: string;
  segundo_nombre: string | null;
  primer_apellido: string | null;
  segundo_apellido: string | null;
  nombre_completo: string;
  email: string | null;
  tipo: string;
  activo: boolean;
  es_facturable: boolean;
  archivado: boolean;
  primera_vez: boolean | null;
  alta?: string | null;
  acceso_app?: boolean;
  // Ya se le invitó y no ha activado su cuenta: se reenvía a esta cuenta.
  invitacion_pendiente?: string | null;
  celular?: string | null;
  sucursal?: { id: string; nombre: string } | null;
  // Para las tarjetas (`resumen=1`): membresía, visitas y adeudo.
  resumen?: ResumenTarjeta;
  // Baja lógica: cuándo y quién (solo en "Dados de baja").
  dado_de_baja_en?: string | null;
  dado_de_baja_por?: string | null;
}
interface Meta {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("miembros.gestionar"));
const puedeInvitar = computed(() => sesion.puede("usuarios.invitar"));

const tipo = ref<"miembro" | "instructor">("miembro");
const q = ref("");
const facturable = ref("");
const estado = ref("");
const archivado = ref("no");
const sucursalFiltro = ref("");
const page = ref(1);
const perPage = 20;

const sucursales = ref<{ id: string; nombre: string }[]>([]);
// El filtro por sede solo aplica con varias sucursales (R19).
const hayMultiSucursal = computed(() => sucursales.value.length > 1);

// Definición de filtros para la BarraListado (estilo Acadion). Solo para alumnos.
const filtrosDef = computed(() => {
  if (tipo.value !== "miembro") {
    return [];
  }
  const defs = [
    {
      clave: "estado",
      etiqueta: t("miembros.colEstado"),
      opciones: [
        { valor: "activo", texto: t("miembros.activo") },
        { valor: "inactivo", texto: t("miembros.suspendido") },
        { valor: "baja", texto: t("bajas.dadosDeBaja") },
      ],
    },
    {
      clave: "facturable",
      etiqueta: t("miembros.editar.facturable"),
      opciones: [
        { valor: "si", texto: t("miembros.filtros.facturableSi") },
        { valor: "no", texto: t("miembros.filtros.facturableNo") },
      ],
    },
    {
      clave: "archivado",
      etiqueta: t("miembros.editar.archivado"),
      opciones: [
        { valor: "si", texto: t("miembros.filtros.archivados") },
        { valor: "todos", texto: t("miembros.filtros.todos") },
      ],
    },
  ];
  if (hayMultiSucursal.value) {
    defs.push({
      clave: "sucursal_id",
      etiqueta: t("miembros.filtros.sede"),
      opciones: sucursales.value.map((s) => ({ valor: s.id, texto: s.nombre })),
    });
  }
  return defs;
});

const valoresFiltro = computed<Record<string, string>>(() => ({
  estado: estado.value,
  facturable: facturable.value,
  archivado: archivado.value,
  sucursal_id: sucursalFiltro.value,
}));

function cambioFiltro(clave: string, valor: string): void {
  if (clave === "estado") {
    estado.value = valor;
  } else if (clave === "facturable") {
    facturable.value = valor;
  } else if (clave === "archivado") {
    archivado.value = valor === "" ? "no" : valor;
  } else if (clave === "sucursal_id") {
    sucursalFiltro.value = valor;
  }
}

function limpiarFiltros(): void {
  estado.value = "";
  facturable.value = "";
  archivado.value = "no";
  sucursalFiltro.value = "";
}

const miembros = ref<Miembro[]>([]);
// Resumen de la persona elegida: a un lado de la tabla en pantallas anchas (como la
// demo de la landing) y como panel en las angostas.
const conDetalle = useAnchoMinimo(1280);
const seleccionado = ref<Miembro | null>(null);
// Con el resumen a un lado el correo ya se ve ahí: la tabla deja esa columna.
// Lista (tabla) o cuadrícula de tarjetas; se recuerda en este navegador.
const vista = useVistaListado("miembros");
const resumenAlLado = computed(
  () => conDetalle.value && tipo.value === "miembro",
);
function elegir(m: Miembro, e: MouseEvent): void {
  // El nombre (ficha) y las acciones de la fila conservan su propio clic.
  if ((e.target as HTMLElement).closest("a, button")) {
    return;
  }
  seleccionado.value = m;
}
const meta = ref<Meta | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const mensaje = ref<string | null>(null);

const editando = ref<MiembroEditable | null>(null);
const invitandoId = ref<string | null>(null);
const invitados = ref<Set<string>>(new Set());

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Miembro[]; meta: Meta }>(
      `${base.value}/miembros`,
      {
        params: {
          tipo: tipo.value,
          q: q.value.trim() || undefined,
          facturable: facturable.value || undefined,
          estado: estado.value || undefined,
          archivado: archivado.value,
          sucursal_id: sucursalFiltro.value || undefined,
          page: page.value,
          per_page: perPage,
          resumen: 1,
        },
      },
    );
    miembros.value = data.data;
    if (conDetalle.value && tipo.value === "miembro") {
      const mismo = miembros.value.find((m) => m.id === seleccionado.value?.id);
      seleccionado.value = mismo ?? miembros.value[0] ?? null;
    }
    meta.value = data.meta;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// Los números de arriba (`GET /miembros/resumen`): solo para clientes.
interface ResumenClientes {
  total: number;
  con_plan: number;
  nuevos_mes: number;
  por_vencer: number;
  vencidas: number;
  con_adeudo: number;
  dias_por_vencer: number;
}
const resumenClientes = ref<ResumenClientes | null>(null);
async function cargarResumen(): Promise<void> {
  try {
    const { data } = await api.get<{ data: ResumenClientes }>(
      `${base.value}/miembros/resumen`,
    );
    resumenClientes.value = data.data;
  } catch {
    // Sin el resumen, la lista sigue igual.
    resumenClientes.value = null;
  }
}
const indicadores = computed<Indicador[]>(() => {
  const r = resumenClientes.value;
  if (r === null) {
    return [];
  }
  return [
    {
      clave: "total",
      etiqueta: t("miembros.resumen.total", {
        grupo: plural(sesion.terminologia.miembro).toLowerCase(),
      }),
      valor: String(r.total),
      icono: "miembros",
      tono: "azul",
    },
    {
      clave: "conPlan",
      etiqueta: t("miembros.resumen.conPlan"),
      valor: String(r.con_plan),
      icono: "etiqueta",
      tono: "verde",
    },
    {
      clave: "nuevos",
      etiqueta: t("miembros.resumen.nuevos"),
      valor: String(r.nuevos_mes),
      icono: "personas",
      tono: "morado",
    },
    {
      clave: "porVencer",
      etiqueta: t("miembros.resumen.porVencer", { dias: r.dias_por_vencer }),
      valor:
        r.vencidas > 0
          ? t("miembros.resumen.porVencerValor", {
              n: r.por_vencer,
              vencidas: r.vencidas,
            })
          : String(r.por_vencer),
      icono: "reloj",
      tono: "naranja",
      aviso: r.por_vencer + r.vencidas > 0,
    },
    {
      clave: "adeudo",
      etiqueta: t("miembros.resumen.adeudo"),
      valor: String(r.con_adeudo),
      icono: "dinero",
      tono: "rosa",
      aviso: r.con_adeudo > 0,
    },
  ];
});

function recargarDesde1(): void {
  page.value = 1;
  void cargar();
}

let tempQ: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
  clearTimeout(tempQ);
  tempQ = setTimeout(recargarDesde1, 300);
});
watch([tipo, facturable, estado, archivado, sucursalFiltro], recargarDesde1);

function irPagina(n: number): void {
  if (meta.value === null || n < 1 || n > meta.value.ultima_pagina) {
    return;
  }
  page.value = n;
  void cargar();
}

function nombreCompleto(m: Miembro): string {
  return (
    m.nombre_completo ||
    [m.nombre, m.primer_apellido].filter(Boolean).join(" ").trim()
  );
}

function abrirEditar(m: Miembro): void {
  editando.value = {
    id: m.id,
    nombre: m.nombre,
    segundo_nombre: m.segundo_nombre,
    primer_apellido: m.primer_apellido,
    segundo_apellido: m.segundo_apellido,
    email: m.email,
    activo: m.activo,
    es_facturable: m.es_facturable,
    archivado: m.archivado,
  };
}
function onGuardado(): void {
  editando.value = null;
  void cargar();
}

function fechaCorta(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(iso),
  );
}
function detalleBaja(m: Miembro): string {
  if (!m.dado_de_baja_en) {
    return "";
  }
  const fecha = fechaCorta(m.dado_de_baja_en);
  return m.dado_de_baja_por
    ? t("bajas.detallePor", { fecha, quien: m.dado_de_baja_por })
    : t("bajas.detalle", { fecha });
}

// Reactiva a alguien dado de baja (vuelve con su historial).
const reactivandoId = ref<string | null>(null);
async function reactivar(id: string, nombre: string): Promise<void> {
  reactivandoId.value = id;
  error.value = null;
  try {
    await api.post(`${base.value}/miembros/${id}/reactivar`, {});
    toast.exito(t("bajas.reactivado", { nombre }));
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
    toast.error(error.value);
  } finally {
    reactivandoId.value = null;
  }
}

async function invitar(m: Miembro): Promise<void> {
  if (!m.email) {
    return;
  }
  invitandoId.value = m.id;
  error.value = null;
  try {
    await api.post(`${base.value}/usuarios/invitar`, {
      nombre: nombreCompleto(m),
      email: m.email,
      rol: "miembro",
    });
    invitados.value = new Set(invitados.value).add(m.id);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    invitandoId.value = null;
  }
}
// Invitación sin activar: se reenvía el correo (no se crea otra cuenta).
async function reenviarInvitacion(m: Miembro): Promise<void> {
  if (!m.invitacion_pendiente) {
    return;
  }
  invitandoId.value = m.id;
  error.value = null;
  try {
    await api.post(`${base.value}/usuarios/${m.invitacion_pendiente}/reenviar`);
    toast.exito(t("operacion.clientes.reenviada", { email: m.email ?? "" }));
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    invitandoId.value = null;
  }
}

// --- Alta ---
const form = ref({
  nombre: "",
  segundo_nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  email: "",
  celular: "",
  whatsapp: false,
  tipo: "miembro",
});
const guardando = ref(false);
const abiertoAlta = ref(false);
// Avisos por WhatsApp (ADR 0069): si el negocio los usa y el cliente dejó celular.
const ofrecerWhatsApp = computed(
  () =>
    sesion.estudio?.whatsapp_clientes === true &&
    form.value.celular.trim() !== "",
);
// El celular es de alguien dado de baja: el negocio decide (reactivarlo u otra persona).
const coincidencia = ref<{
  id: string;
  nombre: string;
  mensaje: string;
} | null>(null);

function abrirAlta(): void {
  form.value = {
    nombre: "",
    segundo_nombre: "",
    primer_apellido: "",
    segundo_apellido: "",
    email: "",
    celular: "",
    whatsapp: false,
    tipo: tipo.value,
  };
  mensaje.value = null;
  error.value = null;
  coincidencia.value = null;
  abiertoAlta.value = true;
}
function cerrarAlta(): void {
  abiertoAlta.value = false;
}

async function crear(liberarCelular = false): Promise<void> {
  guardando.value = true;
  error.value = null;
  mensaje.value = null;
  coincidencia.value = null;
  try {
    const { data } = await api.post<{
      data: { reactivado?: boolean; nombre_completo?: string };
    }>(`${base.value}/miembros`, {
      nombre: form.value.nombre,
      segundo_nombre: form.value.segundo_nombre || null,
      primer_apellido: form.value.primer_apellido || null,
      segundo_apellido: form.value.segundo_apellido || null,
      email: form.value.email || null,
      celular: form.value.celular || null,
      ...(ofrecerWhatsApp.value && form.value.whatsapp
        ? { acepta_whatsapp: true }
        : {}),
      tipo: form.value.tipo,
      liberar_celular: liberarCelular || undefined,
    });
    const mismoTipo = form.value.tipo === tipo.value;
    // Con el correo de alguien dado de baja, se reactivó (con su historial).
    toast.exito(
      data.data.reactivado
        ? t("bajas.reactivado", { nombre: data.data.nombre_completo ?? "" })
        : t("miembros.creado"),
    );
    form.value = {
      nombre: "",
      segundo_nombre: "",
      primer_apellido: "",
      segundo_apellido: "",
      email: "",
      celular: "",
      whatsapp: false,
      tipo: tipo.value,
    };
    abiertoAlta.value = false;
    if (mismoTipo) {
      recargarDesde1();
    }
  } catch (e) {
    const respuesta = axios.isAxiosError(e) ? e.response?.data : null;
    if (respuesta?.code === "PERSON_DEACTIVATED_MATCH") {
      coincidencia.value = {
        id: respuesta.meta.persona.id,
        nombre: respuesta.meta.persona.nombre,
        mensaje: respuesta.message,
      };
      return;
    }
    error.value = mensajeDeError(e);
    toast.error(error.value);
  } finally {
    guardando.value = false;
  }
}

async function reactivarCoincidencia(): Promise<void> {
  if (coincidencia.value === null) {
    return;
  }
  await reactivar(coincidencia.value.id, coincidencia.value.nombre);
  coincidencia.value = null;
  abiertoAlta.value = false;
}

watch(tipo, () => {
  form.value.tipo = tipo.value;
});
async function cargarSucursales(): Promise<void> {
  try {
    const { data } = await api.get<{ data: { id: string; nombre: string }[] }>(
      `${base.value}/sucursales`,
    );
    sucursales.value = data.data;
  } catch {
    // Sin permiso de sucursales o sin sedes: el filtro simplemente no aparece.
    sucursales.value = [];
  }
}

onMounted(() => {
  form.value.tipo = tipo.value;
  void cargarSucursales();
  void cargar();
  if (tipo.value === "miembro") {
    void cargarResumen();
  }
});
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 py-8">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion
        :titulo="plural(sesion.terminologia.miembro)"
        :total="meta?.total ?? 0"
        :subtitulo="$t('miembros.subtitulo')"
      />
      <BotonImportar
        v-if="puedeGestionar"
        ruta="importar"
        :texto="$t('nav.importar')"
      />
    </div>

    <!-- Cuántos hay y qué pide atención -->
    <TarjetasIndicadores
      v-if="tipo === 'miembro' && indicadores.length > 0"
      class="mt-6"
      :tarjetas="indicadores"
    />

    <div class="mt-6">
      <div class="min-w-0">
        <!-- Buscador + filtros + «Agregar» (estilo Acadion). -->
        <BarraListado
          v-model:busqueda="q"
          v-model:vista="vista"
          :filtros="filtrosDef"
          :valores="valoresFiltro"
          :placeholder="$t('miembros.buscar')"
          :puede-crear="puedeGestionar"
          :nuevo-texto="$t('miembros.crear')"
          @cambio-filtro="cambioFiltro"
          @limpiar="limpiarFiltros"
          @nuevo="abrirAlta"
        />

        <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
          {{ error }}
        </p>
        <p
          v-if="cargando"
          class="tu-card mt-4 p-6 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>

        <template v-else>
          <EstadoVacio
            v-if="miembros.length === 0"
            class="tu-card mt-4"
            icono="miembros"
            :titulo="$t('miembros.vacio')"
          />
          <div
            v-else
            class="mt-4 tu-card overflow-hidden"
            :class="{
              'xl:grid xl:grid-cols-[minmax(0,1fr)_22rem]': resumenAlLado,
            }"
          >
            <div class="min-w-0">
              <!-- Cuadrícula: una tarjeta por persona (tocarla muestra su resumen) -->
              <ul
                v-if="vista === 'cuadricula'"
                class="grid gap-3 p-4 sm:grid-cols-2"
                :class="resumenAlLado ? '' : 'lg:grid-cols-3'"
              >
                <li v-for="m in miembros" :key="m.id">
                  <div
                    class="mb-tarjeta"
                    :class="{ 'mb-tarjeta-activa': seleccionado?.id === m.id }"
                    @click="elegir(m, $event)"
                  >
                    <TarjetaMiembro
                      :nombre="m.nombre"
                      :nombre-completo="nombreCompleto(m)"
                      :email="m.email"
                      :celular="m.celular"
                      :sede="hayMultiSucursal ? m.sucursal?.nombre : null"
                      :activo="m.activo"
                      :nuevo="tipo === 'miembro' && m.primera_vez === true"
                      :resumen="m.dado_de_baja_en ? null : m.resumen"
                    >
                      <template #nombre>
                        <RouterLink
                          v-if="tipo === 'miembro'"
                          :to="{ name: 'ficha-miembro', params: { id: m.id } }"
                          class="hover:underline"
                          >{{ nombreCompleto(m) }}</RouterLink
                        >
                        <template v-else>{{ nombreCompleto(m) }}</template>
                      </template>
                    </TarjetaMiembro>
                    <p
                      v-if="m.dado_de_baja_en"
                      class="mt-2 text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      {{ detalleBaja(m) }}
                    </p>
                  </div>
                </li>
              </ul>
              <table v-else class="w-full text-sm">
                <thead>
                  <tr
                    class="text-left text-xs font-semibold uppercase tracking-wider"
                    :style="{
                      color: 'var(--texto-suave)',
                      background:
                        'color-mix(in srgb, var(--texto-suave) 6%, var(--superficie))',
                    }"
                  >
                    <th class="px-4 py-3 whitespace-nowrap">
                      {{ $t("miembros.colNombre") }}
                    </th>
                    <th
                      v-if="tipo !== 'miembro'"
                      class="px-4 py-3 whitespace-nowrap hidden sm:table-cell"
                    >
                      {{ $t("miembros.colCorreo") }}
                    </th>
                    <template v-else>
                      <th
                        class="px-4 py-3 whitespace-nowrap hidden md:table-cell"
                      >
                        {{ $t("operacion.clientes.registro") }}
                      </th>
                      <th
                        class="px-4 py-3 whitespace-nowrap hidden lg:table-cell"
                      >
                        {{ $t("operacion.clientes.app") }}
                      </th>
                      <th
                        class="px-4 py-3 whitespace-nowrap hidden sm:table-cell"
                      >
                        {{ $t("operacion.clientes.plan") }}
                      </th>
                      <th
                        class="px-4 py-3 whitespace-nowrap hidden md:table-cell"
                      >
                        {{ $t("operacion.clientes.saldo") }}
                      </th>
                      <th
                        class="px-4 py-3 whitespace-nowrap hidden"
                        :class="{ 'xl:table-cell': !resumenAlLado }"
                      >
                        {{ $t("operacion.clientes.proxima") }}
                      </th>
                    </template>
                    <th class="px-4 py-3 whitespace-nowrap">
                      {{
                        tipo === "miembro"
                          ? $t("operacion.clientes.estado")
                          : $t("miembros.colEstado")
                      }}
                    </th>
                    <th class="px-4 py-3 text-right"></th>
                  </tr>
                </thead>
                <tbody class="tu-tabla-cuerpo">
                  <tr
                    v-for="m in miembros"
                    :key="m.id"
                    class="border-t cursor-pointer"
                    :class="{ 'mb-activa': seleccionado?.id === m.id }"
                    :style="{ borderColor: 'var(--borde)' }"
                    @click="elegir(m, $event)"
                  >
                    <td class="px-4 py-2">
                      <div class="flex items-center gap-3 whitespace-nowrap">
                        <AvatarIniciales :nombre="m.nombre" tam="md" />
                        <span class="min-w-0">
                          <span class="flex items-center gap-2">
                            <RouterLink
                              v-if="tipo === 'miembro'"
                              :to="{
                                name: 'ficha-miembro',
                                params: { id: m.id },
                              }"
                              class="font-semibold hover:underline"
                              >{{ nombreCompleto(m) }}</RouterLink
                            >
                            <span v-else class="font-semibold">{{
                              nombreCompleto(m)
                            }}</span>
                            <span
                              v-if="tipo === 'miembro' && m.primera_vez"
                              class="mb-etiqueta-nuevo"
                              :title="$t('miembros.nuevoAyuda')"
                              >{{ $t("miembros.nuevo") }}</span
                            >
                          </span>
                          <span
                            v-if="tipo === 'miembro' && (m.email || m.celular)"
                            class="block text-xs"
                            :style="{ color: 'var(--texto-suave)' }"
                            >{{ m.email ?? m.celular }}</span
                          >
                        </span>
                      </div>
                    </td>
                    <td
                      v-if="tipo !== 'miembro'"
                      class="px-4 py-2 hidden sm:table-cell"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      {{ m.email ?? "—" }}
                    </td>
                    <template v-else>
                      <td
                        class="px-4 py-2 whitespace-nowrap hidden md:table-cell"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{ m.alta ? diaCorto(m.alta) : "—" }}
                      </td>
                      <td
                        class="px-4 py-2 whitespace-nowrap hidden lg:table-cell"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{
                          m.acceso_app
                            ? $t("operacion.clientes.conApp")
                            : invitados.has(m.id) || m.invitacion_pendiente
                              ? $t("operacion.clientes.invitacionPendiente")
                              : $t("operacion.clientes.sinApp")
                        }}
                      </td>
                      <td class="px-4 py-2 hidden sm:table-cell">
                        <span class="block max-w-[14rem] truncate">{{
                          m.resumen?.membresia.plan ??
                          $t("tarjetas.sinMembresia")
                        }}</span>
                        <span
                          v-if="
                            m.resumen?.membresia.plan &&
                            estadoMembresia(m.resumen.membresia, t)
                          "
                          class="block text-xs"
                          :style="{
                            color:
                              estadoMembresia(m.resumen.membresia, t)!.color ??
                              'var(--texto-suave)',
                          }"
                          >{{
                            estadoMembresia(m.resumen.membresia, t)!.texto
                          }}</span
                        >
                      </td>
                      <td
                        class="px-4 py-2 whitespace-nowrap hidden md:table-cell tabular-nums"
                      >
                        {{ creditosDe(m.resumen?.membresia, t) ?? "—" }}
                      </td>
                      <td
                        class="px-4 py-2 whitespace-nowrap hidden"
                        :class="{ 'xl:table-cell': !resumenAlLado }"
                        :title="m.resumen?.proxima?.clase ?? undefined"
                      >
                        <template v-if="m.resumen?.proxima">
                          <span class="block first-letter:uppercase">{{
                            cuandoReserva(
                              m.resumen.proxima.inicia_en,
                              m.resumen.proxima.zona_horaria,
                            )
                          }}</span>
                          <span
                            class="block max-w-[12rem] truncate text-xs"
                            :style="{ color: 'var(--texto-suave)' }"
                            >{{ m.resumen.proxima.clase }}</span
                          >
                        </template>
                        <span v-else :style="{ color: 'var(--texto-suave)' }"
                          >—</span
                        >
                      </td>
                    </template>
                    <!-- Estado en texto: lo normal (activo) en gris; lo que requiere atención, en color. -->
                    <td
                      v-if="m.dado_de_baja_en"
                      class="px-4 py-2"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      {{ detalleBaja(m) }}
                    </td>
                    <td v-else class="px-4 py-2 whitespace-nowrap">
                      <span class="mb-estado">
                        <span
                          class="mb-punto"
                          :style="{
                            background: m.archivado
                              ? 'var(--texto-suave)'
                              : m.activo
                                ? 'var(--exito)'
                                : 'var(--aviso)',
                          }"
                          aria-hidden="true"
                        ></span>
                        {{
                          m.activo
                            ? $t("miembros.activo")
                            : $t("miembros.suspendido")
                        }}</span
                      >
                      <span
                        v-if="tipo === 'miembro' && !m.es_facturable"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        · {{ $t("miembros.noFacturable") }}</span
                      >
                      <span
                        v-if="m.archivado"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        · {{ $t("miembros.archivado") }}</span
                      >
                    </td>
                    <td
                      v-if="m.dado_de_baja_en"
                      class="px-4 py-2 text-right whitespace-nowrap"
                    >
                      <button
                        v-if="puedeGestionar"
                        class="tu-enlace text-sm"
                        type="button"
                        :disabled="reactivandoId === m.id"
                        @click="reactivar(m.id, nombreCompleto(m))"
                      >
                        {{ $t("bajas.reactivar") }}
                      </button>
                    </td>
                    <td v-else class="px-4 py-2 text-right whitespace-nowrap">
                      <button
                        v-if="
                          puedeInvitar &&
                          tipo === 'miembro' &&
                          m.email &&
                          !m.acceso_app &&
                          m.invitacion_pendiente &&
                          !invitados.has(m.id)
                        "
                        class="tu-enlace text-sm mr-3"
                        type="button"
                        data-prueba="reenviar-invitacion"
                        :disabled="invitandoId === m.id"
                        @click="reenviarInvitacion(m)"
                      >
                        {{
                          invitandoId === m.id
                            ? $t("operacion.clientes.reenviando")
                            : $t("operacion.clientes.reenviar")
                        }}
                      </button>
                      <button
                        v-else-if="
                          puedeInvitar &&
                          tipo === 'miembro' &&
                          m.email &&
                          !m.acceso_app &&
                          !invitados.has(m.id)
                        "
                        class="tu-enlace text-sm mr-3"
                        type="button"
                        :title="$t('operacion.clientes.invitarAyuda')"
                        :disabled="invitandoId === m.id"
                        @click="invitar(m)"
                      >
                        {{
                          invitandoId === m.id
                            ? $t("miembros.invitando")
                            : $t("operacion.clientes.invitar")
                        }}
                      </button>
                      <span
                        v-else-if="invitados.has(m.id)"
                        class="text-sm mr-3"
                        :style="{ color: 'var(--texto-suave)' }"
                        >{{ $t("miembros.invitado") }}</span
                      >
                      <button
                        v-if="puedeGestionar"
                        class="tu-enlace text-sm"
                        type="button"
                        @click="abrirEditar(m)"
                      >
                        {{ $t("miembros.editar.abrir") }}
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
              <PaginacionListado
                v-if="meta"
                :page="meta.page"
                :ultima-pagina="meta.ultima_pagina"
                :total="meta.total"
                :per-page="meta.per_page"
                @ir="irPagina"
              />
            </div>

            <!-- Resumen de la persona elegida, sobre fondo gris -->
            <div
              v-if="resumenAlLado"
              class="border-l"
              :style="{
                borderColor: 'var(--borde)',
                background: 'var(--fondo)',
              }"
            >
              <PanelMiembro
                v-if="seleccionado"
                :persona-id="seleccionado.id"
                :nombre="nombreCompleto(seleccionado)"
                incrustado
                @cerrar="seleccionado = null"
              />
              <p
                v-else
                class="px-6 py-10 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("recepcionVisual.seleccionaPersona") }}
              </p>
            </div>
          </div>
        </template>

        <!-- Pantallas angostas: el resumen como panel -->
        <PanelMiembro
          v-if="seleccionado && !conDetalle"
          :persona-id="seleccionado.id"
          :nombre="nombreCompleto(seleccionado)"
          @cerrar="seleccionado = null"
        />
      </div>
    </div>

    <!-- Alta de miembro (drawer lateral) -->
    <PanelLateral
      :abierto="abiertoAlta"
      :titulo="$t('miembros.nuevoTitulo')"
      @cerrar="cerrarAlta"
    >
      <form class="space-y-4" @submit.prevent="crear()">
        <div>
          <label class="tu-label" for="mn">{{ $t("miembros.nombre") }}</label>
          <input id="mn" v-model="form.nombre" class="tu-input" required />
        </div>
        <div>
          <label class="tu-label" for="mpa">{{
            $t("miembros.primerApellido")
          }}</label>
          <input id="mpa" v-model="form.primer_apellido" class="tu-input" />
        </div>
        <div>
          <label class="tu-label" for="me">{{ $t("miembros.email") }}</label>
          <input id="me" v-model="form.email" class="tu-input" type="email" />
        </div>
        <div>
          <label class="tu-label" for="mc">{{ $t("miembros.celular") }}</label>
          <input id="mc" v-model="form.celular" class="tu-input" type="tel" />
        </div>
        <label v-if="ofrecerWhatsApp" class="flex items-start gap-2 text-sm">
          <input
            v-model="form.whatsapp"
            type="checkbox"
            class="mt-1"
            data-prueba="acepta-whatsapp"
          />
          <span>
            {{ $t("avisosWhatsApp.aceptaCliente") }}
            <span
              class="block text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("avisosWhatsApp.ayudaCliente") }}</span
            >
          </span>
        </label>
        <p v-if="mensaje" class="text-sm" :style="{ color: 'var(--exito)' }">
          {{ $t("miembros.creado") }}
        </p>
        <div
          v-if="coincidencia"
          class="rounded-lg border p-3 text-sm"
          role="status"
          :style="{ borderColor: 'var(--aviso)' }"
        >
          <p>{{ coincidencia.mensaje }}</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button
              type="button"
              class="tu-btn tu-btn-primario text-sm"
              :disabled="guardando || reactivandoId !== null"
              @click="reactivarCoincidencia"
            >
              {{ $t("bajas.reactivarlo", { nombre: coincidencia.nombre }) }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="guardando"
              @click="crear(true)"
            >
              {{ $t("bajas.otraPersona") }}
            </button>
          </div>
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("bajas.otraPersonaAyuda") }}
          </p>
        </div>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            @click="cerrarAlta"
          >
            {{ $t("comun.cerrar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="guardando || form.nombre === ''"
            @click="crear()"
          >
            {{ guardando ? $t("miembros.creando") : $t("miembros.crear") }}
          </button>
        </div>
      </template>
    </PanelLateral>

    <PanelEditarMiembro
      v-if="editando"
      :miembro="editando"
      @cerrar="editando = null"
      @guardado="onGuardado"
    />
  </section>
</template>

<style scoped>
/* «Nuevo» (aún no asiste): etiqueta suave en ámbar. */
.mb-etiqueta-nuevo {
  padding: 0.05rem 0.45rem;
  border-radius: 0.4rem;
  background: color-mix(in srgb, var(--aviso) 14%, var(--superficie));
  color: var(--aviso);
  font-size: 0.7rem;
  font-weight: 600;
}
.mb-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}
.mb-punto {
  width: 0.5rem;
  height: 0.5rem;
  flex-shrink: 0;
  border-radius: 999px;
}
.tu-tabla-cuerpo tr {
  transition: background-color 0.12s ease;
}
.tu-tabla-cuerpo tr:hover {
  background: color-mix(in srgb, var(--primario) 5%, transparent);
}
/* Tarjeta de la vista en cuadrícula. */
.mb-tarjeta {
  display: block;
  height: 100%;
  padding: 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.8rem;
  background: var(--superficie);
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease;
}
.mb-tarjeta:hover {
  border-color: color-mix(in srgb, var(--primario) 40%, var(--borde));
}
.mb-tarjeta-activa {
  border-color: var(--primario);
  background: color-mix(in srgb, var(--primario) 6%, var(--superficie));
}
/* Fila elegida (su resumen está a un lado), como la clase activa en Recepción. */
.tu-tabla-cuerpo tr.mb-activa {
  background: color-mix(in srgb, var(--primario) 7%, var(--superficie));
  box-shadow: inset 3px 0 0 var(--primario);
}
</style>
