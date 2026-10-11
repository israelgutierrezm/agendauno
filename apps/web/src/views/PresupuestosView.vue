<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import BuscarPersona from "@/components/BuscarPersona.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import {
  aMinor as leerMinor,
  aTexto as textoMinor,
  dinero,
} from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Presupuestos de un negocio de citas (ADR 0127): para un cliente o un contacto, con
 * servicios, paquetes, artículos o conceptos libres; se envían por correo o se
 * imprimen; el negocio marca si el cliente aceptó y, aceptado, se convierte en cobro.
 * Las cuentas las hace el servidor; aquí solo se adelantan para ver el total.
 */
type Estado = "borrador" | "enviado" | "aceptado" | "rechazado" | "convertido";
type Tipo = "servicio" | "producto" | "articulo" | "libre";
interface Resumen {
  id: string;
  folio: string;
  cliente: string | null;
  estado: Estado;
  vencido: boolean;
  valido_hasta: string;
  total_minor: number;
  moneda: string;
}
interface Linea {
  tipo: Tipo;
  referencia: string | null;
  descripcion: string;
  cantidad: number;
  precio_unitario_minor: number;
  descuento_minor: number;
  subtotal_minor?: number;
}
interface Presupuesto extends Resumen {
  persona_id: string | null;
  contacto: {
    nombre: string | null;
    email: string | null;
    celular: string | null;
  };
  sucursal_id: string | null;
  impuestos: "incluidos" | "desglosados";
  tasa_impuesto_bp: number;
  tasa_impuesto: string;
  subtotal_minor: number;
  descuento_minor: number;
  impuesto_minor: number;
  notas: string | null;
  lineas: Linea[];
  editable: boolean;
  puede_convertir: boolean;
  orden: string | null;
}
interface ItemCatalogo {
  id: string;
  nombre: string;
  precio_minor: number;
}
interface Catalogo {
  servicios: ItemCatalogo[];
  productos: ItemCatalogo[];
  articulos: ItemCatalogo[];
}
interface Configuracion {
  impuestos: "incluidos" | "desglosados";
  tasa_impuesto_bp: number;
  vigencia_dias: number;
  notas: string | null;
}
interface Sucursal {
  id: string;
  nombre: string;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("presupuestos.gestionar"));
const puedeCobrar = computed(() => sesion.puede("ordenes.gestionar"));

