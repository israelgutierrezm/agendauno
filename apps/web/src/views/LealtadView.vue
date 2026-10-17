<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import BuscarPersona from "@/components/BuscarPersona.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import AvisoTono from "@/components/AvisoTono.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import InterruptorSiNo from "@/components/InterruptorSiNo.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { dinero } from "@/lib/formato";
import { useDescartarCambios } from "@/lib/editorFormulario";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Programa {
  activa: boolean;
  puntos_por_asistencia: number;
  puntos_por_moneda: number;
}
interface Recompensa {
  id: string;
  nombre: string;
  descripcion: string | null;
  costo_puntos: number;
  activa: boolean;
}
interface Canje {
  id: string;
  persona: string | null;
  recompensa: string;
  puntos: number;
  estado: string;
  creado_en: string | null;
}
interface Movimiento {
  id: string;
  tipo: string;
  origen: string;
  puntos: number;
  saldo_posterior: number;
  descripcion: string | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
// Una unidad de la moneda del negocio, para la ayuda de los puntos («$1», «1 €»).
const unaUnidad = computed(() =>
  dinero(100, sesion.moneda, sesion.pais, { maximumFractionDigits: 0 }),
);
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("lealtad.gestionar"));

const cargando = ref(true);
const error = ref<string | null>(null);
const aviso = ref<string | null>(null);

