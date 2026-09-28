<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
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
interface Miembro {
  id: string;
  nombre_completo: string;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
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
const miembros = ref<Miembro[]>([]);

const nueva = ref({ nombre: "", descripcion: "", costo_puntos: 100 });

const miembroSel = ref("");
const saldo = ref<number | null>(null);
const movimientos = ref<Movimiento[]>([]);
const recompensaSel = ref("");
const ajuste = ref({ puntos: 0, descripcion: "" });

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [prog, recs, canj, miem] = await Promise.all([
      api.get<{ data: Programa }>(`${base.value}/lealtad/programa`),
      api.get<{ data: Recompensa[] }>(`${base.value}/lealtad/recompensas`),
      api.get<{ data: Canje[] }>(`${base.value}/lealtad/canjes`),
      api.get<{ data: Miembro[] }>(`${base.value}/miembros?tipo=miembro`),
    ]);
    programa.value = prog.data.data;
    recompensas.value = recs.data.data;
    canjes.value = canj.data.data;
    miembros.value = miem.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function guardarPrograma(): Promise<void> {
  aviso.value = null;
  error.value = null;
  try {
    const { data } = await api.put<{ data: Programa }>(
      `${base.value}/lealtad/programa`,
      programa.value,
    );
    programa.value = data.data;
    aviso.value = t("lealtad.guardado");
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function crearRecompensa(): Promise<void> {
  aviso.value = null;
  error.value = null;
  try {
    await api.post(`${base.value}/lealtad/recompensas`, {
      nombre: nueva.value.nombre,
      descripcion: nueva.value.descripcion || null,
      costo_puntos: Number(nueva.value.costo_puntos) || 1,
    });
    nueva.value = { nombre: "", descripcion: "", costo_puntos: 100 };
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
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
    }>(`${base.value}/miembros/${miembroSel.value}/puntos`);
    saldo.value = data.data.saldo;
    movimientos.value = data.data.movimientos;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function canjear(): Promise<void> {
  aviso.value = null;
  error.value = null;
  if (miembroSel.value === "" || recompensaSel.value === "") {
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
  error.value = null;
  try {
    await api.post(`${base.value}/lealtad/canjes/${c.id}/${accion}`, {});
    await Promise.all([cargar(), verMiembro()]);
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('lealtad.titulo')" />

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
      <!-- Programa -->
      <div class="mt-6 tu-card p-5">
        <div class="flex items-center justify-between gap-3">
          <h2 class="font-light">{{ $t("lealtad.programa.titulo") }}</h2>
          <label class="flex items-center gap-2 text-sm font-medium">
            <input
              v-model="programa.activa"
              type="checkbox"
              :disabled="!puedeGestionar"
            />
            {{ $t("lealtad.programa.activa") }}
          </label>
        </div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <label class="tu-label" for="ppa">{{
              $t("lealtad.programa.porAsistencia")
            }}</label>
            <input
              id="ppa"
              v-model.number="programa.puntos_por_asistencia"
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
              v-model.number="programa.puntos_por_moneda"
              type="number"
              min="0"
              class="tu-input"
              :disabled="!puedeGestionar"
            />
            <span class="tu-hint">{{
              $t("lealtad.programa.porMonedaAyuda")
            }}</span>
          </div>
        </div>
        <button
          v-if="puedeGestionar"
          type="button"
          class="tu-btn tu-btn-primario mt-4"
          @click="guardarPrograma"
        >
          {{ $t("comun.guardar") }}
        </button>
      </div>

      <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <!-- Recompensas -->
        <div class="tu-card p-5">
          <h2 class="font-light">{{ $t("lealtad.recompensas.titulo") }}</h2>
          <p
            v-if="recompensas.length === 0"
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("lealtad.recompensas.vacio") }}
          </p>
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

          <form
            v-if="puedeGestionar"
            class="mt-4 space-y-2"
            @submit.prevent="crearRecompensa"
          >
            <input
              v-model="nueva.nombre"
              class="tu-input"
              :placeholder="$t('lealtad.recompensas.nombrePh')"
              required
            />
            <div class="flex gap-2">
              <input
                v-model.number="nueva.costo_puntos"
                type="number"
                min="1"
                class="tu-input w-32"
                :placeholder="$t('lealtad.puntos')"
              />
              <button
                type="submit"
                class="tu-btn tu-btn-fantasma whitespace-nowrap"
              >
                {{ $t("lealtad.recompensas.crear") }}
              </button>
            </div>
          </form>
        </div>

        <!-- Consulta por miembro -->
        <div class="tu-card p-5">
          <h2 class="font-light">{{ $t("lealtad.miembro.titulo") }}</h2>
          <select
            v-model="miembroSel"
            class="tu-input mt-3"
            @change="verMiembro"
          >
            <option value="">{{ $t("lealtad.miembro.elige") }}</option>
            <option v-for="m in miembros" :key="m.id" :value="m.id">
              {{ m.nombre_completo }}
            </option>
          </select>

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
          </template>
        </div>
      </div>

      <!-- Canjes recientes -->
      <h2 class="mt-8 font-light text-lg">{{ $t("lealtad.canjes.titulo") }}</h2>
      <p
        v-if="canjes.length === 0"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("lealtad.canjes.vacio") }}
      </p>
      <div v-else class="mt-3 tu-card overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
              <th class="px-4 py-2 font-medium">
                {{ $t("lealtad.canjes.colMiembro") }}
              </th>
              <th class="px-4 py-2 font-medium">
                {{ $t("lealtad.canjes.colRecompensa") }}
              </th>
              <th class="px-4 py-2 font-medium text-right">
                {{ $t("lealtad.puntos") }}
              </th>
              <th class="px-4 py-2 font-medium">
                {{ $t("lealtad.canjes.colEstado") }}
              </th>
              <th class="px-4 py-2 font-medium text-right">
                {{ $t("lealtad.canjes.colAccion") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="c in canjes"
              :key="c.id"
              class="border-t"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <td class="px-4 py-2 font-semibold">{{ c.persona }}</td>
              <td class="px-4 py-2">{{ c.recompensa }}</td>
              <td class="px-4 py-2 text-right">{{ c.puntos }}</td>
              <td class="px-4 py-2">
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
              <td class="px-4 py-2 text-right">
                <span
                  v-if="puedeGestionar && c.estado === 'pendiente'"
                  class="inline-flex gap-2 justify-end"
                >
                  <button
                    type="button"
                    class="tu-btn tu-btn-fantasma whitespace-nowrap"
                    @click="accionCanje(c, 'entregar')"
                  >
                    {{ $t("lealtad.canjes.entregar") }}
                  </button>
                  <button
                    type="button"
                    class="tu-btn tu-btn-fantasma whitespace-nowrap"
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
      </div>
    </template>
  </section>
</template>