const lista = ref<Resumen[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const filtro = ref<"todos" | Estado | "vencido">("todos");
const buscar = ref("");
const catalogo = ref<Catalogo>({ servicios: [], productos: [], articulos: [] });
const sucursales = ref<Sucursal[]>([]);
const config = ref<Configuracion>({
  impuestos: "incluidos",
  tasa_impuesto_bp: 1600,
  vigencia_dias: 15,
  notas: null,
});

const FILTROS = [
  "todos",
  "borrador",
  "enviado",
  "aceptado",
  "rechazado",
  "vencido",
  "convertido",
] as const;

function plata(minor: number, moneda = "MXN"): string {
  return dinero(minor, moneda, sesion.pais);
}

function estadoVisible(p: Resumen): Estado | "vencido" {
  return p.vencido ? "vencido" : p.estado;
}
const COLOR: Record<Estado | "vencido", string> = {
  borrador: "var(--texto-suave)",
  enviado: "var(--primario)",
  aceptado: "var(--exito)",
  rechazado: "var(--error)",
  convertido: "var(--exito)",
  vencido: "var(--aviso)",
};

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Resumen[] }>(
      `${base.value}/presupuestos`,
      {
        params: {
          ...(filtro.value !== "todos" ? { estado: filtro.value } : {}),
          ...(buscar.value.trim() !== "" ? { q: buscar.value.trim() } : {}),
        },
      },
    );
    lista.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function cargarApoyos(): Promise<void> {
  try {
    const [cat, cfg, suc] = await Promise.all([
      api.get<{ data: Catalogo }>(`${base.value}/presupuestos/catalogo`),
      api.get<{ data: Configuracion }>(
        `${base.value}/presupuestos/configuracion`,
      ),
      sesion.puede("sucursales.ver")
        ? api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`)
        : Promise.resolve({ data: { data: [] as Sucursal[] } }),
    ]);
    catalogo.value = cat.data.data;
    config.value = cfg.data.data;
    sucursales.value = suc.data.data;
  } catch {
    // Sin catálogo se puede presupuestar con conceptos libres.
  }
}

let espera: ReturnType<typeof setTimeout> | undefined;
watch(filtro, () => void cargar());
watch(buscar, () => {
  clearTimeout(espera);
  espera = setTimeout(() => void cargar(), 300);
});

// ---- Editor ----
const abierto = ref(false);
const actual = ref<Presupuesto | null>(null);
const guardando = ref(false);
const errorEditor = ref<string | null>(null);
const borrador = ref({
  esContacto: false,
  persona_id: "",
  nombre_contacto: "",
  email_contacto: "",
  celular_contacto: "",
  sucursal_id: "",
  valido_hasta: "",
  descuento: "",
  notas: "",
  lineas: [] as {
    tipo: Tipo;
    referencia: string;
    descripcion: string;
    cantidad: number;
    precio: string;
    descuento: string;
  }[],
});

const soloLectura = computed(
  () =>
    !puedeGestionar.value || (actual.value !== null && !actual.value.editable),
);

// Los montos como los escribe el país del negocio («1,250.50» en México).
function aMinor(texto: string): number {
  return texto.trim() === ""
    ? 0
    : Math.max(0, leerMinor(texto, sesion.pais) ?? 0);
}
function aTexto(minor: number): string {
  return minor > 0 ? textoMinor(minor, sesion.pais) : "";
}

function nuevaLinea(tipo: Tipo = "servicio"): void {
  borrador.value.lineas.push({
    tipo,
    referencia: "",
    descripcion: "",
    cantidad: 1,
    precio: "",
    descuento: "",
  });
}

function opcionesDe(tipo: Tipo): ItemCatalogo[] {
  return tipo === "servicio"
    ? catalogo.value.servicios
    : tipo === "producto"
      ? catalogo.value.productos
      : tipo === "articulo"
        ? catalogo.value.articulos
        : [];
}

function alElegir(i: number): void {
  const l = borrador.value.lineas[i];
  if (!l) {
    return;
  }
  const item = opcionesDe(l.tipo).find((o) => o.id === l.referencia);
  if (item) {
    l.descripcion = item.nombre;
    l.precio = aTexto(item.precio_minor);
  }
}

// Las mismas cuentas que el servidor (TotalesPresupuesto), para ver el total al editar.
const totales = computed(() => {
  const impuestos = actual.value?.impuestos ?? config.value.impuestos;
  const tasa = actual.value?.tasa_impuesto_bp ?? config.value.tasa_impuesto_bp;
  const subtotal = borrador.value.lineas.reduce(
    (s, l) =>
      s +
      Math.max(
        0,
        Math.max(1, l.cantidad) * aMinor(l.precio) - aMinor(l.descuento),
      ),
    0,
  );
  const descuento = Math.min(aMinor(borrador.value.descuento), subtotal);
  const baseImponible = subtotal - descuento;
  if (impuestos === "desglosados") {
    const impuesto = Math.round((baseImponible * tasa) / 10000);
    return {
      impuestos,
      tasa,
      subtotal,
      descuento,
      impuesto,
      total: baseImponible + impuesto,
    };
  }
  const sinImpuesto =
    tasa === 0
      ? baseImponible
      : Math.round((baseImponible * 10000) / (10000 + tasa));
  return {
    impuestos,
    tasa,
    subtotal,
    descuento,
    impuesto: baseImponible - sinImpuesto,
    total: baseImponible,
  };
});
const tasaTexto = computed(() => `${totales.value.tasa / 100} %`);
const moneda = computed(
  () => actual.value?.moneda ?? lista.value[0]?.moneda ?? "MXN",
);

function abrirNuevo(): void {
  actual.value = null;
  errorEditor.value = null;
  borrador.value = {
    esContacto: false,
    persona_id: "",
    nombre_contacto: "",
    email_contacto: "",
    celular_contacto: "",
    sucursal_id: sucursales.value.length === 1 ? sucursales.value[0]!.id : "",
    valido_hasta: "",
    descuento: "",
    notas: config.value.notas ?? "",
    lineas: [],
  };
  nuevaLinea();
  abierto.value = true;
}

async function abrir(id: string): Promise<void> {
  errorEditor.value = null;
  try {
    const { data } = await api.get<{ data: Presupuesto }>(
      `${base.value}/presupuestos/${id}`,
    );
    cargarEnEditor(data.data);
    abierto.value = true;
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

function cargarEnEditor(p: Presupuesto): void {
  actual.value = p;
  borrador.value = {
    esContacto: p.persona_id === null,
    persona_id: p.persona_id ?? "",
    nombre_contacto: p.persona_id === null ? (p.contacto.nombre ?? "") : "",
    email_contacto: p.persona_id === null ? (p.contacto.email ?? "") : "",
    celular_contacto: p.persona_id === null ? (p.contacto.celular ?? "") : "",
    sucursal_id: p.sucursal_id ?? "",
    valido_hasta: p.valido_hasta,
    descuento: aTexto(p.descuento_minor),
    notas: p.notas ?? "",
    lineas: p.lineas.map((l) => ({
      tipo: l.tipo,
      referencia: l.referencia ?? "",
      descripcion: l.descripcion,
      cantidad: l.cantidad,
      precio: aTexto(l.precio_unitario_minor),
      descuento: aTexto(l.descuento_minor),
    })),
  };
}

function cuerpo(): Record<string, unknown> {
  const b = borrador.value;
  return {
    ...(b.esContacto
      ? {
          persona_id: null,
          nombre_contacto: b.nombre_contacto.trim(),
          email_contacto: b.email_contacto.trim() || null,
          celular_contacto: b.celular_contacto.trim() || null,
        }
      : { persona_id: b.persona_id || null }),
    sucursal_id: b.sucursal_id || null,
    ...(b.valido_hasta ? { valido_hasta: b.valido_hasta } : {}),
    descuento_minor: aMinor(b.descuento),
    notas: b.notas.trim() || null,
    lineas: b.lineas.map((l) => ({
      tipo: l.tipo,
      referencia: l.tipo === "libre" ? null : l.referencia || null,
      descripcion: l.descripcion.trim() || null,
      cantidad: Math.max(1, Number(l.cantidad) || 1),
      precio_unitario_minor: aMinor(l.precio),
      descuento_minor: aMinor(l.descuento),
    })),
  };
}

async function guardar(): Promise<void> {
  guardando.value = true;
  errorEditor.value = null;
  try {
    const { data } = actual.value
      ? await api.put<{ data: Presupuesto }>(
          `${base.value}/presupuestos/${actual.value.id}`,
          cuerpo(),
        )
      : await api.post<{ data: Presupuesto }>(
          `${base.value}/presupuestos`,
          cuerpo(),
        );
    cargarEnEditor(data.data);
    toast.exito(t("presupuestos.editor.guardado"));
    await cargar();
  } catch (e) {
    errorEditor.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

async function accion(
  ruta: string,
  datos: Record<string, unknown> = {},
): Promise<Presupuesto | null> {
  if (actual.value === null) {
    return null;
  }
  guardando.value = true;
  errorEditor.value = null;
  try {
    const { data } = await api.post<{ data: Presupuesto }>(
      `${base.value}/presupuestos/${actual.value.id}/${ruta}`,
      datos,
    );
    return data.data;
  } catch (e) {
    errorEditor.value = mensajeDeError(e);
    return null;
  } finally {
    guardando.value = false;
  }
}

async function enviar(): Promise<void> {
  const p = await accion("enviar");
  if (p) {
    cargarEnEditor(p);
    toast.exito(
      t("presupuestos.acciones.enviado", { correo: p.contacto.email ?? "" }),
    );
    await cargar();
  }
}
async function aceptar(): Promise<void> {
  if (
    actual.value === null ||
    !(await confirmar(
      t("presupuestos.acciones.confirmarAceptar", {
        folio: actual.value.folio,
      }),
    ))
  ) {
    return;
  }
  const p = await accion("aceptar");
  if (p) {
    cargarEnEditor(p);
    await cargar();
  }
}
const rechazando = ref(false);
const motivoRechazo = ref("");
async function rechazar(): Promise<void> {
  const p = await accion("rechazar", {
    motivo: motivoRechazo.value.trim() || null,
  });
  if (p) {
    rechazando.value = false;
    motivoRechazo.value = "";
    cargarEnEditor(p);
    await cargar();
  }
}
async function convertir(): Promise<void> {
  const p = actual.value;
  if (
    p === null ||
    !(await confirmar(
      t("presupuestos.acciones.confirmarConvertir", {
        total: plata(p.total_minor, p.moneda),
        cliente: p.cliente ?? "",
      }),
    ))
  ) {
    return;
  }
  guardando.value = true;
  try {
    await api.post(`${base.value}/presupuestos/${p.id}/convertir`, {});
    toast.exito(t("presupuestos.acciones.convertido"));
    await abrir(p.id);
    await cargar();
  } catch (e) {
    errorEditor.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}
async function eliminar(): Promise<void> {
  const p = actual.value;
  if (
    p === null ||
    !(await confirmar(
      t("presupuestos.acciones.confirmarEliminar", { folio: p.folio }),
      { peligro: true },
    ))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/presupuestos/${p.id}`);
    abierto.value = false;
    await cargar();
  } catch (e) {
    errorEditor.value = mensajeDeError(e);
  }
}

// ---- Ajustes ----
const ajustesAbiertos = ref(false);
const ajustes = ref({
  impuestos: "incluidos",
  tasa: "16",
  vigencia: 15,
  notas: "",
});
function abrirAjustes(): void {
  ajustes.value = {
    impuestos: config.value.impuestos,
    tasa: String(config.value.tasa_impuesto_bp / 100),
    vigencia: config.value.vigencia_dias,
    notas: config.value.notas ?? "",
  };
  ajustesAbiertos.value = true;
}
async function guardarAjustes(): Promise<void> {
  guardando.value = true;
  try {
    const { data } = await api.put<{ data: Configuracion }>(
      `${base.value}/presupuestos/configuracion`,
      {
        impuestos: ajustes.value.impuestos,
        tasa_impuesto_bp: Math.round(
          (Number.parseFloat(ajustes.value.tasa.replace(",", ".")) || 0) * 100,
        ),
        vigencia_dias: Number(ajustes.value.vigencia) || 15,
        notas: ajustes.value.notas.trim() || null,
      },
    );
    config.value = data.data;
    ajustesAbiertos.value = false;
    toast.exito(t("presupuestos.config.guardado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}

onMounted(() => {
  void cargar();
  void cargarApoyos();
});
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('presupuestos.titulo')"
      :total="lista.length"
    >
      <template v-if="puedeGestionar" #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          data-prueba="ajustes-presupuestos"
          @click="abrirAjustes"
        >
          {{ $t("presupuestos.ajustes") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          data-prueba="nuevo-presupuesto"
          @click="abrirNuevo"
        >
          {{ $t("presupuestos.nuevo") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <div class="mt-5 flex flex-wrap items-center gap-3">
      <div class="tu-segmentado" role="group">
        <button
          v-for="f in FILTROS"
          :key="f"
          type="button"
          :aria-pressed="filtro === f"
          @click="filtro = f"
        >
          {{ $t(`presupuestos.filtros.${f}`) }}
        </button>
      </div>
      <input
        v-model="buscar"
        type="search"
        class="tu-input sm:max-w-xs"
        :placeholder="$t('presupuestos.buscar')"
        :aria-label="$t('presupuestos.buscar')"
      />
    </div>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-else-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="lista.length === 0"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{
        filtro === "todos" && buscar === ""
          ? $t("presupuestos.vacio")
          : $t("presupuestos.sinResultados")
      }}
    </p>
    <div v-else class="mt-5 tu-card overflow-x-auto relative">
      <table class="tu-tabla">
        <thead>
          <tr>
            <th>{{ $t("presupuestos.col.folio") }}</th>
            <th>{{ $t("presupuestos.col.cliente") }}</th>
            <th>{{ $t("presupuestos.col.estado") }}</th>
            <th class="hidden sm:table-cell">
              {{ $t("presupuestos.col.vigencia") }}
            </th>
            <th class="text-right">{{ $t("presupuestos.col.total") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="p in lista"
            :key="p.id"
            class="cursor-pointer"
            data-prueba="fila-presupuesto"
            @click="abrir(p.id)"
          >
            <td class="font-medium tabular-nums">{{ p.folio }}</td>
            <td>{{ p.cliente ?? "—" }}</td>
            <td>
              <span class="inline-flex items-center gap-1.5 text-sm">
                <span
                  class="inline-block size-1.5 rounded-full"
                  :style="{ background: COLOR[estadoVisible(p)] }"
                  aria-hidden="true"
                />
                {{ $t(`presupuestos.estados.${estadoVisible(p)}`) }}
              </span>
            </td>
            <td class="hidden sm:table-cell tabular-nums">
              {{ p.valido_hasta }}
            </td>
            <td class="text-right tabular-nums">
              {{ plata(p.total_minor, p.moneda) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Editor / detalle -->
    <PanelLateral
      :abierto="abierto"
      :titulo="
        actual
          ? $t('presupuestos.editor.titulo', { folio: actual.folio })
          : $t('presupuestos.editor.tituloNuevo')
      "
      @cerrar="abierto = false"
    >
      <form
        id="form-presupuesto"
        class="space-y-4 p-5"
        @submit.prevent="guardar"
      >
        <p
          v-if="actual && !actual.editable"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("presupuestos.editor.soloLectura") }}
        </p>
        <div v-if="actual" class="inline-flex items-center gap-1.5 text-sm">
          <span
            class="inline-block size-1.5 rounded-full"
            :style="{ background: COLOR[estadoVisible(actual)] }"
            aria-hidden="true"
          />
          {{ $t(`presupuestos.estados.${estadoVisible(actual)}`) }}
        </div>

        <!-- Cliente -->
        <fieldset :disabled="soloLectura" class="space-y-2">
          <div class="flex items-center justify-between gap-2">
            <span class="tu-label mb-0">{{
              $t("presupuestos.editor.cliente")
            }}</span>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="borrador.esContacto" type="checkbox" />
              {{ $t("presupuestos.editor.esContacto") }}
            </label>
          </div>
          <BuscarPersona
            v-if="!borrador.esContacto"
            v-model="borrador.persona_id"
            :buscar-en="`${base}/miembros`"
            :deshabilitado="soloLectura"
          />
          <template v-else>
            <input
              v-model="borrador.nombre_contacto"
              class="tu-input"
              :placeholder="$t('presupuestos.editor.contactoNombre')"
              :aria-label="$t('presupuestos.editor.contactoNombre')"
            />
            <div class="grid gap-2 sm:grid-cols-2">
              <input
                v-model="borrador.email_contacto"
                class="tu-input"
                type="email"
                :placeholder="$t('presupuestos.editor.contactoCorreo')"
                :aria-label="$t('presupuestos.editor.contactoCorreo')"
              />
              <input
                v-model="borrador.celular_contacto"
                class="tu-input"
                :placeholder="$t('presupuestos.editor.contactoCelular')"
                :aria-label="$t('presupuestos.editor.contactoCelular')"
              />
            </div>
          </template>
        </fieldset>

        <fieldset :disabled="soloLectura" class="grid gap-3 sm:grid-cols-2">
          <div v-if="sucursales.length > 1">
            <label class="tu-label" for="pr-sucursal">{{
              $t("presupuestos.editor.sucursal")
            }}</label>
            <select
              id="pr-sucursal"
              v-model="borrador.sucursal_id"
              class="tu-input"
            >
              <option value="">—</option>
              <option v-for="s in sucursales" :key="s.id" :value="s.id">
                {{ s.nombre }}
              </option>
            </select>
          </div>
          <div>
            <label class="tu-label" for="pr-vigencia">{{
              $t("presupuestos.editor.vigencia")
            }}</label>
            <input
              id="pr-vigencia"
              v-model="borrador.valido_hasta"
              class="tu-input"
              type="date"
            />
          </div>
        </fieldset>

        <!-- Conceptos -->
        <fieldset :disabled="soloLectura">
          <legend class="tu-label">
            {{ $t("presupuestos.editor.conceptos") }}
          </legend>
          <div class="space-y-3">
            <div
              v-for="(l, i) in borrador.lineas"
              :key="i"
              class="rounded-xl border p-3 space-y-2"
              :style="{ borderColor: 'var(--borde)' }"
              data-prueba="concepto"
            >
              <div class="grid gap-2 sm:grid-cols-2">
                <select
                  v-model="l.tipo"
                  class="tu-input"
                  :aria-label="$t('presupuestos.editor.tipo')"
                  @change="l.referencia = ''"
                >
                  <option
                    v-for="tp in [
                      'servicio',
                      'producto',
                      'articulo',
                      'libre',
                    ] as const"
                    :key="tp"
                    :value="tp"
                  >
                    {{ $t(`presupuestos.editor.tipos.${tp}`) }}
                  </option>
                </select>
                <select
                  v-if="l.tipo !== 'libre'"
                  v-model="l.referencia"
                  class="tu-input"
                  :aria-label="$t('presupuestos.editor.elige')"
                  @change="alElegir(i)"
                >
                  <option value="">
                    {{ $t("presupuestos.editor.elige") }}
                  </option>
                  <option
                    v-for="o in opcionesDe(l.tipo)"
                    :key="o.id"
                    :value="o.id"
                  >
                    {{ o.nombre }}
                  </option>
                </select>
              </div>
              <input
                v-model="l.descripcion"
                class="tu-input"
                :placeholder="$t('presupuestos.editor.descripcion')"
                :aria-label="$t('presupuestos.editor.descripcion')"
              />
              <div class="grid grid-cols-3 gap-2">
                <label class="text-xs">
                  {{ $t("presupuestos.editor.cantidad") }}
                  <input
                    v-model.number="l.cantidad"
                    class="tu-input mt-1"
                    type="number"
                    min="1"
                    max="999"
                  />
                </label>
                <label class="text-xs">
                  {{ $t("presupuestos.editor.precio") }}
                  <input
                    v-model="l.precio"
                    class="tu-input mt-1"
                    inputmode="decimal"
                  />
                </label>
                <label class="text-xs">
                  {{ $t("presupuestos.editor.descuentoLinea") }}
                  <input
                    v-model="l.descuento"
                    class="tu-input mt-1"
                    inputmode="decimal"
                  />
                </label>
              </div>
              <div class="flex items-center justify-between text-sm">
                <button
                  v-if="!soloLectura && borrador.lineas.length > 1"
                  type="button"
                  class="tu-enlace text-xs"
                  :style="{ color: 'var(--error)' }"
                  @click="borrador.lineas.splice(i, 1)"
                >
                  {{ $t("presupuestos.editor.quitar") }}
                </button>
                <span v-else />
                <span class="tabular-nums font-medium">{{
                  plata(
                    Math.max(
                      0,
                      Math.max(1, l.cantidad) * aMinor(l.precio) -
                        aMinor(l.descuento),
                    ),
                    moneda,
                  )
                }}</span>
              </div>
            </div>
          </div>
          <button
            v-if="!soloLectura"
            type="button"
            class="tu-btn tu-btn-fantasma mt-3"
            data-prueba="agregar-concepto"
            @click="nuevaLinea()"
          >
            {{ $t("presupuestos.editor.agregar") }}
          </button>
        </fieldset>

        <fieldset :disabled="soloLectura" class="space-y-3">
          <div>
            <label class="tu-label" for="pr-descuento">{{
              $t("presupuestos.editor.descuentoGeneral")
            }}</label>
            <input
              id="pr-descuento"
              v-model="borrador.descuento"
              class="tu-input sm:max-w-[12rem]"
              inputmode="decimal"
            />
          </div>
          <div>
            <label class="tu-label" for="pr-notas">{{
              $t("presupuestos.editor.notas")
            }}</label>
            <textarea
              id="pr-notas"
              v-model="borrador.notas"
              class="tu-input"
              rows="3"
              maxlength="2000"
            />
          </div>
        </fieldset>

        <!-- Totales -->
        <dl class="space-y-1 text-sm" data-prueba="totales-presupuesto">
          <div class="flex justify-between">
            <dt>{{ $t("presupuestos.editor.subtotal") }}</dt>
            <dd class="tabular-nums">{{ plata(totales.subtotal, moneda) }}</dd>
          </div>
          <div v-if="totales.descuento > 0" class="flex justify-between">
            <dt>{{ $t("presupuestos.editor.descuento") }}</dt>
            <dd class="tabular-nums">
              −{{ plata(totales.descuento, moneda) }}
            </dd>
          </div>
          <div
            v-if="totales.impuestos === 'desglosados'"
            class="flex justify-between"
          >
            <dt>
              {{ $t("presupuestos.editor.impuestos", { tasa: tasaTexto }) }}
            </dt>
            <dd class="tabular-nums">{{ plata(totales.impuesto, moneda) }}</dd>
          </div>
          <div class="flex justify-between font-semibold text-base">
            <dt>{{ $t("presupuestos.editor.total") }}</dt>
            <dd class="tabular-nums">{{ plata(totales.total, moneda) }}</dd>
          </div>
          <p
            v-if="totales.impuestos === 'incluidos'"
            class="text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("presupuestos.editor.incluidos", { tasa: tasaTexto }) }}
          </p>
        </dl>

        <p v-if="errorEditor" class="text-sm" style="color: var(--error)">
          {{ errorEditor }}
        </p>

        <!-- Rechazo con motivo -->
        <div v-if="rechazando" class="space-y-2">
          <label class="tu-label" for="pr-motivo">{{
            $t("presupuestos.acciones.motivoRechazo")
          }}</label>
          <input id="pr-motivo" v-model="motivoRechazo" class="tu-input" />
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="guardando"
            @click="rechazar"
          >
            {{ $t("presupuestos.acciones.rechazar") }}
          </button>
        </div>
      </form>

      <template #pie>
        <div class="flex flex-wrap items-center justify-end gap-2 p-4">
          <button
            v-if="actual?.estado === 'borrador' && puedeGestionar"
            type="button"
            class="tu-enlace text-sm mr-auto"
            :style="{ color: 'var(--error)' }"
            @click="eliminar"
          >
            {{ $t("presupuestos.acciones.eliminar") }}
          </button>
          <RouterLink
            v-if="actual"
            :to="{ name: 'presupuesto-impreso', params: { id: actual.id } }"
            class="tu-btn tu-btn-fantasma"
            target="_blank"
          >
            {{ $t("presupuestos.acciones.imprimir") }}
          </RouterLink>
          <template v-if="actual?.editable && puedeGestionar">
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="guardando"
              @click="enviar"
            >
              {{ $t("presupuestos.acciones.enviar") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="guardando"
              @click="rechazando = !rechazando"
            >
              {{ $t("presupuestos.acciones.rechazar") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="guardando"
              data-prueba="aceptar-presupuesto"
              @click="aceptar"
            >
              {{ $t("presupuestos.acciones.aceptar") }}
            </button>
          </template>
          <button
            v-if="actual?.puede_convertir && puedeGestionar && puedeCobrar"
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="guardando"
            data-prueba="convertir-presupuesto"
            @click="convertir"
          >
            {{ $t("presupuestos.acciones.convertir") }}
          </button>
          <RouterLink
            v-if="actual?.orden"
            :to="{ name: 'cobranza' }"
            class="tu-btn tu-btn-fantasma"
          >
            {{ $t("presupuestos.acciones.verCobro") }}
          </RouterLink>
          <button
            v-if="!soloLectura"
            type="submit"
            form="form-presupuesto"
            class="tu-btn tu-btn-primario"
            :disabled="guardando"
            data-prueba="guardar-presupuesto"
          >
            {{ $t("presupuestos.editor.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>

    <!-- Ajustes -->
    <PanelLateral
      :abierto="ajustesAbiertos"
      :titulo="$t('presupuestos.config.titulo')"
      @cerrar="ajustesAbiertos = false"
    >
      <form
        id="form-ajustes-presupuestos"
        class="space-y-4 p-5"
        @submit.prevent="guardarAjustes"
      >
        <fieldset class="space-y-2">
          <legend class="tu-label">
            {{ $t("presupuestos.config.impuestos") }}
          </legend>
          <label class="flex items-start gap-2 text-sm">
            <input
              v-model="ajustes.impuestos"
              type="radio"
              value="incluidos"
              class="mt-1"
            />
            <span>
              <span class="block font-medium">{{
                $t("presupuestos.config.incluidos")
              }}</span>
              <span :style="{ color: 'var(--texto-suave)' }">{{
                $t("presupuestos.config.incluidosAyuda")
              }}</span>
            </span>
          </label>
          <label class="flex items-start gap-2 text-sm">
            <input
              v-model="ajustes.impuestos"
              type="radio"
              value="desglosados"
              class="mt-1"
            />
            <span>
              <span class="block font-medium">{{
                $t("presupuestos.config.desglosados")
              }}</span>
              <span :style="{ color: 'var(--texto-suave)' }">{{
                $t("presupuestos.config.desglosadosAyuda")
              }}</span>
            </span>
          </label>
        </fieldset>
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="tu-label" for="pr-tasa">{{
              $t("presupuestos.config.tasa")
            }}</label>
            <input
              id="pr-tasa"
              v-model="ajustes.tasa"
              class="tu-input"
              inputmode="decimal"
            />
          </div>
          <div>
            <label class="tu-label" for="pr-vig">{{
              $t("presupuestos.config.vigencia")
            }}</label>
            <input
              id="pr-vig"
              v-model.number="ajustes.vigencia"
              class="tu-input"
              type="number"
              min="1"
              max="365"
            />
          </div>
        </div>
        <div>
          <label class="tu-label" for="pr-notas-omision">{{
            $t("presupuestos.config.notas")
          }}</label>
          <textarea
            id="pr-notas-omision"
            v-model="ajustes.notas"
            class="tu-input"
            rows="3"
            maxlength="2000"
          />
          <p class="tu-hint mt-1">{{ $t("presupuestos.config.notasAyuda") }}</p>
        </div>
        <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("presupuestos.config.congelados") }}
        </p>
      </form>
      <template #pie>
        <div class="flex justify-end p-4">
          <button
            type="submit"
            form="form-ajustes-presupuestos"
            class="tu-btn tu-btn-primario"
            :disabled="guardando"
          >
            {{ $t("presupuestos.config.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>
