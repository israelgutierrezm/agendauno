<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * El dominio propio del sitio (ADR 0189): conectarlo, los registros que lleva su DNS
 * (un TXT que prueba que es suyo y el CNAME o los A que lo apuntan), revisarlos y
 * quitarlo. Es del sitio avanzado (Plus). Lo revisa también la plataforma cada pocos
 * minutos; activo, su página abre ahí con su certificado.
 */
interface Registro {
  tipo: string;
  nombre: string;
  valor: string;
}
interface DominioPropio {
  dominio: string;
  estado: "activo" | "pendiente" | "sin_plan";
  problema: "sin_txt" | "txt_distinto" | "no_apunta" | null;
  propiedad_verificada: boolean;
  revisado_en: string | null;
  registros: Registro[];
}
interface Respuesta {
  con_plan: boolean;
  destino: string;
  dominio: DominioPropio | null;
}

const props = withDefaults(defineProps<{ puedePublicar?: boolean }>(), {
  puedePublicar: true,
});

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}/sitio/dominio`);

const datos = ref<Respuesta | null>(null);
const escrito = ref("");
const trabajando = ref(false);
const error = ref<string | null>(null);
const copiado = ref<string | null>(null);

const TONOS: Record<DominioPropio["estado"], string> = {
  activo: "var(--exito)",
  pendiente: "var(--aviso)",
  sin_plan: "var(--texto-suave)",
};

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Respuesta }>(base.value);
    datos.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function enviar(hacer: () => Promise<{ data: { data: Respuesta } }>) {
  if (trabajando.value) {
    return;
  }
  trabajando.value = true;
  error.value = null;
  try {
    datos.value = (await hacer()).data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    trabajando.value = false;
  }
}

function conectar(): Promise<void> {
  if (escrito.value.trim() === "") {
    return Promise.resolve();
  }
  return enviar(() =>
    api.put<{ data: Respuesta }>(base.value, { dominio: escrito.value.trim() }),
  );
}

function revisar(): Promise<void> {
  return enviar(() => api.post<{ data: Respuesta }>(`${base.value}/revisar`));
}

async function quitar(): Promise<void> {
  const dominio = datos.value?.dominio?.dominio ?? "";
  if (
    !(await confirmar(t("sitioWeb.dominio.quitarConfirmar", { dominio }), {
      aceptar: t("sitioWeb.dominio.quitar"),
      peligro: true,
    }))
  ) {
    return;
  }
  escrito.value = "";
  await enviar(() => api.delete<{ data: Respuesta }>(base.value));
}

async function copiar(valor: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(valor);
    copiado.value = valor;
    window.setTimeout(() => (copiado.value = null), 1800);
  } catch {
    // Sin portapapeles: se copia a mano.
  }
}

onMounted(cargar);
</script>

<template>
  <div class="tu-card p-5" data-prueba="dominio-propio">
    <div class="flex flex-wrap items-center gap-2">
      <h2 class="text-lg font-medium">{{ $t("sitioWeb.dominio.propio") }}</h2>
      <span class="tu-badge">{{ $t("sitioWeb.plus.etiqueta") }}</span>
    </div>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("sitioWeb.dominio.propioAyuda") }}
    </p>

    <template v-if="datos">
      <!-- Sin dominio: con su plan lo conecta quien publica; sin él, es Plus. -->
      <template v-if="datos.dominio === null">
        <p
          v-if="!datos.con_plan"
          class="mt-3 text-sm"
          data-prueba="dominio-sin-plan"
        >
          {{ $t("sitioWeb.dominio.propioPlus") }}
        </p>
        <form
          v-else-if="props.puedePublicar"
          class="mt-4 flex flex-wrap items-end gap-2"
          @submit.prevent="conectar"
        >
          <label class="grid gap-1 grow min-w-[16rem]">
            <span class="tu-label">{{
              $t("sitioWeb.dominio.propioCampo")
            }}</span>
            <input
              v-model="escrito"
              class="tu-input"
              inputmode="url"
              autocomplete="off"
              spellcheck="false"
              placeholder="reservas.tunegocio.com"
              data-prueba="dominio-escrito"
            />
          </label>
          <button
            type="submit"
            class="tu-btn tu-btn-primario"
            :disabled="trabajando || escrito.trim() === ''"
            data-prueba="dominio-conectar"
          >
            {{ $t("sitioWeb.dominio.conectar") }}
          </button>
        </form>
      </template>

      <!-- Con dominio: su estado y los registros de su DNS. -->
      <div v-else class="mt-4 space-y-4">
        <div class="flex flex-wrap items-center gap-3">
          <strong class="break-all" data-prueba="dominio-actual">{{
            datos.dominio.dominio
          }}</strong>
          <span
            class="tu-estado text-sm"
            :style="{ '--tono': TONOS[datos.dominio.estado] }"
            data-prueba="dominio-estado"
          >
            {{ $t(`sitioWeb.dominio.estado.${datos.dominio.estado}`) }}
          </span>
        </div>
        <p
          v-if="datos.dominio.estado === 'activo'"
          class="text-sm"
          data-prueba="dominio-activo"
        >
          {{ $t("sitioWeb.dominio.activoAyuda") }}
          <a
            :href="`https://${datos.dominio.dominio}`"
            target="_blank"
            rel="noopener"
            class="tu-enlace break-all"
            >https://{{ datos.dominio.dominio }}</a
          >
        </p>
        <p v-else-if="datos.dominio.estado === 'sin_plan'" class="text-sm">
          {{ $t("sitioWeb.dominio.sinPlanAyuda") }}
        </p>
        <p
          v-if="datos.dominio.problema"
          class="text-sm"
          data-prueba="dominio-problema"
        >
          {{ $t(`sitioWeb.dominio.problema.${datos.dominio.problema}`) }}
        </p>

        <div v-if="datos.dominio.estado !== 'activo'">
          <p class="text-sm">{{ $t("sitioWeb.dominio.registrosAyuda") }}</p>
          <div class="mt-2 overflow-x-auto">
            <table class="tu-tabla text-sm" data-prueba="dominio-registros">
              <thead>
                <tr>
                  <th>{{ $t("sitioWeb.dominio.tipo") }}</th>
                  <th>{{ $t("sitioWeb.dominio.nombre") }}</th>
                  <th>{{ $t("sitioWeb.dominio.valor") }}</th>
                  <th>
                    <span class="sr-only">{{ $t("enlace.copiar") }}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="r in datos.dominio.registros"
                  :key="`${r.tipo}-${r.valor}`"
                >
                  <td>{{ r.tipo }}</td>
                  <td class="font-mono break-all">{{ r.nombre }}</td>
                  <td class="font-mono break-all">{{ r.valor }}</td>
                  <td>
                    <button
                      type="button"
                      class="tu-btn tu-btn-fantasma text-sm"
                      @click="copiar(r.valor)"
                    >
                      {{
                        copiado === r.valor
                          ? $t("enlace.copiado")
                          : $t("enlace.copiar")
                      }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="mt-2 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("sitioWeb.dominio.tardaAyuda") }}
          </p>
        </div>

        <div
          v-if="props.puedePublicar"
          class="flex flex-wrap justify-end gap-2"
        >
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="trabajando"
            data-prueba="dominio-quitar"
            @click="quitar"
          >
            {{ $t("sitioWeb.dominio.quitar") }}
          </button>
          <button
            v-if="datos.dominio.estado === 'pendiente'"
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="trabajando"
            data-prueba="dominio-revisar"
            @click="revisar"
          >
            {{ $t("sitioWeb.dominio.revisar") }}
          </button>
        </div>
      </div>
    </template>

    <p v-if="error" class="tu-error-campo mt-2" role="alert">{{ error }}</p>
  </div>
</template>
