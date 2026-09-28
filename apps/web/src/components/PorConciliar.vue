<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Por conciliar: lo del dinero que necesita que alguien lo revise (p. ej. una
 * devolución que la pasarela no confirmó). Una devolución sin confirmar se resuelve
 * diciendo si la pasarela la hizo o no (se ve en su panel), con una nota. Solo se
 * muestra si hay algo pendiente.
 */
interface Incidencia {
  id: string;
  tipo: string;
  detalle: string;
  fecha: string | null;
  persona: string | null;
  monto_minor: number | null;
  moneda: string | null;
  reembolso: {
    estado: string;
    monto_minor: number;
    moneda: string;
    proveedor: string | null;
    motivo_fallo: string | null;
  } | null;
}

const emit = defineEmits<{ cambio: [] }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeResolver = computed(() => sesion.puede("pagos.reembolsar"));

const incidencias = ref<Incidencia[]>([]);
const abierta = ref<string | null>(null);
const nota = ref("");
const guardando = ref(false);
const error = ref<string | null>(null);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda || "MXN",
  }).format(minor / 100);
}

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Incidencia[] }>(
      `${base.value}/incidencias-cobro`,
    );
    incidencias.value = data.data;
  } catch {
    // Sin permiso o sin red: la sección simplemente no aparece.
    incidencias.value = [];
  }
}

function abrir(i: Incidencia): void {
  abierta.value = abierta.value === i.id ? null : i.id;
  nota.value = "";
  error.value = null;
}

async function resolver(
  i: Incidencia,
  reembolso: "aprobado" | "fallido" | null,
  accion: "devolver" | null = null,
): Promise<void> {
  if (nota.value.trim() === "") {
    error.value = t("porConciliar.faltaNota");
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/incidencias-cobro/${i.id}/resolver`, {
      resolucion: nota.value.trim(),
      reembolso,
      accion,
    });
    abierta.value = null;
    await cargar();
    emit("cambio");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
defineExpose({ cargar });
</script>

<template>
  <section v-if="incidencias.length > 0" class="mt-8">
    <h2 class="font-light text-lg">{{ $t("porConciliar.titulo") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("porConciliar.ayuda") }}
    </p>
    <ul class="mt-3 tu-card divide-y" :style="{ borderColor: 'var(--borde)' }">
      <li v-for="i in incidencias" :key="i.id" class="px-4 py-3 text-sm">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium">
              <template v-if="i.reembolso">
                {{
                  $t("porConciliar.devolucion", {
                    monto: dinero(i.reembolso.monto_minor, i.reembolso.moneda),
                  })
                }}
              </template>
              <template v-else-if="i.monto_minor !== null">
                {{
                  $t(
                    i.tipo === "pago_duplicado"
                      ? "porConciliar.pagoDuplicado"
                      : "porConciliar.pagoTardio",
                    { monto: dinero(i.monto_minor, i.moneda ?? "MXN") },
                  )
                }}
              </template>
              <template v-if="i.persona"> · {{ i.persona }}</template>
            </p>
            <p class="mt-0.5" :style="{ color: 'var(--texto-suave)' }">
              {{ i.detalle }}
            </p>
          </div>
          <button
            v-if="puedeResolver"
            type="button"
            class="tu-enlace shrink-0"
            :aria-expanded="abierta === i.id"
            @click="abrir(i)"
          >
            {{ $t("porConciliar.resolver") }}
          </button>
        </div>

        <div v-if="abierta === i.id" class="mt-3 space-y-2">
          <label class="tu-label" :for="`nota-${i.id}`">{{
            $t("porConciliar.nota")
          }}</label>
          <input
            :id="`nota-${i.id}`"
            v-model="nota"
            class="tu-input"
            maxlength="500"
            :placeholder="$t('porConciliar.notaEjemplo')"
          />
          <p v-if="error" class="text-sm" style="color: var(--error)">
            {{ error }}
          </p>
          <div class="flex flex-wrap gap-2">
            <template
              v-if="
                i.reembolso &&
                i.reembolso.estado !== 'aprobado' &&
                i.reembolso.estado !== 'fallido'
              "
            >
              <button
                type="button"
                class="tu-btn tu-btn-primario"
                :disabled="guardando"
                @click="resolver(i, 'aprobado')"
              >
                {{ $t("porConciliar.siSeDevolvio") }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma"
                :disabled="guardando"
                @click="resolver(i, 'fallido')"
              >
                {{ $t("porConciliar.noSeDevolvio") }}
              </button>
            </template>
            <template v-else>
              <button
                v-if="i.reembolso === null && i.monto_minor !== null"
                type="button"
                class="tu-btn tu-btn-primario"
                :disabled="guardando"
                @click="resolver(i, null, 'devolver')"
              >
                {{ $t("porConciliar.devolverPago") }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma"
                :disabled="guardando"
                @click="resolver(i, null)"
              >
                {{ $t("porConciliar.marcarResuelta") }}
              </button>
            </template>
          </div>
        </div>
      </li>
    </ul>
  </section>
</template>
