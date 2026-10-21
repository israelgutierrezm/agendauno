<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import DetallePresupuesto, {
  type PresupuestoCliente,
} from "@/components/DetallePresupuesto.vue";
import RespuestaPresupuesto from "@/components/RespuestaPresupuesto.vue";
import { api } from "@/lib/api";
import { dinero } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Mis presupuestos en la cuenta (negocios de citas): los que el negocio me mandó, para
 * verlos, aceptarlos o rechazarlos y pagarlos. Sin presupuestos, no se muestra.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const lista = ref<PresupuestoCliente[]>([]);
const abierto = ref<string | null>(null);
const base = `/api/v1/app/${sesion.slug}/mi/presupuestos`;

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: PresupuestoCliente[] }>(base);
    lista.value = data.data;
    // El que espera respuesta, abierto.
    abierto.value ??= lista.value.find((p) => p.puede_responder)?.id ?? null;
  } catch {
    // Sin presupuestos visibles: la sección no aparece.
    lista.value = [];
  }
}

function vigencia(fecha: string): string {
  const [a, m, d] = fecha.split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
  }).format(new Date(a ?? 1970, (m ?? 1) - 1, d ?? 1));
}

function actualizar(p: PresupuestoCliente): void {
  const i = lista.value.findIndex((x) => x.id === p.id);
  if (i !== -1) {
    lista.value[i] = p;
  }
}

defineExpose({ cargar });
onMounted(cargar);
</script>

<template>
  <section v-if="lista.length > 0" data-prueba="mis-presupuestos">
    <h2 class="mp-seccion">{{ t("presupuestos.enLinea.misTitulo") }}</h2>
    <ul class="space-y-3">
      <li v-for="p in lista" :key="p.id" class="tu-card p-4">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          :aria-expanded="abierto === p.id"
          @click="abierto = abierto === p.id ? null : p.id"
        >
          <span class="min-w-0">
            <span class="font-medium">{{ p.folio }}</span>
            <span
              class="block text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                t("presupuestos.enLinea.valeHasta", {
                  fecha: vigencia(p.valido_hasta),
                })
              }}
            </span>
          </span>
          <span class="font-semibold tabular-nums">
            {{ dinero(p.total_minor, p.moneda, sesion.pais) }}
          </span>
        </button>
        <div v-if="abierto === p.id" class="mt-4">
          <DetallePresupuesto :presupuesto="p" :pais="sesion.pais" />
          <RespuestaPresupuesto
            class="mt-5"
            :presupuesto="p"
            :url="`${base}/${p.id}`"
            :slug="sesion.slug ?? ''"
            @actualizado="actualizar"
          />
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.mp-seccion {
  font-size: 1rem;
  font-weight: 600;
  margin: 1.5rem 0 0.75rem;
}
</style>