const programa = ref<Programa>({
  activa: false,
  puntos_por_asistencia: 0,
  puntos_por_moneda: 0,
});
const recompensas = ref<Recompensa[]>([]);
const canjes = ref<Canje[]>([]);
// Canjes por páginas: los que faltan por entregar (en orden de llegada) aparte del
// historial; el resumen (sin búsqueda) alimenta los indicadores.
interface MetaCanjes {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  conteos?: { pendientes: number; historial: number };
  resumen?: { pendientes: number; puntos_entregados: number };
}
const vistaCanjes = ref<"pendientes" | "historial">("pendientes");
const buscarCanjes = ref("");
const paginaCanjes = ref(1);
const metaCanjes = ref<MetaCanjes | null>(null);
async function cargarCanjes(): Promise<void> {
  const { data } = await api.get<{ data: Canje[]; meta?: MetaCanjes }>(
    `${base.value}/lealtad/canjes`,
    {
      params: {
        vista: vistaCanjes.value,
        page: paginaCanjes.value,
        ...(buscarCanjes.value.trim() !== ""
          ? { q: buscarCanjes.value.trim() }
          : {}),
      },
    },
  );
  canjes.value = data.data;
  metaCanjes.value = data.meta ?? null;
}
async function recargarCanjes(): Promise<void> {
  try {
    await cargarCanjes();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
function irPaginaCanjes(n: number): void {
  paginaCanjes.value = n;
  void recargarCanjes();
}
watch(vistaCanjes, () => {
  paginaCanjes.value = 1;
  void recargarCanjes();
});
let esperaBusqueda: ReturnType<typeof setTimeout> | undefined;
watch(buscarCanjes, () => {
  clearTimeout(esperaBusqueda);
  esperaBusqueda = setTimeout(() => {
    paginaCanjes.value = 1;
    void recargarCanjes();
  }, 300);
});

const nueva = ref({ nombre: "", descripcion: "", costo_puntos: 100 });
const nuevaRecompensa = ref(false);
const creandoRecompensa = ref(false);
const editorPrograma = ref(false);
const guardandoPrograma = ref(false);
const borradorPrograma = ref<Programa>({ ...programa.value });
// Con cambios sin guardar, cerrar o salir pregunta antes (patrón de formularios).
const descartarPrograma = useDescartarCambios({
  abierto: editorPrograma,
  valor: () => borradorPrograma.value,
  guardando: guardandoPrograma,
});
const descartarRecompensa = useDescartarCambios({
  abierto: nuevaRecompensa,
  valor: () => nueva.value,
  guardando: creandoRecompensa,
});
function abrirPrograma(): void {
  borradorPrograma.value = { ...programa.value };
  error.value = null;
  descartarPrograma.fijar();
  editorPrograma.value = true;
}
function cerrarPrograma(): void {
  void descartarPrograma.cerrar();
}
function abrirRecompensa(): void {
  error.value = null;
  descartarRecompensa.fijar();
  nuevaRecompensa.value = true;
}
function cerrarRecompensa(): void {
  void descartarRecompensa.cerrar();
}

const miembroSel = ref("");
const saldo = ref<number | null>(null);
const movimientos = ref<Movimiento[]>([]);
// Por páginas: un cliente frecuente junta decenas de movimientos al mes.
const metaMovimientos = ref<{
  page: number;
  ultima_pagina: number;
  total: number;
} | null>(null);
const cargandoMasMovimientos = ref(false);
const recompensaSel = ref("");
const ajuste = ref({ puntos: 0, descripcion: "" });

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [prog, recs] = await Promise.all([
      api.get<{ data: Programa }>(`${base.value}/lealtad/programa`),
      api.get<{ data: Recompensa[] }>(`${base.value}/lealtad/recompensas`),
      cargarCanjes(),
    ]);
    programa.value = prog.data.data;
    recompensas.value = recs.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// Activo sin puntos por asistir ni por comprar, nadie juntaría nada.
const programaSinPuntos = computed(
  () =>
    borradorPrograma.value.activa &&
    Number(borradorPrograma.value.puntos_por_asistencia || 0) === 0 &&
    Number(borradorPrograma.value.puntos_por_moneda || 0) === 0,
);

async function guardarPrograma(): Promise<void> {
  if (!puedeGestionar.value || guardandoPrograma.value) return;
  guardandoPrograma.value = true;
  aviso.value = null;
  error.value = null;
  try {
    const { data } = await api.put<{ data: Programa }>(
      `${base.value}/lealtad/programa`,
      borradorPrograma.value,
    );
    programa.value = data.data;
    aviso.value = t("lealtad.guardado");
    descartarPrograma.fijar();
    editorPrograma.value = false;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardandoPrograma.value = false;
  }
}

async function crearRecompensa(): Promise<void> {
  if (!puedeGestionar.value || creandoRecompensa.value) return;
  creandoRecompensa.value = true;
  aviso.value = null;
  error.value = null;
  try {
    await api.post(`${base.value}/lealtad/recompensas`, {
      nombre: nueva.value.nombre,
      descripcion: nueva.value.descripcion || null,
      costo_puntos: Number(nueva.value.costo_puntos) || 1,
    });
    aviso.value = t("lealtad.recompensaCreada", { nombre: nueva.value.nombre });
    nueva.value = { nombre: "", descripcion: "", costo_puntos: 100 };
    descartarRecompensa.fijar();
    nuevaRecompensa.value = false;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    creandoRecompensa.value = false;
  }
}

async function toggleRecompensa(r: Recompensa): Promise<void> {
  error.value = null;
  try {
    await api.put(`${base.value}/lealtad/recompensas/${r.id}`, {
      nombre: r.nombre,
      descripcion: r.descripcion,
      costo_puntos: r.costo_puntos,
      activa: !r.activa,
    });
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function verMiembro(): Promise<void> {
  saldo.value = null;
  movimientos.value = [];
  if (miembroSel.value === "") {
    return;
  }
  try {
    const { data } = await api.get<{
      data: { saldo: number; movimientos: Movimiento[] };
      meta?: { page: number; ultima_pagina: number; total: number };
    }>(`${base.value}/miembros/${miembroSel.value}/puntos`, {
      params: { page: 1 },
    });
    saldo.value = data.data.saldo;
    movimientos.value = data.data.movimientos;
    metaMovimientos.value = data.meta ?? null;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
async function masMovimientos(): Promise<void> {
  const meta = metaMovimientos.value;
  if (miembroSel.value === "" || !meta || meta.page >= meta.ultima_pagina) {
    return;
  }
  cargandoMasMovimientos.value = true;
  try {
    const { data } = await api.get<{
      data: { movimientos: Movimiento[] };
      meta?: { page: number; ultima_pagina: number; total: number };
    }>(`${base.value}/miembros/${miembroSel.value}/puntos`, {
      params: { page: meta.page + 1 },
    });
    movimientos.value = [...movimientos.value, ...data.data.movimientos];
    metaMovimientos.value = data.meta ?? null;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargandoMasMovimientos.value = false;
  }
}

async function canjear(): Promise<void> {
  aviso.value = null;
  error.value = null;
  if (miembroSel.value === "" || recompensaSel.value === "") {
    return;
  }
  if (
    !(await confirmar(t("confirmaciones.canjear"), {
      aceptar: t("confirmaciones.canjearAceptar"),
    }))
  ) {
    return;
  }
  try {
    await api.post(`${base.value}/lealtad/canjes`, {
      persona_id: miembroSel.value,
      recompensa_id: recompensaSel.value,
    });
    recompensaSel.value = "";
    aviso.value = t("lealtad.canjeado");
    await Promise.all([verMiembro(), cargar()]);
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function ajustar(): Promise<void> {
  aviso.value = null;
  error.value = null;
  if (miembroSel.value === "" || Number(ajuste.value.puntos) === 0) {
    return;
  }
  if (
    !(await confirmar(
      t("confirmaciones.ajustarPuntos", {
        puntos: Number(ajuste.value.puntos),
      }),
      { aceptar: t("confirmaciones.ajustar") },
    ))
  ) {
    return;
  }
  try {
    await api.post(`${base.value}/miembros/${miembroSel.value}/puntos/ajuste`, {
      puntos: Number(ajuste.value.puntos),
      descripcion: ajuste.value.descripcion || null,
    });
    ajuste.value = { puntos: 0, descripcion: "" };
    aviso.value = t("lealtad.ajustado");
    await verMiembro();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function accionCanje(
  c: Canje,
  accion: "entregar" | "cancelar",
): Promise<void> {
  const seguir =
    accion === "cancelar"
      ? await confirmar(t("confirmaciones.cancelarCanje"), {
          aceptar: t("confirmaciones.cancelarCanjeAceptar"),
          peligro: true,
        })
      : await confirmar(t("confirmaciones.entregarCanje"), {
          aceptar: t("confirmaciones.entregar"),
        });
  if (!seguir) {
    return;
  }
  error.value = null;
  try {
    await api.post(`${base.value}/lealtad/canjes/${c.id}/${accion}`, {});
    await Promise.all([cargar(), verMiembro()]);
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

onMounted(cargar);

// Indicadores del programa (patrón de los listados).
const indicadores = computed<Indicador[]>(() => {
  const pendientes =
    metaCanjes.value?.resumen?.pendientes ??
    canjes.value.filter((c) => c.estado === "pendiente").length;
  return [
    {
      clave: "programa",
      etiqueta: t("lealtadVisual.kpi.programa"),
      valor: programa.value.activa
        ? t("lealtad.programa.activa")
        : t("lealtadVisual.inactivo"),
      icono: "lealtad",
    },
    {
      clave: "recompensas",
      etiqueta: t("lealtadVisual.kpi.recompensas"),
      valor: String(recompensas.value.filter((r) => r.activa).length),
      icono: "estrella",
    },
    {
      clave: "pendientes",
      etiqueta: t("lealtadVisual.kpi.pendientes"),
      valor: String(pendientes),
      icono: "reloj",
      aviso: pendientes > 0,
    },
    {
      clave: "canjeados",
      etiqueta: t("lealtadVisual.kpi.canjeados"),
      valor: String(
        metaCanjes.value?.resumen?.puntos_entregados ??
          canjes.value
            .filter((c) => c.estado === "entregado")
            .reduce((suma, c) => suma + c.puntos, 0),
      ),
      icono: "hecho",
    },
  ];
});
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('lealtad.titulo')"
      :subtitulo="$t('lealtadVisual.subtitulo')"
    />

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p v-if="aviso" class="mt-4 text-sm" style="color: var(--exito)">
      {{ aviso }}
    </p>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />
      <!-- Programa -->
      <div class="mt-5 tu-card p-5">
        <div class="flex items-center justify-between gap-3">
          <h2 class="font-medium">{{ $t("lealtad.programa.titulo") }}</h2>
          <span
            class="tu-badge"
            :class="programa.activa ? 'tu-badge-exito' : ''"
            >{{
              $t(
                programa.activa
                  ? "adminUsabilidad.estadoActivo"
                  : "adminUsabilidad.estadoInactivo",
              )
            }}</span
          >
        </div>
        <p class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("lealtad.programa.porAsistencia") }}:
          {{ programa.puntos_por_asistencia }} ·
          {{ $t("lealtad.programa.porMoneda") }}:
          {{ programa.puntos_por_moneda }}
        </p>
        <button
          v-if="puedeGestionar"
          type="button"
          class="tu-btn tu-btn-fantasma mt-4"
          @click="abrirPrograma"
        >
          {{ $t("adminUsabilidad.configurarPrograma") }}
        </button>
      </div>
      <ModalDialogo
        v-if="puedeGestionar"
        :abierto="editorPrograma"
        :titulo="$t('adminUsabilidad.configurarPrograma')"
        icono="lealtad"
        :ocupado="guardandoPrograma"
        @cerrar="cerrarPrograma"
      >
        <form @submit.prevent="guardarPrograma">
          <AvisoTono v-if="error" tono="peligro" class="mb-4" role="alert">
            {{ error }}
          </AvisoTono>
          <InterruptorSiNo
            v-model="borradorPrograma.activa"
            :etiqueta="$t('lealtad.programa.activa')"
            :deshabilitado="!puedeGestionar"
            prueba="lealtad-activa"
          />
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <label class="tu-label" for="ppa">{{
                $t("lealtad.programa.porAsistencia")
              }}</label>
              <input
                id="ppa"
                v-model.number="borradorPrograma.puntos_por_asistencia"
                type="number"
                min="0"
                class="tu-input"
                :disabled="!puedeGestionar"
              />
            </div>
            <div>
              <label class="tu-label" for="ppm">{{
                $t("lealtad.programa.porMoneda")
              }}</label>
              <input
                id="ppm"
                v-model.number="borradorPrograma.puntos_por_moneda"
                type="number"
                min="0"
                class="tu-input"
                :disabled="!puedeGestionar"
              />
              <span class="tu-hint" data-prueba="ayuda-por-moneda">{{
                $t("lealtad.programa.porMonedaAyuda", { monto: unaUnidad })
              }}</span>
            </div>
          </div>
          <AvisoTono
            v-if="programaSinPuntos"
            tono="aviso"
            class="mt-4"
            data-prueba="lealtad-sin-puntos"
          >
            {{ $t("operacion.lealtad.sinPuntos") }}
          </AvisoTono>
          <div class="mt-4 flex justify-end gap-2">
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="guardandoPrograma"
              @click="cerrarPrograma"
            >
              {{ $t("comun.cancelar") }}
            </button>
            <button
              v-if="puedeGestionar"
              type="submit"
              class="tu-btn tu-btn-primario"
              :disabled="guardandoPrograma || programaSinPuntos"
            >
              {{ $t("comun.guardar") }}
            </button>
          </div>
        </form>
      </ModalDialogo>

      <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <!-- Recompensas -->
        <div class="tu-card p-5">
          <div class="flex flex-wrap justify-between gap-3 items-center">
            <h2 class="font-medium">{{ $t("lealtad.recompensas.titulo") }}</h2>
            <button
              v-if="puedeGestionar"
              type="button"
              class="tu-btn tu-btn-fantasma"
              @click="abrirRecompensa"
            >
              {{ $t("adminUsabilidad.recompensas.nueva") }}
            </button>
          </div>
          <EstadoVacio
            v-if="recompensas.length === 0"
            class="mt-3 py-6"
            icono="lealtad"
            compacto
            :titulo="$t('lealtad.recompensas.vacio')"
          />
          <ul v-else class="mt-3 space-y-2">
            <li
              v-for="r in recompensas"
              :key="r.id"
              class="flex items-center justify-between gap-3 border-t pt-2"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <div class="min-w-0">
                <div class="font-semibold truncate">{{ r.nombre }}</div>
                <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                  {{ r.costo_puntos }} {{ $t("lealtad.puntos") }}
                </div>
              </div>
              <button
                v-if="puedeGestionar"
                type="button"
                class="tu-badge"
                :class="r.activa ? 'tu-badge-exito' : ''"
                @click="toggleRecompensa(r)"
              >
                {{
                  r.activa
                    ? $t("lealtad.recompensas.activa")
                    : $t("lealtad.recompensas.inactiva")
                }}
              </button>
              <span
                v-else
                class="tu-badge"
                :class="r.activa ? 'tu-badge-exito' : ''"
                >{{
                  r.activa
                    ? $t("lealtad.recompensas.activa")
                    : $t("lealtad.recompensas.inactiva")
                }}</span
              >
            </li>
          </ul>

          <ModalDialogo
            v-if="puedeGestionar"
            :abierto="nuevaRecompensa"
            :titulo="$t('adminUsabilidad.recompensas.nueva')"
            tam="md"
            icono="lealtad"
            :ocupado="creandoRecompensa"
            @cerrar="cerrarRecompensa"
          >
            <form
              v-if="puedeGestionar"
              class="space-y-4"
              @submit.prevent="crearRecompensa"
            >
              <p v-if="error" role="alert" :style="{ color: 'var(--error)' }">
                {{ error }}
              </p>
              <label class="block"
                ><span class="tu-label">{{
                  $t("adminUsabilidad.recompensas.nombre")
                }}</span
                ><input
                  v-model="nueva.nombre"
                  class="tu-input"
                  :placeholder="$t('adminUsabilidad.recompensas.ejemplo')"
                  required
              /></label>
              <label class="block"
                ><span class="tu-label">{{
                  $t("adminUsabilidad.recompensas.costo")
                }}</span
                ><input
                  v-model.number="nueva.costo_puntos"
                  type="number"
                  min="1"
                  class="tu-input"
                  :placeholder="$t('lealtad.puntos')"
                  required
              /></label>
              <div class="flex justify-end gap-2">
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma"
                  :disabled="creandoRecompensa"
                  @click="cerrarRecompensa"
                >
                  {{ $t("comun.cancelar") }}
                </button>
                <button
                  type="submit"
                  class="tu-btn tu-btn-primario whitespace-nowrap"
                  :disabled="creandoRecompensa || !nueva.nombre.trim()"
                >
                  {{ $t("lealtad.recompensas.crear") }}
                </button>
              </div>
            </form>
          </ModalDialogo>
        </div>

        <!-- Consulta por miembro -->
        <div class="tu-card p-5">
          <h2 class="font-medium">{{ $t("lealtad.miembro.titulo") }}</h2>
          <BuscarPersona
            v-model="miembroSel"
            class="mt-3"
            :buscar-en="`${base}/miembros`"
            :parametros="{ tipo: 'miembro' }"
            :placeholder="$t('lealtad.miembro.elige')"
            @update:model-value="verMiembro"
          />

          <template v-if="miembroSel !== '' && saldo !== null">
            <div class="mt-4 flex items-end justify-between">
              <div>
                <div class="text-3xl font-semibold">{{ saldo }}</div>
                <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                  {{ $t("lealtad.miembro.saldo") }}
                </div>
              </div>
            </div>

            <div v-if="puedeGestionar" class="mt-4 space-y-3">
              <div class="flex gap-2">
                <select v-model="recompensaSel" class="tu-input">
                  <option value="">{{ $t("lealtad.miembro.canjear") }}…</option>
                  <option
                    v-for="r in recompensas.filter((x) => x.activa)"
                    :key="r.id"
                    :value="r.id"
                  >
                    {{ r.nombre }} · {{ r.costo_puntos }}
                  </option>
                </select>
                <button
                  type="button"
                  class="tu-btn tu-btn-primario whitespace-nowrap"
                  :disabled="recompensaSel === ''"
                  @click="canjear"
                >
                  {{ $t("lealtad.miembro.canjearBtn") }}
                </button>
              </div>
              <div class="flex gap-2">
                <input
                  v-model.number="ajuste.puntos"
                  type="number"
                  class="tu-input w-28"
                  :placeholder="$t('lealtad.miembro.ajustePh')"
                />
                <input
                  v-model="ajuste.descripcion"
                  class="tu-input flex-1"
                  :placeholder="$t('lealtad.miembro.motivo')"
                />
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma whitespace-nowrap"
                  @click="ajustar"
                >
                  {{ $t("lealtad.miembro.ajustar") }}
                </button>
              </div>
            </div>

            <ul class="mt-4 space-y-1 text-sm">
              <li
                v-for="m in movimientos"
                :key="m.id"
                class="flex items-center justify-between gap-2 border-t pt-1"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <span
                  class="truncate"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ m.descripcion ?? $t(`lealtad.origen.${m.origen}`) }}</span
                >
                <span
                  class="font-semibold"
                  :style="{
                    color: m.puntos >= 0 ? 'var(--exito)' : 'var(--error)',
                  }"
                >
                  {{ m.puntos >= 0 ? "+" : "" }}{{ m.puntos }}
                </span>
              </li>
            </ul>
            <button
              v-if="
                metaMovimientos && movimientos.length < metaMovimientos.total
              "
              type="button"
              class="tu-enlace mt-2 text-sm"
              :disabled="cargandoMasMovimientos"
              data-prueba="ver-mas-puntos"
              @click="masMovimientos"
            >
              {{
                cargandoMasMovimientos
                  ? $t("comun.cargando")
                  : $t("lealtad.miembro.verMas", {
                      n: metaMovimientos.total - movimientos.length,
                    })
              }}
            </button>
          </template>
        </div>
      </div>

      <!-- Canjes recientes -->
      <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-medium">
          {{ $t("lealtad.canjes.titulo") }}
        </h2>
        <div class="tu-segmentado" role="group" data-prueba="vista-canjes">
          <button
            v-for="v in ['pendientes', 'historial'] as const"
            :key="v"
            type="button"
            :aria-pressed="vistaCanjes === v"
            :data-prueba="`canjes-${v}`"
            @click="vistaCanjes = v"
          >
            {{
              v === "pendientes"
                ? $t("operacion.canjes.porEntregar")
                : $t("operacion.canjes.historial")
            }}
            <span
              v-if="metaCanjes?.conteos"
              class="ml-1 tabular-nums"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ metaCanjes.conteos[v] }}</span
            >
          </button>
        </div>
      </div>
      <input
        v-model="buscarCanjes"
        type="search"
        class="tu-input mt-3 max-w-sm"
        :placeholder="$t('operacion.canjes.buscar')"
        :aria-label="$t('operacion.canjes.buscar')"
        data-prueba="buscar-canjes"
      />
      <EstadoVacio
        v-if="canjes.length === 0"
        class="mt-3 py-6"
        icono="lealtad"
        compacto
        :titulo="
          buscarCanjes.trim() !== ''
            ? $t('operacion.listadosDos.sinResultados')
            : vistaCanjes === 'pendientes'
              ? $t('operacion.canjes.sinPendientes')
              : $t('operacion.canjes.sinHistorial')
        "
      />
      <div v-else class="mt-3 tu-card overflow-x-auto">
        <table class="tu-tabla">
          <thead>
            <tr>
              <th>
                {{ $t("lealtad.canjes.colMiembro") }}
              </th>
              <th>
                {{ $t("lealtad.canjes.colRecompensa") }}
              </th>
              <th class="text-right">
                {{ $t("lealtad.puntos") }}
              </th>
              <th>
                {{ $t("lealtad.canjes.colEstado") }}
              </th>
              <th class="text-right">
                {{ $t("lealtad.canjes.colAccion") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in canjes" :key="c.id">
              <td class="font-semibold">{{ c.persona }}</td>
              <td>{{ c.recompensa }}</td>
              <td class="text-right">{{ c.puntos }}</td>
              <td>
                <span
                  class="tu-badge"
                  :class="
                    c.estado === 'entregado'
                      ? 'tu-badge-exito'
                      : c.estado === 'cancelado'
                        ? ''
                        : 'tu-badge-aviso'
                  "
                >
                  {{ $t(`lealtad.estados.${c.estado}`) }}
                </span>
              </td>
              <td class="text-right">
                <span
                  v-if="puedeGestionar && c.estado === 'pendiente'"
                  class="inline-flex gap-2 justify-end"
                >
                  <button
                    type="button"
                    class="tu-btn tu-btn-suave tu-tono-exito tu-btn-chico whitespace-nowrap"
                    @click="accionCanje(c, 'entregar')"
                  >
                    {{ $t("lealtad.canjes.entregar") }}
                  </button>
                  <button
                    type="button"
                    class="tu-btn tu-btn-contorno tu-tono-peligro tu-btn-chico whitespace-nowrap"
                    @click="accionCanje(c, 'cancelar')"
                  >
                    {{ $t("lealtad.canjes.cancelar") }}
                  </button>
                </span>
                <span v-else :style="{ color: 'var(--texto-suave)' }">—</span>
              </td>
            </tr>
          </tbody>
        </table>
        <PaginacionListado
          v-if="metaCanjes && metaCanjes.ultima_pagina > 1"
          :page="metaCanjes.page"
          :ultima-pagina="metaCanjes.ultima_pagina"
          :total="metaCanjes.total"
          :per-page="metaCanjes.per_page"
          @ir="irPaginaCanjes"
        />
      </div>
    </template>
  </section>
</template>
