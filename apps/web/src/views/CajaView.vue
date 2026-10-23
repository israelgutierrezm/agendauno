<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PestanasSeccion from "@/components/PestanasSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useDescartarCambios } from "@/lib/editorFormulario";
import {
  fechaEnUrl,
  useFiltrosEnUrl,
  usePestanaEnUrl,
} from "@/lib/filtrosEnUrl";
import { aMinor, dinero as dineroDelPais } from "@/lib/formato";
import { useSucursales } from "@/lib/sucursalOperativa";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * La caja de cada sucursal (ADR 0175). Quien opera la abre con su fondo, registra los
 * retiros e ingresos de efectivo y la cuenta a ciegas al cerrar; si no cuadra, escribe
 * el motivo. Quien supervisa ve además lo esperado en vivo y, en «Cierres», el historial
 * con las diferencias por revisar.
 */
type Metodo = "efectivo" | "tarjeta" | "transferencia" | "ventanilla";
const METODOS: Metodo[] = [
  "efectivo",
  "tarjeta",
  "transferencia",
  "ventanilla",
];

interface Movimiento {
  id: string;
  tipo: string;
  metodo: Metodo;
  monto_minor: number;
  motivo: string | null;
  quien: string | null;
  fecha: string | null;
}
interface Conteo {
  metodo: Metodo;
  esperado_minor: number;
  contado_minor: number;
  diferencia_minor: number;
}
interface Turno {
  id: string;
  sucursal: { id: string | null; nombre: string | null };
  estado: "abierta" | "contada" | "cerrada";
  moneda: string;
  fondo_minor: number;
  abierta_por: string | null;
  abierta_en: string;
  cerrada_por: string | null;
  cerrada_en: string | null;
  diferencia_minor: number | null;
  motivo_diferencia: string | null;
  requiere_revision: boolean;
  revisada_por: string | null;
  revisada_en: string | null;
  nota_revision: string | null;
  esperado: Record<Metodo, number> | null;
  conteos: Conteo[];
  movimientos: Movimiento[];
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const sucursales = useSucursales();
const supervisa = computed(() => sesion.puede("caja.supervisar"));
const base = computed(() => `/api/v1/app/${sesion.slug}`);
// «Caja | Cierres» en la dirección (`?pestana=cierres`): recargar o compartir el
// enlace abre la misma. Los cierres solo los ve quien supervisa.
type PestanaCaja = "caja" | "cierres";
const pestanas = computed<PestanaCaja[]>(() =>
  supervisa.value ? ["caja", "cierres"] : ["caja"],
);
const pestana = usePestanaEnUrl(pestanas);
const opcionesPestana = computed(() =>
  pestanas.value.map((clave) => ({
    clave,
    texto: clave === "caja" ? t("caja.pestanaCaja") : t("caja.pestanaCierres"),
  })),
);

function dinero(minor: number): string {
  return dineroDelPais(minor, sesion.moneda, sesion.pais);
}
// En la hora del negocio, no la del navegador.
function fechaHora(iso: string | null): string {
  if (iso === null) return "—";
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "short",
    timeStyle: "short",
    ...(sesion.zonaHoraria ? { timeZone: sesion.zonaHoraria } : {}),
  }).format(new Date(iso));
}
function conSigno(minor: number): string {
  return minor > 0 ? `+${dinero(minor)}` : dinero(minor);
}

// ---------- La caja de la sede ----------
// La sucursal de la barra (o la única); con «Todas», se elige aquí.
const sede = ref<string>(sucursales.fija.value ?? "");
watch(sucursales.fija, (fija) => {
  if (fija !== null) sede.value = fija;
});
const cargando = ref(false);
const errorCarga = ref(false);
const usaCaja = ref(false);
const turno = ref<Turno | null>(null);
const nombreSede = computed(
  () => sucursales.lista.value.find((s) => s.id === sede.value)?.nombre ?? "",
);

async function cargar(): Promise<void> {
  if (sede.value === "") {
    turno.value = null;
    return;
  }
  cargando.value = true;
  errorCarga.value = false;
  try {
    const { data } = await api.get<{
      data: { usa_caja: boolean; turno: Turno | null };
    }>(`${base.value}/caja`, { params: { sucursal_id: sede.value } });
    usaCaja.value = data.data.usa_caja;
    turno.value = data.data.turno;
  } catch {
    errorCarga.value = true;
  } finally {
    cargando.value = false;
  }
}
watch(sede, () => void cargar());

