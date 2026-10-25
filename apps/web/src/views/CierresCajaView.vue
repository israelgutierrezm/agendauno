<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import type { Turno } from "@/lib/caja";
import { useDescartarCambios } from "@/lib/editorFormulario";
import { fechaEnUrl, useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSucursales } from "@/lib/sucursalOperativa";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Cobros › Cierres de caja (ADR 0175): el historial de cada caja al cerrarse, con su
 * diferencia y lo que falta revisar. Quien supervisa abre un cierre, ve su conteo y sus
 * movimientos, y deja la nota de revisión. La operación del día (abrir, mover efectivo,
 * contar y cerrar) sigue en Ventas › Caja.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const sucursales = useSucursales();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

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

onMounted(() => void cargarCierres(1));
</script>

<template>
  <div class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('caja.cierresTitulo')"
      :subtitulo="$t('caja.cierresSubtitulo')"
    />

    <section class="tu-card mt-6">
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
        <!-- En el teléfono, cada método como ficha (sin desbordar). -->
        <div
          v-if="detalle.conteos.length > 0"
          class="relative tu-tabla-adaptable"
        >
          <table class="tu-tabla text-sm tabular-nums">
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
                <td class="py-1" :data-etiqueta="$t('caja.conteo.metodo')">
                  {{ $t(`caja.metodos.${c.metodo}`) }}
                </td>
                <td
                  class="py-1 text-right"
                  :data-etiqueta="$t('caja.conteo.esperado')"
                >
                  {{ dinero(c.esperado_minor) }}
                </td>
                <td
                  class="py-1 text-right"
                  :data-etiqueta="$t('caja.conteo.contado')"
                >
                  {{ dinero(c.contado_minor) }}
                </td>
                <td
                  class="py-1 text-right"
                  :style="{
                    color:
                      c.diferencia_minor !== 0 ? 'var(--error)' : undefined,
                  }"
                  :data-etiqueta="$t('caja.conteo.diferencia')"
                >
                  {{ conSigno(c.diferencia_minor) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
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
