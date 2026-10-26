<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { dinero as dineroDelPais } from "@/lib/formato";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Mis comisiones (ADR 0219): lo que el profesional lleva ganado en el periodo con su
 * esquema (lo ya atendido y lo que viene) y sus propinas. Solo lo suyo; lo paga el
 * negocio en su corte de nómina.
 */
interface Fila {
  tipo: string;
  porcentaje_bp?: number | null;
  monto_minor?: number;
  unidades: number;
  monto_total_minor: number;
  por_venir_minor?: number;
  moneda: string;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();

const hoy = hoyEnNegocio(sesion.zonaHoraria);
const desde = ref(`${hoy.slice(0, 8)}01`);
const hasta = ref(hoy);
const filas = ref<Fila[]>([]);
const cargando = ref(false);
const error = ref<string | null>(null);

function dinero(minor: number, moneda: string): string {
  return dineroDelPais(minor, moneda, sesion.pais);
}

const moneda = computed(() => filas.value[0]?.moneda ?? sesion.moneda);
const comision = computed(() =>
  filas.value
    .filter((f) => f.tipo !== "propinas")
    .reduce((s, f) => s + f.monto_total_minor, 0),
);
const porVenir = computed(() =>
  filas.value.reduce((s, f) => s + (f.por_venir_minor ?? 0), 0),
);
const propinas = computed(() =>
  filas.value
    .filter((f) => f.tipo === "propinas")
    .reduce((s, f) => s + f.monto_total_minor, 0),
);
const atendidas = computed(() =>
  filas.value
    .filter((f) => f.tipo !== "propinas")
    .reduce((s, f) => s + f.unidades, 0),
);
const esquema = computed(() => {
  const f = filas.value.filter((x) => x.tipo !== "propinas").at(-1);
  if (!f) return null;
  return f.tipo === "porcentaje_venta" && f.porcentaje_bp
    ? t("misComisiones.porcentaje", { n: f.porcentaje_bp / 100 })
    : t("misComisiones.fijo");
});

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Fila[] }>(
      `/api/v1/app/${sesion.slug}/mis-comisiones`,
      { params: { desde: desde.value, hasta: hasta.value } },
    );
    filas.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

watch([desde, hasta], () => {
  if (desde.value && hasta.value && desde.value <= hasta.value) {
    void cargar();
  }
});
onMounted(() => void cargar());
</script>

<template>
  <section class="tu-pagina" data-prueba="mis-comisiones">
    <EncabezadoSeccion
      :titulo="$t('misComisiones.titulo')"
      :subtitulo="$t('misComisiones.subtitulo')"
    />
    <div class="mt-4 flex flex-wrap items-end gap-3">
      <div>
        <label class="tu-label" for="mc-desde">{{
          $t("misComisiones.desde")
        }}</label>
        <input id="mc-desde" v-model="desde" type="date" class="tu-input" />
      </div>
      <div>
        <label class="tu-label" for="mc-hasta">{{
          $t("misComisiones.hasta")
        }}</label>
        <input id="mc-hasta" v-model="hasta" type="date" class="tu-input" />
      </div>
    </div>

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">{{
      error
    }}</AvisoTono>
    <p
      v-else-if="!cargando && filas.length === 0"
      class="mt-6"
      :style="{ color: 'var(--texto-suave)' }"
      data-prueba="sin-comisiones"
    >
      {{ $t("misComisiones.vacio") }}
    </p>
    <div v-else class="mc-tarjetas mt-6" :aria-busy="cargando || undefined">
      <div class="tu-card p-4">
        <p class="mc-etiqueta">{{ $t("misComisiones.ganado") }}</p>
        <p class="mc-valor" data-prueba="ganado">
          {{ dinero(comision + propinas, moneda) }}
        </p>
        <p v-if="esquema" class="mc-nota">
          {{ esquema }} ·
          {{ $t("misComisiones.atendidas", { n: atendidas }, atendidas) }}
        </p>
      </div>
      <div class="tu-card p-4">
        <p class="mc-etiqueta">{{ $t("misComisiones.propinas") }}</p>
        <p class="mc-valor" data-prueba="propinas">
          {{ dinero(propinas, moneda) }}
        </p>
      </div>
      <div class="tu-card p-4">
        <p class="mc-etiqueta">{{ $t("misComisiones.porVenir") }}</p>
        <p class="mc-valor">{{ dinero(porVenir, moneda) }}</p>
        <p class="mc-nota">{{ $t("misComisiones.porVenirAyuda") }}</p>
      </div>
    </div>
  </section>
</template>

<style scoped>
.mc-tarjetas {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 1rem;
}
.mc-etiqueta {
  color: var(--texto-suave);
  font-size: 0.875rem;
}
.mc-valor {
  margin-top: 0.25rem;
  font-size: 1.5rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.mc-nota {
  margin-top: 0.25rem;
  color: var(--texto-suave);
  font-size: 0.8125rem;
}
</style>
