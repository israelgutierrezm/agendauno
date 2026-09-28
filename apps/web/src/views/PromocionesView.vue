<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Promo {
  id: string;
  codigo: string;
  descripcion: string | null;
  tipo: string;
  valor: number;
  monto_minimo_minor: number | null;
  usos_maximos: number | null;
  usos: number;
  vence_en: string | null;
  activa: boolean;
  vigente: boolean;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const promos = ref<Promo[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const accionando = ref(false);

const mostrarForm = ref(false);
const editandoId = ref<string | null>(null);
const form = ref({
  codigo: "",
  descripcion: "",
  tipo: "porcentaje",
  valor: 10,
  minimo: "",
  usosMaximos: "",
  vence: "",
  activa: true,
});

function dinero(minor: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
  }).format(minor / 100);
}

function valorLegible(p: Promo): string {
  return p.tipo === "porcentaje" ? `${p.valor / 100}%` : dinero(p.valor);
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Promo[] }>(
      `${base.value}/promociones`,
    );
    promos.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function nueva(): void {
  editandoId.value = null;
  form.value = {
    codigo: "",
    descripcion: "",
    tipo: "porcentaje",
    valor: 10,
    minimo: "",
    usosMaximos: "",
    vence: "",
    activa: true,
  };
  mostrarForm.value = true;
}

function editar(p: Promo): void {
  editandoId.value = p.id;
  form.value = {
    codigo: p.codigo,
    descripcion: p.descripcion ?? "",
    tipo: p.tipo,
    valor: p.valor / 100,
    minimo:
      p.monto_minimo_minor !== null ? String(p.monto_minimo_minor / 100) : "",
    usosMaximos: p.usos_maximos !== null ? String(p.usos_maximos) : "",
    vence: p.vence_en ?? "",
    activa: p.activa,
  };
  mostrarForm.value = true;
}

