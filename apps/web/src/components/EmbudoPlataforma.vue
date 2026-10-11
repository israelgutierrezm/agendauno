<script setup lang="ts">
import axios from "axios";
import { onMounted, ref, watch } from "vue";

import { mensajeDeError } from "@/lib/api";
import { PRODUCTOS, esProducto } from "@/lib/producto";

/**
 * Embudo de la plataforma (C08), para el superadmin: de la visita a la landing al
 * primer pago, por producto. Los negocios de la cohorte son los que se registraron en
 * el periodo (sin los demos); cada paso dice cuántos y de cuántos, y la mediana de
 * días hasta publicar y hasta la primera operación real.
 */
interface FilaEmbudo {
  producto: string;
  visitas_landing: number;
  registros: number;
  verificados: number;
  publicados: number;
  primera_operacion: number;
  primer_pago: number;
  dias_a_publicar: number | null;
  dias_a_operar: number | null;
}

const props = defineProps<{ apiUrl: string; token: string }>();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});

function hace(dias: number): string {
  const d = new Date();
  d.setDate(d.getDate() - dias);
  return d.toISOString().slice(0, 10);
}
const desde = ref(hace(89));
const hasta = ref(hace(0));
const filas = ref<FilaEmbudo[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await cliente.get<{ data: { productos: FilaEmbudo[] } }>(
      "/api/v1/plataforma/embudo",
      {
        params: { desde: desde.value, hasta: hasta.value },
        headers: { Authorization: `Bearer ${props.token}` },
      },
    );
    filas.value = data.data.productos;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}
watch([desde, hasta], cargar);
onMounted(cargar);

function nombre(producto: string): string {
  return esProducto(producto) ? PRODUCTOS[producto].nombre : producto;
}
function pct(n: number, de: number): string {
  return de > 0 ? `${Math.round((n / de) * 100)} %` : "—";
}
function dias(d: number | null): string {
  return d === null ? "—" : `${d} d`;
}
</script>

<template>
  <section data-prueba="embudo-plataforma">
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("plataformaAdmin.embudo.texto") }}
    </p>
    <div class="mt-3 flex flex-wrap items-end gap-3">
      <label class="text-sm">
        <span class="tu-label">{{ $t("plataformaAdmin.embudo.desde") }}</span>
        <input v-model="desde" type="date" class="tu-input w-auto" />
      </label>
      <label class="text-sm">
        <span class="tu-label">{{ $t("plataformaAdmin.embudo.hasta") }}</span>
        <input v-model="hasta" type="date" class="tu-input w-auto" />
      </label>
    </div>
    <p v-if="error" class="mt-3 text-sm" :style="{ color: 'var(--error)' }">
      {{ error }}
    </p>
    <p
      v-else-if="cargando"
      class="mt-3 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <div v-else class="mt-4 grid gap-4 md:grid-cols-2">
      <article
        v-for="f in filas"
        :key="f.producto"
        class="tu-card px-5 py-4"
        :data-producto="f.producto"
      >
        <h3 class="font-medium">{{ nombre(f.producto) }}</h3>
        <dl class="mt-3 grid gap-2 text-sm">
          <div class="flex justify-between gap-3">
            <dt>{{ $t("plataformaAdmin.embudo.visitas") }}</dt>
            <dd class="tabular-nums">{{ f.visitas_landing }}</dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt>{{ $t("plataformaAdmin.embudo.registros") }}</dt>
            <dd class="tabular-nums">
              {{ f.registros }}
              <span :style="{ color: 'var(--texto-suave)' }"
                >· {{ pct(f.registros, f.visitas_landing) }}</span
              >
            </dd>
          </div>
          <div
            v-for="paso in [
              ['verificados', f.verificados],
              ['publicados', f.publicados],
              ['primeraOperacion', f.primera_operacion],
              ['primerPago', f.primer_pago],
            ] as const"
            :key="paso[0]"
            class="flex justify-between gap-3"
          >
            <dt>{{ $t(`plataformaAdmin.embudo.${paso[0]}`) }}</dt>
            <dd class="tabular-nums">
              {{
                $t("plataformaAdmin.embudo.deRegistros", {
                  n: paso[1],
                  de: f.registros,
                })
              }}
              <span :style="{ color: 'var(--texto-suave)' }"
                >· {{ pct(paso[1], f.registros) }}</span
              >
            </dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt>{{ $t("plataformaAdmin.embudo.diasPublicar") }}</dt>
            <dd class="tabular-nums">{{ dias(f.dias_a_publicar) }}</dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt>{{ $t("plataformaAdmin.embudo.diasOperar") }}</dt>
            <dd class="tabular-nums">{{ dias(f.dias_a_operar) }}</dd>
          </div>
        </dl>
      </article>
    </div>
  </section>
</template>