// Abrir con su fondo.
const fondo = ref("");
const notaApertura = ref("");
const abriendo = ref(false);
const errorAbrir = ref<string | null>(null);
async function abrir(): Promise<void> {
  const fondoMinor =
    fondo.value.trim() === "" ? 0 : aMinor(fondo.value, sesion.pais);
  if (fondoMinor === null) {
    errorAbrir.value = t("caja.montoInvalido");
    return;
  }
  abriendo.value = true;
  errorAbrir.value = null;
  try {
    const { data } = await api.post<{ data: Turno }>(
      `${base.value}/caja/turnos`,
      {
        sucursal_id: sede.value,
        fondo_minor: fondoMinor,
        nota: notaApertura.value.trim() || null,
      },
    );
    turno.value = data.data;
    usaCaja.value = true;
    fondo.value = "";
    notaApertura.value = "";
    toast.exito(t("caja.okAbierta"));
  } catch (e) {
    errorAbrir.value = mensajeDeError(e);
  } finally {
    abriendo.value = false;
  }
}

// Retiros e ingresos de efectivo.
const movimiento = ref<"retiro" | "ingreso" | null>(null);
const montoMovimiento = ref("");
const motivoMovimiento = ref("");
const guardandoMovimiento = ref(false);
const errorMovimiento = ref<string | null>(null);
// Una clave por movimiento: reintentarlo (sin red, tiempo agotado) no lo registra dos veces.
const claveMovimiento = ref("");
function nuevaClave(): string {
  return crypto.randomUUID().replace(/-/g, "");
}
// Con lo escrito sin registrar, cerrar o salir pregunta antes (patrón de formularios).
const conMovimiento = computed({
  get: () => movimiento.value !== null,
  set: (v: boolean) => {
    if (!v) movimiento.value = null;
  },
});
const descartarMovimiento = useDescartarCambios({
  abierto: conMovimiento,
  valor: () => [montoMovimiento.value, motivoMovimiento.value],
  guardando: guardandoMovimiento,
});
function abrirMovimiento(tipo: "retiro" | "ingreso"): void {
  montoMovimiento.value = "";
  motivoMovimiento.value = "";
  errorMovimiento.value = null;
  claveMovimiento.value = nuevaClave();
  descartarMovimiento.fijar();
  movimiento.value = tipo;
}
function cerrarMovimiento(): void {
  void descartarMovimiento.cerrar();
}
async function registrarMovimiento(): Promise<void> {
  const monto = aMinor(montoMovimiento.value, sesion.pais);
  if (monto === null || monto < 1) {
    errorMovimiento.value = t("caja.montoInvalido");
    return;
  }
  if (turno.value === null || movimiento.value === null) return;
  guardandoMovimiento.value = true;
  errorMovimiento.value = null;
  try {
    const { data } = await api.post<{ data: Turno }>(
      `${base.value}/caja/turnos/${turno.value.id}/movimientos`,
      {
        tipo: movimiento.value,
        monto_minor: monto,
        motivo: motivoMovimiento.value.trim(),
        clave: claveMovimiento.value,
      },
    );
    turno.value = data.data;
    movimiento.value = null;
    toast.exito(t("caja.okMovimiento"));
  } catch (e) {
    errorMovimiento.value = mensajeDeError(e);
    // Si el servidor respondió que no (p. ej. otro monto), el siguiente es otro intento.
    if (
      axios.isAxiosError(e) &&
      e.response !== undefined &&
      e.response.status < 500
    ) {
      claveMovimiento.value = nuevaClave();
    }
  } finally {
    guardandoMovimiento.value = false;
  }
}

// El conteo a ciegas, una sola vez.
const contando = ref(false);
const contado = ref<Record<Metodo, string>>({
  efectivo: "",
  tarjeta: "",
  transferencia: "",
  ventanilla: "",
});
const guardandoConteo = ref(false);
const errorConteo = ref<string | null>(null);
// Lo contado sin registrar no se pierde sin preguntar.
const descartarConteo = useDescartarCambios({
  abierto: contando,
  valor: () => contado.value,
  guardando: guardandoConteo,
});
function abrirConteo(): void {
  contado.value = {
    efectivo: "",
    tarjeta: "",
    transferencia: "",
    ventanilla: "",
  };
  errorConteo.value = null;
  descartarConteo.fijar();
  contando.value = true;
}
function cerrarConteo(): void {
  void descartarConteo.cerrar();
}
async function registrarConteo(): Promise<void> {
  const montos: Record<string, number> = {};
  for (const m of METODOS) {
    const texto = contado.value[m].trim();
    // Fuera del efectivo el reporte puede salir negativo (una devolución por la terminal
    // en un día sin ventas con tarjeta).
    const negativo = m !== "efectivo" && texto.startsWith("-");
    const minor =
      texto === "" ? 0 : aMinor(negativo ? texto.slice(1) : texto, sesion.pais);
    if (minor === null) {
      errorConteo.value = `${t(`caja.metodos.${m}`)}: ${t("caja.montoInvalido")}`;
      return;
    }
    montos[`${m}_minor`] = negativo ? -minor : minor;
  }
  if (turno.value === null) return;
  if (!(await confirmar(t("caja.confirmarConteo")))) return;
  guardandoConteo.value = true;
  errorConteo.value = null;
  try {
    const { data } = await api.post<{ data: Turno & { cuadra: boolean } }>(
      `${base.value}/caja/turnos/${turno.value.id}/conteo`,
      montos,
    );
    contando.value = false;
    if (data.data.cuadra) {
      ultimoCierre.value = data.data;
      turno.value = null;
      toast.exito(t("caja.okCuadra"));
    } else {
      turno.value = data.data;
    }
  } catch (e) {
    errorConteo.value = mensajeDeError(e);
  } finally {
    guardandoConteo.value = false;
  }
}