async function guardar(): Promise<void> {
  if (form.value.codigo.trim() === "") {
    return;
  }
  accionando.value = true;
  error.value = null;
  // % (15) -> 1500 bps; monto ($100) -> 10000 minor: en ambos casos x100.
  const carga = {
    codigo: form.value.codigo,
    descripcion: form.value.descripcion !== "" ? form.value.descripcion : null,
    tipo: form.value.tipo,
    valor: Math.round(Number(form.value.valor) * 100),
    monto_minimo_minor:
      form.value.minimo !== ""
        ? Math.round(Number(form.value.minimo) * 100)
        : null,
    usos_maximos:
      form.value.usosMaximos !== "" ? Number(form.value.usosMaximos) : null,
    vence_en: form.value.vence !== "" ? form.value.vence : null,
    activa: form.value.activa,
  };
  try {
    if (editandoId.value !== null) {
      await api.put(`${base.value}/promociones/${editandoId.value}`, carga);
    } else {
      await api.post(`${base.value}/promociones`, carga);
    }
    mostrarForm.value = false;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function eliminar(p: Promo): Promise<void> {
  if (
    !(await confirmar(t("promociones.confirmarEliminar"), { peligro: true }))
  ) {
    return;
  }
  accionando.value = true;
  error.value = null;
  try {
    await api.delete(`${base.value}/promociones/${p.id}`);
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <div class="flex items-start justify-between gap-3">
      <EncabezadoSeccion :titulo="$t('promociones.titulo')" />
      <button
        class="tu-btn tu-btn-primario shrink-0"
        type="button"
        @click="nueva"
      >
        {{ $t("promociones.nueva") }}
      </button>
    </div>

    <!-- Alta / edición (drawer lateral) -->
    <PanelLateral
      :abierto="mostrarForm"
      :titulo="
        editandoId !== null ? $t('promociones.editar') : $t('promociones.nueva')
      "
      @cerrar="mostrarForm = false"
    >
      <form class="grid gap-4" @submit.prevent="guardar">
        <div>
          <label class="tu-label" for="p-codigo">{{
            $t("promociones.campos.codigo")
          }}</label>
          <input
            id="p-codigo"
            v-model="form.codigo"
            class="tu-input uppercase"
            required
          />
        </div>
        <div>
          <label class="tu-label" for="p-tipo">{{
            $t("promociones.campos.tipo")
          }}</label>
          <select id="p-tipo" v-model="form.tipo" class="tu-input">
            <option value="porcentaje">
              {{ $t("promociones.tipos.porcentaje") }}
            </option>
            <option value="monto_fijo">
              {{ $t("promociones.tipos.monto_fijo") }}
            </option>
          </select>
        </div>
        <div>
          <label class="tu-label" for="p-valor">
            {{
              form.tipo === "porcentaje"
                ? $t("promociones.campos.valorPorcentaje")
                : $t("promociones.campos.valorMonto")
            }}
          </label>
          <input
            id="p-valor"
            v-model.number="form.valor"
            type="number"
            min="1"
            step="0.01"
            class="tu-input"
            required
          />
        </div>
        <div>
          <label class="tu-label" for="p-min">{{
            $t("promociones.campos.minimo")
          }}</label>
          <input
            id="p-min"
            v-model="form.minimo"
            type="number"
            min="0"
            step="0.01"
            class="tu-input"
          />
        </div>
        <div>
          <label class="tu-label" for="p-usos">{{
            $t("promociones.campos.usosMaximos")
          }}</label>
          <input
            id="p-usos"
            v-model="form.usosMaximos"
            type="number"
            min="1"
            class="tu-input"
          />
        </div>
        <div>
          <label class="tu-label" for="p-vence">{{
            $t("promociones.campos.vence")
          }}</label>
          <input
            id="p-vence"
            v-model="form.vence"
            type="date"
            class="tu-input"
          />
        </div>
        <div>
          <label class="tu-label" for="p-desc">{{
            $t("promociones.campos.descripcion")
          }}</label>
          <input id="p-desc" v-model="form.descripcion" class="tu-input" />
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.activa" type="checkbox" />
          {{ $t("promociones.campos.activa") }}
        </label>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            @click="mostrarForm = false"
          >
            {{ $t("promociones.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="accionando || form.codigo.trim() === ''"
            @click="guardar"
          >
            {{ $t("promociones.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="promos.length === 0"
      class="mt-10 text-center text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("promociones.sinPromos") }}
    </p>

    <ul v-else class="mt-6 space-y-2">
      <li
        v-for="p in promos"
        :key="p.id"
        class="tu-card p-3 flex items-start justify-between gap-3"
      >
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="font-bold tracking-wide">{{ p.codigo }}</span>
            <span class="tu-badge tu-badge-exito">{{ valorLegible(p) }}</span>
            <span
              class="tu-badge"
              :class="p.vigente ? 'tu-badge-exito' : 'tu-badge-aviso'"
            >
              {{
                p.vigente
                  ? $t("promociones.vigente")
                  : $t("promociones.noVigente")
              }}
            </span>
          </div>
          <p
            v-if="p.descripcion"
            class="text-sm mt-1"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ p.descripcion }}
          </p>
          <div
            class="flex items-center gap-3 mt-1 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            <span
              >{{ $t("promociones.usos") }}: {{ p.usos
              }}<span v-if="p.usos_maximos !== null">/{{ p.usos_maximos }}</span
              ><span v-else> ({{ $t("promociones.sinLimite") }})</span></span
            >
            <span
              >{{ $t("promociones.vence") }}:
              {{ p.vence_en ?? $t("promociones.sinVence") }}</span
            >
          </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <button
            class="tu-enlace"
            type="button"
            :disabled="accionando"
            @click="editar(p)"
          >
            {{ $t("promociones.editar") }}
          </button>
          <button
            class="tu-enlace"
            style="color: var(--error)"
            type="button"
            :disabled="accionando"
            @click="eliminar(p)"
          >
            {{ $t("promociones.eliminar") }}
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>
