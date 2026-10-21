<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import axios from "axios";

import AvisoTono from "@/components/AvisoTono.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Check-ins de Wellhub y TotalPass en una clase (M10). La visita se valida con el
 * proveedor: aprobada, rechazada (no se registra) o «sin verificar» cuando el
 * proveedor no responde. Una visita sin verificar no cuenta como aprobada: se
 * reintenta con el mismo código o quien tiene permiso la registra como excepción, con
 * su motivo y lo que vio (queda quién la autorizó).
 */
export interface Checkin {
  id: string;
  proveedor: string;
  usuario: string | null;
  // Si llegó sola por el aviso de la plataforma (ADR 0148) o la registró recepción.
  origen?: "automatico" | "manual";
  estado: "validado" | "no_verificable" | "excepcion";
  motivo: string | null;
  registrado_en: string;
  registrado_por: string | null;
  autorizado_por: string | null;
  motivo_excepcion: string | null;
  evidencia: string | null;
}

const props = defineProps<{ sesionId: string }>();

const sesion = useSesionTenantStore();
const { t } = useI18n();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeExcepcion = computed(() => sesion.puede("checkins.excepcion"));

const checkins = ref<Checkin[]>([]);
const modelo = ref({ proveedor: "wellhub", codigo: "" });
const registrando = ref(false);
const ok = ref(false);
const aviso = ref<string | null>(null);
const error = ref<string | null>(null);

const abierta = ref<string | null>(null);
const excepcion = ref({ motivo: "", evidencia: "" });
const autorizando = ref(false);

const COLOR: Record<Checkin["estado"], string> = {
  validado: "var(--exito)",
  no_verificable: "var(--aviso)",
  excepcion: "var(--primario)",
};

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Checkin[] }>(
      `${base.value}/sesiones/${props.sesionId}/checkins`,
    );
    checkins.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function registrar(): Promise<void> {
  registrando.value = true;
  ok.value = false;
  aviso.value = null;
  error.value = null;
  try {
    await api.post(`${base.value}/checkins`, {
      proveedor: modelo.value.proveedor,
      sesion_id: props.sesionId,
      codigo: modelo.value.codigo,
    });
    modelo.value.codigo = "";
    ok.value = true;
  } catch (e) {
    // El proveedor no respondió: no se registra como aprobada, queda sin verificar.
    const codigo = axios.isAxiosError(e)
      ? (e.response?.data as { code?: string } | undefined)?.code
      : undefined;
    if (codigo === "CHECKIN_UNVERIFIABLE") {
      aviso.value = mensajeDeError(e);
    } else {
      error.value = mensajeDeError(e);
    }
  } finally {
    registrando.value = false;
  }
  await cargar();
}

function abrirExcepcion(id: string): void {
  abierta.value = abierta.value === id ? null : id;
  excepcion.value = { motivo: "", evidencia: "" };
}

async function autorizar(c: Checkin): Promise<void> {
  autorizando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: Checkin }>(
      `${base.value}/checkins/${c.id}/excepcion`,
      excepcion.value,
    );
    checkins.value = checkins.value.map((x) => (x.id === c.id ? data.data : x));
    abierta.value = null;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    autorizando.value = false;
  }
}

function etiqueta(c: Checkin): string {
  if (c.estado === "excepcion") {
    return c.autorizado_por !== null
      ? t("checkins.estados.excepcionPor", { nombre: c.autorizado_por })
      : t("checkins.estados.excepcion");
  }
  return t(`checkins.estados.${c.estado}`, {
    proveedor: t(`integraciones.proveedores.${c.proveedor}`),
  });
}

watch(
  () => props.sesionId,
  () => {
    checkins.value = [];
    modelo.value = { proveedor: "wellhub", codigo: "" };
    ok.value = false;
    aviso.value = null;
    error.value = null;
    abierta.value = null;
    void cargar();
  },
  { immediate: true },
);
</script>