// Si no cuadró: el motivo y se cierra.
const motivoDiferencia = ref("");
const cerrando = ref(false);
const errorCierre = ref<string | null>(null);
const ultimoCierre = ref<Turno | null>(null);
async function cerrarConMotivo(): Promise<void> {
  if (turno.value === null) return;
  cerrando.value = true;
  errorCierre.value = null;
  try {
    const { data } = await api.post<{ data: Turno }>(
      `${base.value}/caja/turnos/${turno.value.id}/cierre`,
      { motivo: motivoDiferencia.value.trim() },
    );
    ultimoCierre.value = data.data;
    turno.value = null;
    motivoDiferencia.value = "";
    toast.exito(t("caja.okCerrada"));
  } catch (e) {
    errorCierre.value = mensajeDeError(e);
  } finally {
    cerrando.value = false;
  }
}

// ---------- Cierres (quien supervisa) ----------
const filtroSede = ref<string>(sucursales.fija.value ?? "");
const desde = ref("");
const hasta = ref("");
const porRevisar = ref(false);
// Los filtros de los cierres van en la dirección (un enlace o recargar los conserva).
// Antes de vigilarlos, para no cargar dos veces.
useFiltrosEnUrl({
  sucursal: filtroSede,
  desde: fechaEnUrl(desde),
  hasta: fechaEnUrl(hasta),
  revisar: computed({
    get: () => (porRevisar.value ? "si" : ""),
    set: (v: string) => {
      porRevisar.value = v === "si";
    },
  }),
});
const cierres = ref<Turno[]>([]);
const meta = ref({ page: 1, ultima_pagina: 1, total: 0, per_page: 20 });
const cargandoCierres = ref(false);
const errorCierres = ref(false);
async function cargarCierres(pagina = 1): Promise<void> {
  cargandoCierres.value = true;
  errorCierres.value = false;
  try {
    const { data } = await api.get<{ data: Turno[]; meta: typeof meta.value }>(
      `${base.value}/caja/turnos`,
      {
        params: {
          sucursal_id: filtroSede.value || undefined,
          desde: desde.value || undefined,
          hasta: hasta.value || undefined,
          revision: porRevisar.value ? "pendiente" : undefined,
          page: pagina,
        },
      },
    );
    cierres.value = data.data;
    meta.value = data.meta;
  } catch {
    errorCierres.value = true;
  } finally {
    cargandoCierres.value = false;
  }
}
watch([filtroSede, desde, hasta, porRevisar], () => void cargarCierres(1));
// Al abrir con `?pestana=cierres` también se cargan (no solo al tocar la pestaña).
watch(
  pestana,
  (p) => {
    if (p === "cierres") void cargarCierres(meta.value.page);
  },
  { immediate: true },
);

// Punto y texto: en ámbar lo que falta (abierta, sin motivo o por revisar).
function estadoDe(c: Turno): { texto: string; tono: string | undefined } {
  if (c.estado !== "cerrada")
    return { texto: t(`caja.estados.${c.estado}`), tono: "var(--aviso)" };
  if (c.requiere_revision && c.revisada_en === null)
    return { texto: t("caja.estados.porRevisar"), tono: "var(--aviso)" };
  if (c.revisada_en !== null)
    return { texto: t("caja.estados.revisada"), tono: "var(--exito)" };
  return { texto: t("caja.estados.cerrada"), tono: undefined };
}

