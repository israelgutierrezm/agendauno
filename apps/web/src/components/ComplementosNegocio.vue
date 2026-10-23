<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { conTerminosActuales } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Complementos que el negocio puede pedir (ADR 0140): módulos que la plataforma
 * enciende por negocio, como Familias. Apagados no existen en el panel; aquí se
 * ofrecen como un plus, con lo que hacen, y se piden con un botón. La plataforma lo
 * enciende y aparece activo.
 */
interface Complemento {
  clave: string;
  /** Un módulo que se enciende, o un servicio de pago único (ADR 0188). */
  tipo?: "modulo" | "servicio";
  nombre: string;
  descripcion: string;
  activo: boolean;
  solicitado_en: string | null;
  // Con precio publicado (en pesos, sin IVA); null, incluido (ADR 0188).
  precio?: {
    alta_minor: number;
    mensual_minor: number | null;
    moneda: string;
    gratis: boolean;
  } | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedePedir = computed(() => sesion.puede("estudio.gestionar"));

const complementos = ref<Complemento[]>([]);
const pidiendo = ref<string | null>(null);

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Complemento[] }>(
      `${base.value}/renta/complementos`,
    );
    complementos.value = data.data;
  } catch {
    complementos.value = [];
  }
}

async function pedir(c: Complemento): Promise<void> {
  pidiendo.value = c.clave;
  try {
    const { data } = await api.post<{ data: Complemento }>(
      `${base.value}/renta/complementos/${c.clave}/solicitud`,
      {},
    );
    complementos.value = complementos.value.map((x) =>
      x.clave === c.clave ? data.data : x,
    );
    toast.exito(t("suscripcion.complementos.pedido", { nombre: c.nombre }));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    pidiendo.value = null;
  }
}

function precio(c: Complemento): string | null {
  const p = c.precio;
  if (!p) {
    return null;
  }
  const fmt = (minor: number) =>
    new Intl.NumberFormat("es-MX", {
      style: "currency",
      currency: p.moneda,
      maximumFractionDigits: 0,
    }).format(minor / 100);
  if (p.gratis) {
    return t("suscripcion.complementos.gratisConAnual");
  }
  return p.mensual_minor
    ? t("suscripcion.complementos.precioMensual", {
        alta: fmt(p.alta_minor),
        mensual: fmt(p.mensual_minor),
      })
    : t("suscripcion.complementos.precioUnico", { precio: fmt(p.alta_minor) });
}

function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
    timeZone: sesion.zonaHoraria ?? undefined,
  }).format(new Date(iso));
}

onMounted(cargar);
</script>

<template>
  <section
    v-if="complementos.length > 0"
    class="mt-8"
    data-prueba="complementos"
  >
    <h2 class="font-medium text-lg">
      {{ $t("suscripcion.complementos.titulo") }}
    </h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("suscripcion.complementos.ayuda") }}
    </p>
    <ul class="mt-3 grid gap-3 sm:grid-cols-2">
      <li
        v-for="c in complementos"
        :key="c.clave"
        class="tu-card p-4 grid gap-2"
        :data-complemento="c.clave"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-medium">{{ c.nombre }}</h3>
          <span
            v-if="c.activo"
            class="tu-estado text-sm"
            :style="{ '--tono': 'var(--exito)' }"
          >
            {{ $t("suscripcion.complementos.activo") }}
          </span>
          <span
            v-else-if="c.solicitado_en"
            class="tu-estado text-sm"
            :style="{ '--tono': 'var(--aviso)' }"
          >
            {{
              $t("suscripcion.complementos.solicitado", {
                fecha: fecha(c.solicitado_en),
              })
            }}
          </span>
        </div>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ conTerminosActuales(c.descripcion) }}
        </p>
        <p
          v-if="precio(c)"
          class="text-sm font-medium"
          data-prueba="precio-complemento"
        >
          {{ precio(c) }}
        </p>
        <button
          v-if="!c.activo && puedePedir"
          type="button"
          class="tu-btn tu-btn-secundario justify-self-start"
          :disabled="pidiendo !== null"
          data-prueba="pedir-complemento"
          @click="pedir(c)"
        >
          {{
            c.solicitado_en
              ? $t("suscripcion.complementos.pedirOtraVez")
              : $t("suscripcion.complementos.pedir")
          }}
        </button>
      </li>
    </ul>
  </section>
</template>
