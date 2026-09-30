<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { PAISES } from "@/lib/ladas";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Avisos de AgendaUno al dueño (ADR 0072): de su prueba y su renta. Siempre por
 * correo; si la plataforma tiene WhatsApp con los dueños, también por WhatsApp. Aquí
 * verifica su número con un código (si no lo hizo al registrarse) y decide si los
 * quiere por WhatsApp. Quien gestiona el negocio cambia el número (ADR 0075): con
 * WhatsApp con los dueños, cambia al confirmar el código que llega al número nuevo.
 */
interface Avisos {
  correo: string | null;
  numero?: string | null;
  pais?: string;
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
const puedeCambiar = computed(() => sesion.puede("estudio.gestionar"));

const avisos = ref<Avisos | null>(null);
const ocupado = ref(false);
// Qué confirma el código: el número actual o el nuevo.
const modo = ref<"verificar" | "cambiar" | null>(null);
const codigo = ref("");
const error = ref<string | null>(null);
const esperaReenvio = ref(0);
let cuentaRegresiva: ReturnType<typeof setInterval> | undefined;

const cambiando = ref(false);
const nuevoPais = ref("52");
const nuevoNumero = ref("");
const ladas = computed(() =>
  PAISES.some((p) => p.lada === nuevoPais.value)
    ? PAISES
    : [...PAISES, { lada: nuevoPais.value, nombre: "", bandera: "" }],
);
const numeroNuevo = computed(() => ({
  contacto_whatsapp_pais: nuevoPais.value,
  contacto_telefono: nuevoNumero.value.trim(),
}));

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

function esperarReenvio(): void {
  esperaReenvio.value = 60;
  clearInterval(cuentaRegresiva);
  cuentaRegresiva = setInterval(() => {
    esperaReenvio.value = Math.max(0, esperaReenvio.value - 1);
    if (esperaReenvio.value === 0) {
      clearInterval(cuentaRegresiva);
    }
  }, 1000);
}

async function enviarCodigo(paraModo: "verificar" | "cambiar"): Promise<void> {
  ocupado.value = true;
  error.value = null;
  try {
    if (paraModo === "cambiar") {
      await api.post(`${url.value}/whatsapp/cambio/codigo`, numeroNuevo.value);
    } else {
      await api.post(`${url.value}/whatsapp/codigo`, {});
    }
    modo.value = paraModo;
    codigo.value = "";
    esperarReenvio();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

function abrirCambio(): void {
  cambiando.value = true;
  nuevoPais.value = avisos.value?.pais ?? "52";
  nuevoNumero.value = "";
  modo.value = null;
  error.value = null;
}

function cerrarCambio(): void {
  cambiando.value = false;
  modo.value = null;
  error.value = null;
  clearInterval(cuentaRegresiva);
}

// Con WhatsApp con los dueños, primero el código al número nuevo; sin él, se guarda.
async function pedirCambio(): Promise<void> {
  if (avisos.value?.whatsapp) {
    await enviarCodigo("cambiar");
    return;
  }
  ocupado.value = true;
  error.value = null;
  try {
    const { data } = await api.put<{ data: Avisos }>(
      `${url.value}/whatsapp`,
      numeroNuevo.value,
    );
    avisos.value = data.data;
    cerrarCambio();
    toast.exito(t("avisosAgendaUno.cambiado"));
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

async function verificar(): Promise<void> {
  error.value = null;
  const cambio = modo.value === "cambiar";
  try {
    const { data } = cambio
      ? await api.put<{ data: Avisos }>(`${url.value}/whatsapp`, {
          ...numeroNuevo.value,
          codigo: codigo.value,
        })
      : await api.post<{ data: Avisos }>(`${url.value}/whatsapp/verificar`, {
          codigo: codigo.value,
        });
    avisos.value = data.data;
    cerrarCambio();
    toast.exito(
      t(cambio ? "avisosAgendaUno.cambiado" : "avisosAgendaUno.verificado"),
    );
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

    <template v-if="avisos.whatsapp || puedeCambiar">
      <div class="aa-fila mt-4">
        <div class="min-w-0">
          <p class="text-sm font-medium">
            {{ $t("avisosAgendaUno.whatsapp") }}
            <span class="font-normal" data-prueba="numero-whatsapp">{{
              avisos.numero ?? avisos.whatsapp?.numero ?? "—"
            }}</span>
            <button
              v-if="puedeCambiar && !cambiando"
              type="button"
              class="tu-enlace ml-2 text-xs font-normal"
              data-prueba="cambiar-whatsapp"
              @click="abrirCambio"
            >
              {{ $t("avisosAgendaUno.cambiar") }}
            </button>
          </p>
          <p
            v-if="avisos.whatsapp"
            class="aa-estado mt-0.5 text-xs"
            data-prueba="estado-whatsapp"
          >
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
          <p
            v-else
            class="mt-0.5 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("avisosAgendaUno.enTuPagina") }}
          </p>
        </div>
        <template v-if="avisos.whatsapp && !cambiando">
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
            v-else-if="modo === null"
            type="button"
            class="tu-btn tu-btn-fantasma text-sm shrink-0"
            data-prueba="verificar-whatsapp"
            :disabled="ocupado"
            @click="enviarCodigo('verificar')"
          >
            {{ $t("avisosAgendaUno.verificar") }}
          </button>
        </template>
      </div>

      <!-- Cambiar el número (ADR 0075). -->
      <form
        v-if="cambiando && modo === null"
        class="mt-3"
        data-prueba="cambio-whatsapp"
        @submit.prevent="pedirCambio"
      >
        <label class="tu-label" for="aa-numero">{{
          $t("avisosAgendaUno.numeroNuevo")
        }}</label>
        <div class="flex flex-wrap gap-2">
          <select
            v-model="nuevoPais"
            class="tu-input shrink-0 aa-lada"
            :aria-label="$t('avisosAgendaUno.lada')"
          >
            <option
              v-for="p in ladas"
              :key="p.lada"
              :value="p.lada"
              :title="p.nombre"
            >
              +{{ p.lada }}
            </option>
          </select>
          <input
            id="aa-numero"
            v-model="nuevoNumero"
            class="tu-input flex-1 min-w-0 aa-numero"
            type="tel"
            inputmode="tel"
            autocomplete="tel-national"
            required
          />
        </div>
        <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{
            avisos.whatsapp
              ? $t("avisosAgendaUno.cambioConCodigo")
              : $t("avisosAgendaUno.cambioSinCodigo")
          }}
        </p>
        <div class="mt-3 flex gap-2">
          <button
            class="tu-btn tu-btn-primario text-sm"
            type="submit"
            :disabled="ocupado || nuevoNumero.trim() === ''"
          >
            {{
              avisos.whatsapp
                ? $t("avisosAgendaUno.enviarCodigo")
                : $t("avisosAgendaUno.guardar")
            }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma text-sm"
            @click="cerrarCambio"
          >
            {{ $t("avisosAgendaUno.cancelar") }}
          </button>
        </div>
      </form>

      <div v-if="modo !== null" class="mt-3" data-prueba="codigo-whatsapp">
        <label class="tu-label" for="aa-codigo">{{
          modo === "cambiar"
            ? $t("avisosAgendaUno.codigoNuevo", {
                numero: `+${nuevoPais} ${nuevoNumero.trim()}`,
              })
            : $t("avisosAgendaUno.codigo")
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
            @click="enviarCodigo(modo ?? 'verificar')"
          >
            {{ $t("avisosAgendaUno.reenviar") }}
          </button>
          <template v-if="modo === 'cambiar'">
            ·
            <button type="button" class="tu-enlace" @click="cerrarCambio">
              {{ $t("avisosAgendaUno.cancelar") }}
            </button>
          </template>
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
.aa-lada {
  width: 5.5rem;
}
.aa-numero {
  max-width: 16rem;
}
.aa-codigo {
  max-width: 9rem;
  letter-spacing: 0.3em;
  font-variant-numeric: tabular-nums;
}
</style>