// Detalle y revisión.
const detalle = ref<Turno | null>(null);
const notaRevision = ref("");
const revisando = ref(false);
const errorRevision = ref<string | null>(null);
// Con la nota de revisión escrita y sin guardar, cerrar pregunta antes.
const conDetalle = computed({
  get: () => detalle.value !== null,
  set: (v: boolean) => {
    if (!v) detalle.value = null;
  },
});
const descartarRevision = useDescartarCambios({
  abierto: conDetalle,
  valor: () => notaRevision.value,
  guardando: revisando,
});
// ¿El detalle trae la revisión por hacer? Entonces su pie es el de un formulario.
const porRevisarDetalle = computed(
  () =>
    detalle.value !== null &&
    detalle.value.requiere_revision &&
    !detalle.value.revisada_en,
);
async function verCierre(id: string): Promise<void> {
  notaRevision.value = "";
  errorRevision.value = null;
  descartarRevision.fijar();
  try {
    const { data } = await api.get<{ data: Turno }>(
      `${base.value}/caja/turnos/${id}`,
    );
    detalle.value = data.data;
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}
function cerrarDetalle(): void {
  void descartarRevision.cerrar();
}
async function revisar(): Promise<void> {
  if (detalle.value === null) return;
  revisando.value = true;
  errorRevision.value = null;
  try {
    const { data } = await api.post<{ data: Turno }>(
      `${base.value}/caja/turnos/${detalle.value.id}/revision`,
      { nota: notaRevision.value.trim() },
    );
    detalle.value = data.data;
    // La nota ya quedó guardada: cerrar ya no pregunta.
    descartarRevision.fijar();
    toast.exito(t("caja.okRevisada"));
    void cargarCierres(meta.value.page);
  } catch (e) {
    errorRevision.value = mensajeDeError(e);
  } finally {
    revisando.value = false;
  }
}

onMounted(() => void cargar());
</script>

<template>
  <div class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('caja.titulo')" />
    <!-- Segundo nivel (solo quien supervisa ve «Cierres»; con una sola, no se muestra). -->
    <PestanasSeccion
      v-model="pestana"
      :opciones="opcionesPestana"
      :etiqueta="$t('caja.pestanas')"
    />

    <!-- ===================== LA CAJA DE LA SEDE ===================== -->
    <section v-if="pestana === 'caja'" class="mt-6 grid gap-6">
      <label
        v-if="sucursales.varias.value && sucursales.fija.value === null"
        class="block max-w-sm"
      >
        <span class="tu-label">{{ $t("caja.sucursal") }}</span>
        <select v-model="sede" class="tu-input" data-prueba="sede">
          <option value="" disabled>{{ $t("caja.eligeSucursal") }}</option>
          <option v-for="s in sucursales.lista.value" :key="s.id" :value="s.id">
            {{ s.nombre }}
          </option>
        </select>
      </label>

      <p v-if="sede === ''" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("caja.eligeSucursal") }}
      </p>
      <p v-else-if="cargando && turno === null" role="status">
        {{ $t("caja.cargando") }}
      </p>
      <div v-else-if="errorCarga" role="alert">
        <p>{{ $t("caja.errorCarga") }}</p>
        <button
          type="button"
          class="tu-btn tu-btn-discreto mt-3"
          @click="cargar"
        >
          {{ $t("caja.reintentar") }}
        </button>
      </div>

      <!-- Cerrada: abrir con el fondo. -->
      <template v-else-if="turno === null">
        <AvisoTono
          v-if="ultimoCierre"
          :tono="ultimoCierre.diferencia_minor === 0 ? 'exito' : 'aviso'"
          data-prueba="ultimo-cierre"
        >
          {{
            ultimoCierre.diferencia_minor === 0
              ? $t("caja.okCuadra")
              : `${$t("caja.okCerrada")} ${$t("caja.diferenciaTotal", { monto: conSigno(ultimoCierre.diferencia_minor ?? 0) })}`
          }}
        </AvisoTono>
        <form
          class="tu-card p-5 grid gap-4 max-w-md"
          data-prueba="abrir-caja"
          @submit.prevent="abrir"
        >
          <p class="font-medium">
            {{ $t("caja.cerrada", { sede: nombreSede }) }}
          </p>
          <p
            v-if="!usaCaja"
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("caja.primeraVez") }}
          </p>
          <label class="block">
            <span class="tu-label">{{ $t("caja.fondo") }}</span>
            <input
              v-model="fondo"
              class="tu-input"
              inputmode="decimal"
              autocomplete="off"
              data-prueba="fondo"
              aria-describedby="caja-fondo-ayuda"
            />
            <span
              id="caja-fondo-ayuda"
              class="mt-1 block text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("caja.fondoAyuda") }}</span
            >
          </label>
          <label class="block">
            <span class="tu-label">{{ $t("caja.nota") }}</span>
            <input v-model="notaApertura" class="tu-input" maxlength="300" />
          </label>
          <AvisoTono v-if="errorAbrir" tono="peligro" role="alert">{{
            errorAbrir
          }}</AvisoTono>
          <div>
            <button
              type="submit"
              class="tu-btn tu-btn-primario"
              :disabled="abriendo"
              :aria-busy="abriendo || undefined"
              data-prueba="abrir"
            >
              {{ abriendo ? $t("caja.abriendo") : $t("caja.abrir") }}
            </button>
          </div>
        </form>
      </template>

      <!-- Contada y no cuadró: el motivo para cerrar. -->
      <template v-else-if="turno.estado === 'contada'">
        <div class="tu-card p-5 grid gap-4 max-w-2xl" data-prueba="no-cuadra">
          <div>
            <p class="font-medium">{{ $t("caja.noCuadra") }}</p>
            <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("caja.noCuadraAyuda") }}
            </p>
          </div>
          <table class="w-full text-sm tabular-nums">
            <thead>
              <tr :style="{ color: 'var(--texto-suave)' }">
                <th class="text-left font-medium py-1">
                  {{ $t("caja.conteo.metodo") }}
                </th>
                <th class="text-right font-medium py-1">
                  {{ $t("caja.conteo.esperado") }}
                </th>
                <th class="text-right font-medium py-1">
                  {{ $t("caja.conteo.contado") }}
                </th>
                <th class="text-right font-medium py-1">
                  {{ $t("caja.conteo.diferencia") }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in turno.conteos" :key="c.metodo">
                <td class="py-1">{{ $t(`caja.metodos.${c.metodo}`) }}</td>
                <td class="py-1 text-right">{{ dinero(c.esperado_minor) }}</td>
                <td class="py-1 text-right">{{ dinero(c.contado_minor) }}</td>
                <td
                  class="py-1 text-right"
                  :style="{
                    color:
                      c.diferencia_minor !== 0 ? 'var(--error)' : undefined,
                  }"
                >
                  {{ conSigno(c.diferencia_minor) }}
                </td>
              </tr>
            </tbody>
          </table>
          <label class="block">
            <span class="tu-label">{{ $t("caja.motivoDiferencia") }}</span>
            <textarea
              v-model="motivoDiferencia"
              class="tu-input"
              rows="3"
              maxlength="500"
              :placeholder="$t('caja.motivoDiferenciaPh')"
              data-prueba="motivo-diferencia"
            ></textarea>
          </label>
          <AvisoTono v-if="errorCierre" tono="peligro" role="alert">{{
            errorCierre
          }}</AvisoTono>
          <div>
            <button
              type="button"
              class="tu-btn tu-btn-primario"
              :disabled="cerrando || motivoDiferencia.trim().length < 3"
              :aria-busy="cerrando || undefined"
              data-prueba="cerrar-con-motivo"
              @click="cerrarConMotivo"
            >
              {{ $t("caja.cerrarCaja") }}
            </button>
          </div>
        </div>
      </template>

      <!-- Abierta. -->
      <template v-else>
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="font-medium">
              {{
                $t("caja.abierta", {
                  quien: turno.abierta_por ?? "—",
                  cuando: fechaHora(turno.abierta_en),
                })
              }}
            </p>
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("caja.fondoDe", { monto: dinero(turno.fondo_minor) }) }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="tu-btn tu-btn-secundario"
              data-prueba="retiro"
              @click="abrirMovimiento('retiro')"
            >
              {{ $t("caja.retiro") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-secundario"
              data-prueba="ingreso"
              @click="abrirMovimiento('ingreso')"
            >
              {{ $t("caja.ingreso") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-primario"
              data-prueba="contar"
              @click="abrirConteo"
            >
              {{ $t("caja.contar") }}
            </button>
          </div>
        </div>

        <div v-if="turno.esperado" class="tu-card p-5" data-prueba="esperado">
          <p class="tu-label">{{ $t("caja.esperado") }}</p>
          <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-4">
            <div v-for="m in METODOS" :key="m">
              <dt class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                {{ $t(`caja.metodos.${m}`) }}
              </dt>
              <dd class="text-xl font-semibold tabular-nums">
                {{ dinero(turno.esperado[m]) }}
              </dd>
            </div>
          </dl>
          <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("caja.esperadoAyuda") }}
          </p>
        </div>
        <p
          v-else
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="a-ciegas"
        >
          {{ $t("caja.aCiegas") }}
        </p>

        <div>
          <h2 class="tu-titulo-seccion">{{ $t("caja.movimientos") }}</h2>
          <p
            v-if="turno.movimientos.length === 0"
            class="mt-2 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("caja.sinMovimientos") }}
          </p>
          <ul
            v-else
            class="mt-2 divide-y divide-[var(--borde)]"
            data-prueba="movimientos"
          >
            <li
              v-for="m in turno.movimientos"
              :key="m.id"
              class="flex items-baseline justify-between gap-4 py-2 text-sm"
            >
              <span class="min-w-0">
                {{ $t(`caja.tipos.${m.tipo}`) }} ·
                {{ $t(`caja.metodos.${m.metodo}`) }}
                <span v-if="m.motivo" :style="{ color: 'var(--texto-suave)' }">
                  · {{ m.motivo }}</span
                >
                <span
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ fechaHora(m.fecha)
                  }}<template v-if="m.quien"> · {{ m.quien }}</template></span
                >
              </span>
              <span class="tabular-nums font-medium">{{
                conSigno(m.monto_minor)
              }}</span>
            </li>
          </ul>
        </div>
      </template>
    </section>

    <!-- ===================== CIERRES (supervisa) ===================== -->
    <section v-else class="tu-card mt-6">
      <!-- Los filtros, dentro de la tarjeta de lo que filtran. -->
      <div class="tu-filtros items-end">
        <label v-if="sucursales.varias.value" class="block">
          <span class="tu-label">{{ $t("caja.sucursal") }}</span>
          <select v-model="filtroSede" class="tu-input">
            <option value="">{{ $t("caja.filtros.todas") }}</option>
            <option
              v-for="s in sucursales.lista.value"
              :key="s.id"
              :value="s.id"
            >
              {{ s.nombre }}
            </option>
          </select>
        </label>
        <label class="block">
          <span class="tu-label">{{ $t("caja.filtros.desde") }}</span>
          <input v-model="desde" type="date" class="tu-input" />
        </label>
        <label class="block">
          <span class="tu-label">{{ $t("caja.filtros.hasta") }}</span>
          <input v-model="hasta" type="date" class="tu-input" />
        </label>
        <label class="flex items-center gap-2 pb-2 text-sm">
          <input
            v-model="porRevisar"
            type="checkbox"
            data-prueba="por-revisar"
          />
          {{ $t("caja.filtros.porRevisar") }}
        </label>
      </div>

      <p
        v-if="cargandoCierres && cierres.length === 0"
        class="p-5"
        role="status"
      >
        {{ $t("caja.cargando") }}
      </p>
      <div v-else-if="errorCierres" class="p-5" role="alert">
        <p>{{ $t("caja.errorCarga") }}</p>
        <button
          type="button"
          class="tu-btn tu-btn-discreto mt-3"
          @click="cargarCierres(meta.page)"
        >
          {{ $t("caja.reintentar") }}
        </button>
      </div>
      <p
        v-else-if="cierres.length === 0"
        class="p-5"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("caja.sinCierres") }}
      </p>
      <template v-else>
        <!-- En el teléfono, una ficha por cierre: la fecha (que lo abre) y la diferencia. -->
        <div class="relative overflow-x-auto tu-tabla-adaptable">
          <table class="tu-tabla" data-prueba="cierres">
            <thead>
              <tr>
                <th>
                  {{ $t("caja.columnas.abierta") }}
                </th>
                <th class="text-right">
                  {{ $t("caja.columnas.diferencia") }}
                </th>
                <th>
                  {{ $t("caja.columnas.sucursal") }}
                </th>
                <th>
                  {{ $t("caja.columnas.quien") }}
                </th>
                <th>
                  {{ $t("caja.columnas.estado") }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="c in cierres"
                :key="c.id"
                class="cursor-pointer"
                @click="verCierre(c.id)"
              >
                <td :data-etiqueta="$t('caja.columnas.abierta')">
                  <button
                    type="button"
                    class="cj-abrir"
                    @click.stop="verCierre(c.id)"
                  >
                    {{ fechaHora(c.abierta_en) }}
                  </button>
                </td>
                <td
                  :data-etiqueta="$t('caja.columnas.diferencia')"
                  class="text-right tabular-nums"
                  :style="{
                    color:
                      (c.diferencia_minor ?? 0) !== 0
                        ? 'var(--error)'
                        : undefined,
                  }"
                >
                  {{
                    c.diferencia_minor === null
                      ? "—"
                      : conSigno(c.diferencia_minor)
                  }}
                </td>
                <td :data-etiqueta="$t('caja.columnas.sucursal')">
                  {{ c.sucursal.nombre }}
                </td>
                <td :data-etiqueta="$t('caja.columnas.quien')">
                  {{ c.abierta_por ?? "—" }} · {{ c.cerrada_por ?? "—" }}
                </td>
                <td :data-etiqueta="$t('caja.columnas.estado')">
                  <span
                    class="tu-estado"
                    :style="{ '--tono': estadoDe(c).tono }"
                    >{{ estadoDe(c).texto }}</span
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <PaginacionListado
          :page="meta.page"
          :ultima-pagina="meta.ultima_pagina"
          :total="meta.total"
          :per-page="meta.per_page"
          @ir="cargarCierres"
        />
      </template>
    </section>

    <!-- Retiro o ingreso de efectivo. -->
    <ModalDialogo
      :abierto="movimiento !== null"
      :titulo="
        movimiento === 'ingreso' ? $t('caja.ingreso') : $t('caja.retiro')
      "
      tam="md"
      :ocupado="guardandoMovimiento"
      @cerrar="cerrarMovimiento"
    >
      <form
        id="caja-movimiento"
        class="grid gap-4"
        @submit.prevent="registrarMovimiento"
      >
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            movimiento === "ingreso"
              ? $t("caja.ingresoAyuda")
              : $t("caja.retiroAyuda")
          }}
        </p>
        <label class="block">
          <span class="tu-label">{{ $t("caja.monto") }}</span>
          <input
            v-model="montoMovimiento"
            class="tu-input"
            inputmode="decimal"
            autocomplete="off"
            required
            data-prueba="monto-movimiento"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{ $t("caja.motivo") }}</span>
          <input
            v-model="motivoMovimiento"
            class="tu-input"
            maxlength="300"
            required
            minlength="3"
            :placeholder="
              movimiento === 'ingreso'
                ? $t('caja.motivoIngresoPh')
                : $t('caja.motivoRetiroPh')
            "
            data-prueba="motivo-movimiento"
          />
        </label>
        <AvisoTono v-if="errorMovimiento" tono="peligro" role="alert">{{
          errorMovimiento
        }}</AvisoTono>
      </form>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="guardandoMovimiento"
          @click="cerrarMovimiento"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          type="submit"
          form="caja-movimiento"
          class="tu-btn tu-btn-primario"
          :disabled="guardandoMovimiento"
          :aria-busy="guardandoMovimiento || undefined"
          data-prueba="registrar-movimiento"
        >
          {{ $t("caja.registrar") }}
        </button>
      </template>
    </ModalDialogo>

    <!-- Conteo a ciegas. -->
    <ModalDialogo
      :abierto="contando"
      :titulo="$t('caja.contarTitulo')"
      tam="md"
      :ocupado="guardandoConteo"
      @cerrar="cerrarConteo"
    >
      <form
        id="caja-conteo"
        class="grid gap-4"
        @submit.prevent="registrarConteo"
      >
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("caja.contarAyuda") }}
        </p>
        <label v-for="m in METODOS" :key="m" class="block">
          <span class="tu-label">{{ $t(`caja.metodos.${m}`) }}</span>
          <input
            v-model="contado[m]"
            class="tu-input"
            inputmode="decimal"
            autocomplete="off"
            placeholder="0"
            :data-prueba="`contado-${m}`"
          />
        </label>
        <AvisoTono v-if="errorConteo" tono="peligro" role="alert">{{
          errorConteo
        }}</AvisoTono>
      </form>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="guardandoConteo"
          @click="cerrarConteo"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          type="submit"
          form="caja-conteo"
          class="tu-btn tu-btn-primario"
          :disabled="guardandoConteo"
          :aria-busy="guardandoConteo || undefined"
          data-prueba="registrar-conteo"
        >
          {{
            guardandoConteo ? $t("caja.contando") : $t("caja.registrarConteo")
          }}
        </button>
      </template>
    </ModalDialogo>

    <!-- Detalle de un cierre (quien supervisa). -->
    <ModalDialogo
      :abierto="detalle !== null"
      :titulo="
        detalle
          ? $t('caja.detalle', { sede: detalle.sucursal.nombre ?? '' })
          : ''
      "
      tam="lg"
      :ocupado="revisando"
      @cerrar="cerrarDetalle"
    >
      <div v-if="detalle" class="grid gap-5" data-prueba="detalle-cierre">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            $t("caja.abierta", {
              quien: detalle.abierta_por ?? "—",
              cuando: fechaHora(detalle.abierta_en),
            })
          }}
          · {{ $t("caja.fondoDe", { monto: dinero(detalle.fondo_minor) }) }}
        </p>
        <table
          v-if="detalle.conteos.length > 0"
          class="w-full text-sm tabular-nums"
        >
          <thead>
            <tr :style="{ color: 'var(--texto-suave)' }">
              <th class="text-left font-medium py-1">
                {{ $t("caja.conteo.metodo") }}
              </th>
              <th class="text-right font-medium py-1">
                {{ $t("caja.conteo.esperado") }}
              </th>
              <th class="text-right font-medium py-1">
                {{ $t("caja.conteo.contado") }}
              </th>
              <th class="text-right font-medium py-1">
                {{ $t("caja.conteo.diferencia") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in detalle.conteos" :key="c.metodo">
              <td class="py-1">{{ $t(`caja.metodos.${c.metodo}`) }}</td>
              <td class="py-1 text-right">{{ dinero(c.esperado_minor) }}</td>
              <td class="py-1 text-right">{{ dinero(c.contado_minor) }}</td>
              <td
                class="py-1 text-right"
                :style="{
                  color: c.diferencia_minor !== 0 ? 'var(--error)' : undefined,
                }"
              >
                {{ conSigno(c.diferencia_minor) }}
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="detalle.motivo_diferencia">
          <p class="tu-label">{{ $t("caja.motivoDelCierre") }}</p>
          <p class="text-sm">{{ detalle.motivo_diferencia }}</p>
        </div>
        <div>
          <h3 class="text-sm font-semibold">{{ $t("caja.movimientos") }}</h3>
          <p
            v-if="detalle.movimientos.length === 0"
            class="mt-1 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("caja.sinMovimientos") }}
          </p>
          <ul v-else class="mt-1 divide-y divide-[var(--borde)]">
            <li
              v-for="m in detalle.movimientos"
              :key="m.id"
              class="flex items-baseline justify-between gap-4 py-1.5 text-sm"
            >
              <span class="min-w-0">
                {{ $t(`caja.tipos.${m.tipo}`) }} ·
                {{ $t(`caja.metodos.${m.metodo}`) }}
                <span v-if="m.motivo" :style="{ color: 'var(--texto-suave)' }">
                  · {{ m.motivo }}</span
                >
                <span
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ fechaHora(m.fecha)
                  }}<template v-if="m.quien"> · {{ m.quien }}</template></span
                >
              </span>
              <span class="tabular-nums">{{ conSigno(m.monto_minor) }}</span>
            </li>
          </ul>
        </div>
        <div v-if="detalle.revisada_en">
          <p class="tu-label">{{ $t("caja.revision") }}</p>
          <p class="text-sm">
            {{
              $t("caja.revisadaPor", {
                quien: detalle.revisada_por ?? "—",
                cuando: fechaHora(detalle.revisada_en),
              })
            }}
          </p>
          <p class="text-sm">{{ detalle.nota_revision }}</p>
        </div>
        <form
          v-else-if="detalle.requiere_revision"
          id="caja-revision"
          class="grid gap-2"
          @submit.prevent="revisar"
        >
          <label class="block">
            <span class="tu-label">{{ $t("caja.notaRevision") }}</span>
            <textarea
              v-model="notaRevision"
              class="tu-input"
              rows="2"
              maxlength="500"
              required
              minlength="3"
              :placeholder="$t('caja.notaRevisionPh')"
              data-prueba="nota-revision"
            ></textarea>
          </label>
          <AvisoTono v-if="errorRevision" tono="peligro" role="alert">{{
            errorRevision
          }}</AvisoTono>
        </form>
      </div>
      <template #pie>
        <!-- Solo para leer: «Cerrar»; con la revisión por hacer, es un formulario. -->
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="revisando"
          @click="cerrarDetalle"
        >
          {{ porRevisarDetalle ? $t("comun.cancelar") : $t("caja.cerrar") }}
        </button>
        <button
          v-if="porRevisarDetalle"
          type="submit"
          form="caja-revision"
          class="tu-btn tu-btn-primario"
          :disabled="revisando"
          :aria-busy="revisando || undefined"
          data-prueba="revisar"
        >
          {{ $t("caja.revisar") }}
        </button>
      </template>
    </ModalDialogo>
  </div>
</template>

<style scoped>
/* La fecha abre el cierre: un botón con aspecto de título de la fila (no un enlace). */
.cj-abrir {
  font-weight: 500;
  text-align: left;
  cursor: pointer;
}
.cj-abrir:hover {
  text-decoration: underline;
}
.cj-abrir:focus-visible {
  outline: 2px solid var(--acento);
  outline-offset: 2px;
}
</style>
