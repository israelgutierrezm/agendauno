<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useToastStore } from "@/stores/toast";

/**
 * WhatsApp por mensaje (ADR 0186): los avisos que el negocio puede mandar por
 * WhatsApp (las plantillas aprobadas de la plataforma), cuánto cuesta cada mensaje, su
 * saldo y lo usado este mes, y la compra de un paquete (en la página de Stripe). Solo
 * se muestra si WhatsApp está activo en el negocio.
 */
interface Saldo {
  activo: boolean;
  cobra: boolean;
  precio_minor: number;
  moneda: string;
  disponibles: number;
  usados_mes: number;
  tope_mensual: number;
  paquetes: { cantidad: number; precio_minor: number }[];
  plantillas: {
    evento: string;
    titulo: string;
    texto: string;
    categoria: string;
    activo: boolean;
  }[];
}

const props = withDefaults(
  defineProps<{ base: string; puedeGestionar?: boolean }>(),
  { puedeGestionar: true },
);
const { t } = useI18n();
const toast = useToastStore();
const saldo = ref<Saldo | null>(null);
const paquete = ref<number | null>(null);
const comprando = ref(false);

const dinero = (minor: number): string =>
  new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: saldo.value?.moneda ?? "MXN",
  }).format(minor / 100);
const activas = computed(
  () => saldo.value?.plantillas.filter((p) => p.activo).length ?? 0,
);

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Saldo }>(
      `${props.base}/whatsapp/saldo`,
    );
    saldo.value = data.data.activo ? data.data : null;
    paquete.value = data.data.paquetes[0]?.cantidad ?? null;
  } catch {
    saldo.value = null;
  }
}

async function comprar(): Promise<void> {
  if (paquete.value === null) {
    return;
  }
  comprando.value = true;
  try {
    const { data } = await api.post<{ data: { checkout?: { url?: string } } }>(
      `${props.base}/whatsapp/comprar`,
      { cantidad: paquete.value, retorno: "/renta" },
    );
    const url = data.data.checkout?.url;
    if (typeof url === "string" && url !== "") {
      window.location.href = url;
      return;
    }
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    comprando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <div v-if="saldo" class="tu-card mt-4 p-5" data-prueba="whatsapp-renta">
    <h2 class="font-medium">{{ $t("whatsappRenta.titulo") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{
        saldo.cobra
          ? $t("whatsappRenta.cobra", { precio: dinero(saldo.precio_minor) })
          : $t("whatsappRenta.incluido")
      }}
    </p>

    <dl v-if="saldo.cobra" class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
      <div>
        <dt class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("whatsappRenta.disponibles") }}
        </dt>
        <dd
          class="text-lg font-semibold tabular-nums"
          data-prueba="whatsapp-disponibles"
        >
          {{ saldo.disponibles }}
        </dd>
      </div>
      <div>
        <dt class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("whatsappRenta.usadosMes") }}
        </dt>
        <dd class="text-lg font-semibold tabular-nums">
          {{ saldo.usados_mes }}
        </dd>
      </div>
      <div>
        <dt class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("whatsappRenta.tope") }}
        </dt>
        <dd class="text-lg font-semibold tabular-nums">
          {{
            saldo.tope_mensual > 0
              ? saldo.tope_mensual
              : $t("whatsappRenta.sinTope")
          }}
        </dd>
      </div>
    </dl>
    <p
      v-if="saldo.cobra"
      class="mt-1 text-xs"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("whatsappRenta.topeAyuda") }}
    </p>

    <form
      v-if="saldo.cobra && puedeGestionar && saldo.paquetes.length > 0"
      class="mt-3 flex flex-wrap items-end gap-2"
      @submit.prevent="comprar"
    >
      <label>
        <span class="tu-label">{{ $t("whatsappRenta.paquete") }}</span>
        <select v-model="paquete" class="tu-input">
          <option
            v-for="p in saldo.paquetes"
            :key="p.cantidad"
            :value="p.cantidad"
          >
            {{
              $t("whatsappRenta.opcion", {
                n: p.cantidad,
                precio: dinero(p.precio_minor),
              })
            }}
          </option>
        </select>
      </label>
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="comprando"
        data-prueba="comprar-whatsapp"
      >
        {{ $t("whatsappRenta.comprar") }}
      </button>
    </form>

    <details class="mt-4">
      <summary class="cursor-pointer text-sm font-medium">
        {{ t("whatsappRenta.plantillas", { n: activas }) }}
      </summary>
      <ul class="mt-2 space-y-2 text-sm">
        <li v-for="p in saldo.plantillas" :key="p.evento">
          <p class="font-medium">
            {{ p.titulo }}
            <span class="text-xs" :style="{ color: 'var(--texto-suave)' }"
              >·
              {{
                p.activo
                  ? $t("whatsappRenta.encendido")
                  : $t("whatsappRenta.apagado")
              }}</span
            >
          </p>
          <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ p.texto }}
          </p>
        </li>
      </ul>
      <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("whatsappRenta.plantillasAyuda") }}
      </p>
    </details>
  </div>
</template>
