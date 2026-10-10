<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * Cobro automático de la suscripción (domiciliación, ADR 0107): la tarjeta se guarda
 * en la página de Stripe y cada cobro se paga solo. Al volver (`?tarjeta=exito&sesion=…`)
 * se confirma con la sesión.
 */
interface Tarjeta {
  marca: string | null;
  ultimos4: string | null;
  vence: string | null;
}

const props = defineProps<{
  base: string;
  tarjeta: Tarjeta | null;
  posible: boolean;
}>();
const emit = defineEmits<{ cambio: [] }>();

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const toast = useToastStore();

const ocupado = ref(false);

function marca(m: string | null): string {
  if (m === null || m === "") {
    return t("suscripcion.tarjeta.titulo");
  }
  return m.charAt(0).toUpperCase() + m.slice(1);
}

async function guardar(): Promise<void> {
  ocupado.value = true;
  try {
    const { data } = await api.post<{ data: { url: string } }>(
      `${props.base}/renta/tarjeta`,
      {},
    );
    window.location.href = data.data.url;
  } catch (e) {
    toast.error(mensajeDeError(e));
    ocupado.value = false;
  }
}

async function quitar(): Promise<void> {
  if (
    !(await confirmar(t("suscripcion.tarjeta.confirmarQuitar"), {
      aceptar: t("suscripcion.tarjeta.quitar"),
      peligro: true,
    }))
  ) {
    return;
  }
  ocupado.value = true;
  try {
    await api.delete(`${props.base}/renta/tarjeta`);
    toast.exito(t("suscripcion.tarjeta.quitada"));
    emit("cambio");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

// Al volver de Stripe: se confirma la tarjeta con la sesión y se limpia la URL.
onMounted(async () => {
  const resultado = route.query.tarjeta;
  const sesion = route.query.sesion;
  if (resultado !== "exito" && resultado !== "cancelado") {
    return;
  }
  const resto = { ...route.query };
  delete resto.tarjeta;
  delete resto.sesion;
  void router.replace({ query: resto });
  if (resultado === "cancelado") {
    toast.error(t("suscripcion.tarjeta.cancelado"));
    return;
  }
  if (typeof sesion !== "string" || sesion === "") {
    return;
  }
  ocupado.value = true;
  try {
    await api.post(`${props.base}/renta/tarjeta/confirmar`, { sesion });
    toast.exito(t("suscripcion.tarjeta.guardada"));
    emit("cambio");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
});
</script>

<template>
  <div class="tu-card p-5" data-prueba="tarjeta-renta">
    <h2 class="font-medium">{{ $t("suscripcion.tarjeta.titulo") }}</h2>
    <template v-if="tarjeta">
      <p class="mt-2 font-semibold">
        {{
          $t("suscripcion.tarjeta.activa", {
            marca: marca(tarjeta.marca),
            ultimos4: tarjeta.ultimos4 ?? "····",
          })
        }}
      </p>
      <p v-if="tarjeta.vence" class="mt-1 text-sm tr-suave">
        {{ $t("suscripcion.tarjeta.vence", { fecha: tarjeta.vence }) }}
      </p>
    </template>
    <p class="mt-2 text-sm tr-suave">{{ $t("suscripcion.tarjeta.ayuda") }}</p>
    <p v-if="!posible" class="mt-2 text-sm tr-suave">
      {{ $t("suscripcion.tarjeta.noDisponible") }}
    </p>
    <div v-else class="mt-4 flex gap-2 flex-wrap">
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="ocupado"
        data-prueba="guardar-tarjeta"
        @click="guardar"
      >
        {{
          ocupado
            ? $t("suscripcion.tarjeta.abriendo")
            : tarjeta
              ? $t("suscripcion.tarjeta.cambiar")
              : $t("suscripcion.tarjeta.guardar")
        }}
      </button>
      <button
        v-if="tarjeta"
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="ocupado"
        data-prueba="quitar-tarjeta"
        @click="quitar"
      >
        {{ $t("suscripcion.tarjeta.quitar") }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.tr-suave {
  color: var(--texto-suave);
}
</style>