<template>
  <section class="tu-detalle-seccion">
    <h3>{{ $t("checkins.titulo") }}</h3>
    <p class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("checkins.alcance") }}
    </p>
    <form
      class="mt-2 flex flex-wrap items-end gap-2"
      @submit.prevent="registrar"
    >
      <div class="min-w-[130px]">
        <label class="tu-label" for="cp">{{ $t("checkins.proveedor") }}</label>
        <select id="cp" v-model="modelo.proveedor" class="tu-input">
          <option value="wellhub">
            {{ $t("integraciones.proveedores.wellhub") }}
          </option>
          <option value="totalpass">
            {{ $t("integraciones.proveedores.totalpass") }}
          </option>
          <!-- Sin API: el folio de la visita que valida recepción (ADR 0197). -->
          <option value="fitpass">
            {{ $t("integraciones.proveedores.fitpass") }}
          </option>
        </select>
      </div>
      <div class="flex-1 min-w-[150px]">
        <label class="tu-label" for="cc">{{ $t("checkins.codigo") }}</label>
        <input
          id="cc"
          v-model="modelo.codigo"
          class="tu-input"
          autocomplete="off"
          required
        />
      </div>
      <button
        class="tu-btn tu-btn-primario"
        type="submit"
        :disabled="registrando || modelo.codigo === ''"
      >
        {{
          registrando ? $t("checkins.registrando") : $t("checkins.registrar")
        }}
      </button>
    </form>
    <AvisoTono v-if="ok" tono="exito" class="mt-2">
      {{ $t("checkins.ok") }}
    </AvisoTono>
    <p
      v-if="aviso"
      class="mt-2 text-sm"
      role="status"
      data-prueba="checkin-sin-verificar"
    >
      {{ aviso }}
      {{
        puedeExcepcion
          ? $t("checkins.sinVerificarConPermiso")
          : $t("checkins.sinVerificarSinPermiso")
      }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-2" role="alert">
      {{ error }}
    </AvisoTono>

    <ul v-if="checkins.length > 0" class="mt-3 space-y-3">
      <li
        v-for="c in checkins"
        :key="c.id"
        class="text-sm"
        data-prueba="checkin"
      >
        <div class="flex items-center justify-between gap-2">
          <span class="min-w-0 truncate">
            {{ c.usuario ?? $t(`integraciones.proveedores.${c.proveedor}`) }}
            <span
              v-if="c.origen === 'automatico'"
              class="text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              data-prueba="checkin-automatico"
            >
              · {{ $t("operacion.plataformas.origen.automatico") }}</span
            >
          </span>
          <span
            class="inline-flex items-center gap-1.5 shrink-0"
            data-prueba="estado-checkin"
          >
            <span
              class="inline-block size-1.5 rounded-full"
              :style="{ background: COLOR[c.estado] }"
              aria-hidden="true"
            />
            {{ etiqueta(c) }}
          </span>
        </div>
        <p
          v-if="c.estado === 'no_verificable' && c.motivo"
          class="text-xs mt-0.5"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ c.motivo }}
        </p>
        <p
          v-if="c.estado === 'excepcion' && c.motivo_excepcion"
          class="text-xs mt-0.5"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ c.motivo_excepcion }}
          <template v-if="c.evidencia"> · {{ c.evidencia }}</template>
        </p>
        <button
          v-if="c.estado === 'no_verificable' && puedeExcepcion"
          type="button"
          class="tu-enlace text-xs mt-1"
          :aria-expanded="abierta === c.id"
          @click="abrirExcepcion(c.id)"
        >
          {{ $t("checkins.registrarExcepcion") }}
        </button>
        <form
          v-if="abierta === c.id"
          class="mt-2 grid gap-2"
          data-prueba="form-excepcion"
          @submit.prevent="autorizar(c)"
        >
          <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("checkins.excepcionAyuda") }}
          </p>
          <div>
            <label class="tu-label" :for="`exm-${c.id}`">{{
              $t("checkins.motivoExcepcion")
            }}</label>
            <input
              :id="`exm-${c.id}`"
              v-model="excepcion.motivo"
              class="tu-input"
              maxlength="500"
              required
            />
          </div>
          <div>
            <label class="tu-label" :for="`exe-${c.id}`">{{
              $t("checkins.evidencia")
            }}</label>
            <textarea
              :id="`exe-${c.id}`"
              v-model="excepcion.evidencia"
              class="tu-input"
              rows="2"
              maxlength="1000"
              :placeholder="$t('checkins.evidenciaEjemplo')"
              required
            />
          </div>
          <div>
            <button
              class="tu-btn tu-btn-primario"
              type="submit"
              :disabled="
                autorizando ||
                excepcion.motivo.trim() === '' ||
                excepcion.evidencia.trim() === ''
              "
            >
              {{ $t("checkins.autorizar") }}
            </button>
          </div>
        </form>
      </li>
    </ul>
    <p v-else class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("checkins.vacio") }}
    </p>
  </section>
</template>
