<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { dinero } from "@/lib/formato";
import { textoCampana } from "@/lib/captacion";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * El reporte por campaña del negocio (ADR 0144), debajo de su embudo: por la campaña
 * que se acredita (su atribución propia, no la de Google ni la de Meta), cuántas
 * solicitudes, compradores nuevos, compras, devoluciones y primeras reservas hubo, y
 * aparte las renovaciones. Sin gasto publicitario capturado no hay costo por resultado.
 */
export interface FilaCampana {
  campana: {
    campana: string | null;
    fuente: string | null;
    medio: string | null;
    referente: string | null;
  } | null;
  solicitudes: number;
  nuevos_compradores: number;
  compras: number;
  compras_minor: number;
  devoluciones: number;
  devoluciones_minor: number;
  primeras_reservas: number;
  renovaciones: number;
  renovaciones_minor: number;
}

const props = defineProps<{ desde: string; hasta: string }>();
const { t } = useI18n();
const sesion = useSesionTenantStore();

const filas = ref<FilaCampana[]>([]);
const moneda = ref("MXN");
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: { moneda: string; filas: FilaCampana[] };
    }>(`/api/v1/app/${sesion.slug}/reportes/campanas`, {
      params: { desde: props.desde, hasta: props.hasta },
    });
    filas.value = data.data.filas;
    moneda.value = data.data.moneda;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}
watch(() => [props.desde, props.hasta], cargar, { immediate: true });

const nombre = (f: FilaCampana): string =>
  f.campana !== null && textoCampana(f.campana) !== ""
    ? textoCampana(f.campana)
    : t("medicion.reporte.sinAtribucion");
const plata = (minor: number): string => dinero(minor, moneda.value);

const cifras = computed(() =>
  filas.value.map((f) => ({
    fila: f,
    cifras: [
      { clave: "solicitudes", valor: String(f.solicitudes) },
      { clave: "nuevos", valor: String(f.nuevos_compradores) },
      {
        clave: "compras",
        valor: `${f.compras} · ${plata(f.compras_minor)}`,
      },
      {
        clave: "devoluciones",
        valor: `${f.devoluciones} · ${plata(f.devoluciones_minor)}`,
      },
      { clave: "reservas", valor: String(f.primeras_reservas) },
      {
        clave: "renovaciones",
        valor: `${f.renovaciones} · ${plata(f.renovaciones_minor)}`,
      },
    ],
  })),
);
</script>

<template>
  <section class="mt-6" data-prueba="campanas-negocio">
    <h3 class="font-semibold">{{ t("medicion.reporte.titulo") }}</h3>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ t("medicion.reporte.ayuda") }}
    </p>
    <p
      v-if="cargando"
      class="mt-3 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("comun.cargando") }}
    </p>
    <p
      v-else-if="error"
      class="mt-3 text-sm"
      :style="{ color: 'var(--error)' }"
    >
      {{ error }}
    </p>
    <p
      v-else-if="filas.length === 0"
      class="mt-3 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("medicion.reporte.vacio") }}
    </p>
    <ul v-else class="mt-3 space-y-3">
      <li
        v-for="(c, i) in cifras"
        :key="i"
        class="tu-card px-5 py-4"
        data-prueba="fila-campana"
      >
        <div class="font-medium">{{ nombre(c.fila) }}</div>
        <dl class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <div v-for="x in c.cifras" :key="x.clave">
            <dt class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ t(`medicion.reporte.columnas.${x.clave}`) }}
            </dt>
            <dd class="tabular-nums">{{ x.valor }}</dd>
          </div>
        </dl>
      </li>
    </ul>
    <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
      {{ t("medicion.reporte.leyenda") }}
    </p>
  </section>
</template>
