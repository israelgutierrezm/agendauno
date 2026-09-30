<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Avisos de AgendaUno al dueño (ADR 0072): de su prueba y su renta. Siempre por
 * correo; si la plataforma tiene WhatsApp con los dueños, también por WhatsApp. Aquí
 * verifica su número con un código (si no lo hizo al registrarse) y decide si los
 * quiere por WhatsApp.
 */
interface Avisos {
  correo: string | null;
  whatsapp: {
    numero: string | null;
    verificado: boolean;
    acepta: boolean;
  } | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const url = computed(() => `/api/v1/app/${sesion.slug}/avisos-plataforma`);

const avisos = ref<Avisos | null>(null);
const ocupado = ref(false);
const codigoEnviado = ref(false);
const codigo = ref("");
const error = ref<string | null>(null);
const esperaReenvio = ref(0);
let cuentaRegresiva: ReturnType<typeof setInterval> | undefined;

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Avisos }>(url.value);
    avisos.value = data.data;
  } catch {
    avisos.value = null;
  }
}

async function cambiar(acepta: boolean): Promise<void> {
  ocupado.value = true;
  try {
    const { data } = await api.put<{ data: Avisos }>(url.value, {
      acepta_whatsapp: acepta,
    });
    avisos.value = data.data;
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

async function enviarCodigo(): Promise<void> {
  ocupado.value = true;
  error.value = null;
  try {
    await api.post(`${url.value}/whatsapp/codigo`, {});
    codigoEnviado.value = true;
    codigo.value = "";
    esperaReenvio.value = 60;
    clearInterval(cuentaRegresiva);
    cuentaRegresiva = setInterval(() => {
      esperaReenvio.value = Math.max(0, esperaReenvio.value - 1);
      if (esperaReenvio.value === 0) {
        clearInterval(cuentaRegresiva);
      }
    }, 1000);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

async function verificar(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.post<{ data: Avisos }>(
      `${url.value}/whatsapp/verificar`,
      { codigo: codigo.value },
    );
    avisos.value = data.data;
    codigoEnviado.value = false;
    clearInterval(cuentaRegresiva);
    toast.exito(t("avisosAgendaUno.verificado"));
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

// Con los 6 dígitos se verifica solo.
watch(codigo, (valor) => {
  if (/^\d{6}$/.test(valor)) {
    void verificar();
  }
});

onMounted(cargar);
onBeforeUnmount(() => clearInterval(cuentaRegresiva));
</script>

<template>
  <div v-if="avisos" class="tu-card p-5" data-prueba="avisos-agendauno">
    <h2 class="font-light text-lg">{{ $t("avisosAgendaUno.titulo") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("avisosAgendaUno.ayuda", { correo: avisos.correo ?? "—" }) }}
    </p>

    <template v-if="avisos.whatsapp">
      <div class="aa-fila mt-4">
        <div class="min-w-0">
          <p class="text-sm font-medium">
            {{ $t("avisosAgendaUno.whatsapp") }}
            <span class="font-normal">{{ avisos.whatsapp.numero ?? "—" }}</span>
          </p>
          <p class="aa-estado mt-0.5 text-xs" data-prueba="estado-whatsapp">
            <span
              class="aa-punto"
              :class="avisos.whatsapp.verificado ? 'aa-exito' : 'aa-gris'"
              aria-hidden="true"
            ></span>
            {{
              avisos.whatsapp.verificado
                ? $t("avisosAgendaUno.verificadoEstado")
                : $t("avisosAgendaUno.sinVerificar")
            }}
          </p>
        </div>
        <label
          v-if="avisos.whatsapp.verificado"
          class="flex items-center gap-2 text-sm shrink-0"
        >
          <input
            type="checkbox"
            data-prueba="acepta-whatsapp"
            :checked="avisos.whatsapp.acepta"
            :disabled="ocupado"
            @change="cambiar(($event.target as HTMLInputElement).checked)"
          />
          {{ $t("avisosAgendaUno.recibir") }}
        </label>
        <button
          v-else-if="!codigoEnviado"
          type="button"
          class="tu-btn tu-btn-fantasma text-sm shrink-0"
          data-prueba="verificar-whatsapp"
          :disabled="ocupado"
          @click="enviarCodigo"
        >
          {{ $t("avisosAgendaUno.verificar") }}
        </button>
      </div>

      <div v-if="codigoEnviado" class="mt-3">
        <label class="tu-label" for="aa-codigo">{{
          $t("avisosAgendaUno.codigo")
        }}</label>
        <input
          id="aa-codigo"
          v-model="codigo"
          class="tu-input aa-codigo"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
        />
        <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
          <span v-if="esperaReenvio > 0">{{
            $t("avisosAgendaUno.reenviarEn", { s: esperaReenvio })
          }}</span>
          <button
            v-else
            type="button"
            class="tu-enlace"
            :disabled="ocupado"
            @click="enviarCodigo"
          >
            {{ $t("avisosAgendaUno.reenviar") }}
          </button>
        </p>
      </div>
      <p
        v-if="error"
        class="mt-2 text-xs"
        role="alert"
        style="color: var(--error)"
      >
        {{ error }}
      </p>
    </template>
  </div>
</template>

<style scoped>
.aa-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding-top: 0.9rem;
  border-top: 1px solid var(--borde);
}
.aa-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--texto-suave);
}
.aa-punto {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
}
.aa-exito {
  background: var(--exito);
}
.aa-gris {
  background: var(--texto-suave);
}
.aa-codigo {
  max-width: 9rem;
  letter-spacing: 0.3em;
  font-variant-numeric: tabular-nums;
}
</style>
