<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { mensajeDeError } from "@/lib/api";
import { useCambiosPendientes } from "@/lib/cambiosPendientes";
import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * Tipo de cambio para cobrar en pesos la renta en dólares (ADR 0107): vale para las dos
 * tarifas; compacto, con el valor vigente y su captura en una fila. Lo captura el perfil
 * total o cobranza (ADR 0215); los demás solo lo ven.
 */
interface TipoCambio {
  fecha: string;
  valor: string;
  fuente: string;
}

const props = defineProps<{
  apiUrl: string;
  token: string;
  soloLectura?: boolean;
}>();
const { t } = useI18n();
const toast = useToastStore();

const banxico = ref(false);
const ultimoCambio = ref<TipoCambio | null>(null);
const nuevoCambio = ref("");
const guardandoCambio = ref(false);
// El error del valor capturado va debajo de su campo.
const errorCambio = ref<string | null>(null);

function cliente() {
  return crearClientePlataforma({
    baseURL: props.apiUrl,
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${props.token}`,
    },
  });
}

async function cargarCambio(): Promise<void> {
  try {
    const { data } = await cliente().get<{
      data: { banxico_configurado: boolean; ultimo: TipoCambio | null };
    }>("/api/v1/plataforma/tipo-cambio");
    banxico.value = data.data.banxico_configurado;
    ultimoCambio.value = data.data.ultimo;
  } catch (e) {
    errorCambio.value = mensajeDeError(e);
  }
}

async function guardarCambio(): Promise<void> {
  // Un error de dedo aquí cambia lo que se cobra a los negocios en pesos.
  if (
    !(await confirmar(
      t("suscripcion.plataforma.tipoCambio.confirmar", {
        valor: nuevoCambio.value.trim(),
      }),
      { aceptar: t("suscripcion.plataforma.tipoCambio.guardar") },
    ))
  ) {
    return;
  }
  guardandoCambio.value = true;
  errorCambio.value = null;
  try {
    const { data } = await cliente().put<{
      data: { banxico_configurado: boolean; ultimo: TipoCambio | null };
    }>("/api/v1/plataforma/tipo-cambio", { valor: nuevoCambio.value });
    ultimoCambio.value = data.data.ultimo;
    nuevoCambio.value = "";
    toast.exito(t("suscripcion.plataforma.tipoCambio.guardado"));
  } catch (e) {
    errorCambio.value = mensajeDeError(e);
  } finally {
    guardandoCambio.value = false;
  }
}

function fecha(iso: string): string {
  const [a, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(a, m - 1, d),
  );
}

// Salir con un tipo de cambio escrito sin guardar pregunta antes (ADR 0166).
useCambiosPendientes(() => String(nuevoCambio.value).trim() !== "");

onMounted(cargarCambio);
</script>

<template>
  <form
    class="tu-card p-4 mt-4"
    data-prueba="tipo-cambio"
    @submit.prevent="guardarCambio"
  >
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
      <div class="min-w-0 flex-1 space-y-1">
        <h2 class="tu-titulo-seccion">
          {{ $t("suscripcion.plataforma.tipoCambio.titulo") }}
        </h2>
        <p class="font-semibold">
          <template v-if="ultimoCambio">
            {{
              $t("suscripcion.plataforma.tipoCambio.ultimo", {
                valor: ultimoCambio.valor,
                fecha: fecha(ultimoCambio.fecha),
              })
            }}
            <span
              class="text-sm font-normal"
              :style="{ color: 'var(--texto-suave)' }"
            >
              ·
              {{
                ultimoCambio.fuente === "banxico"
                  ? $t("suscripcion.plataforma.tipoCambio.fuenteBanxico")
                  : $t("suscripcion.plataforma.tipoCambio.fuenteManual")
              }}
            </span>
          </template>
          <template v-else>{{
            $t("suscripcion.plataforma.tipoCambio.sinValor")
          }}</template>
        </p>
        <p class="text-sm">
          {{
            banxico
              ? $t("suscripcion.plataforma.tipoCambio.banxico")
              : $t("suscripcion.plataforma.tipoCambio.sinBanxico")
          }}
        </p>
      </div>
      <div v-if="!soloLectura" class="flex items-end gap-2">
        <label class="block">
          <span class="tu-label">{{
            $t("suscripcion.plataforma.tipoCambio.valor")
          }}</span>
          <input
            v-model="nuevoCambio"
            class="tu-input w-36"
            inputmode="decimal"
            placeholder="17.2345"
            :aria-invalid="errorCambio ? 'true' : undefined"
            aria-describedby="tipo-cambio-error"
          />
        </label>
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="guardandoCambio || nuevoCambio.trim() === ''"
          :aria-busy="guardandoCambio || undefined"
        >
          {{ $t("suscripcion.plataforma.tipoCambio.guardar") }}
        </button>
      </div>
    </div>
    <p class="mt-3 text-xs" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("suscripcion.plataforma.tipoCambio.ayuda") }}
    </p>
    <AvisoTono
      v-if="errorCambio"
      id="tipo-cambio-error"
      class="mt-3"
      tono="peligro"
      role="alert"
    >
      {{ errorCambio }}
    </AvisoTono>
  </form>
</template>
