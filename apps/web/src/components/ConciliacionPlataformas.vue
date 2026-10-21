<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { api, erroresPorCampo, mensajeDeError } from "@/lib/api";
import { dinero } from "@/lib/miCuenta";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Conciliación de pagos de Wellhub y TotalPass (ADR 0185). Ninguna publica sus pagos
 * por API: el negocio captura cuánto le pagan por visita e importa el reporte de pagos
 * del mes (CSV, del portal de la plataforma). Se compara con sus visitas validadas.
 */
interface Resumen {
  moneda: string;
  visitas: number;
  visitas_sin_tarifa: number;
  esperado_minor: number;
  recibido_minor: number;
  diferencia_minor: number;
  importado: {
    archivo: string | null;
    lineas: number;
    fecha: string | null;
  } | null;
  visitas_sin_pago: {
    id: string;
    fecha: string;
    clase: string | null;
    referencia: string | null;
  }[];
  pagos_sin_visita: {
    fecha: string;
    referencia: string | null;
    monto_minor: number;
  }[];
  montos_distintos: {
    fecha: string;
    referencia: string | null;
    monto_minor: number;
    esperado_minor: number | null;
  }[];
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeImportar = computed(() => sesion.puede("estudio.gestionar"));

// El mes anterior: el que ya se puede conciliar.
const hoy = new Date();
const anterior = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1);
const periodo = ref(
  `${anterior.getFullYear()}-${String(anterior.getMonth() + 1).padStart(2, "0")}`,
);
const proveedor = ref<"wellhub" | "totalpass" | "fitpass">("totalpass");
const resumen = ref<Resumen | null>(null);
const error = ref<string | null>(null);
const ocupado = ref(false);
const tarifa = ref("");
const archivo = ref<File | null>(null);
const errorArchivo = ref<string | null>(null);

const moneda = computed(() => resumen.value?.moneda ?? sesion.moneda ?? "MXN");

async function cargar(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{ data: Resumen }>(
      `${base.value}/plataformas/conciliacion`,
      { params: { proveedor: proveedor.value, periodo: periodo.value } },
    );
    resumen.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function guardarTarifa(): Promise<void> {
  const monto = Number(tarifa.value.replace(",", "."));
  if (!Number.isFinite(monto) || monto < 0) {
    return;
  }
  ocupado.value = true;
  try {
    await api.post(`${base.value}/plataformas/tarifas`, {
      proveedor: proveedor.value,
      monto_minor: Math.round(monto * 100),
      vigente_desde: `${periodo.value}-01`,
    });
    toast.exito(t("operacion.plataformas.conciliacion.tarifaGuardada"));
    tarifa.value = "";
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

function elegirArchivo(evento: Event): void {
  archivo.value = (evento.target as HTMLInputElement).files?.[0] ?? null;
  errorArchivo.value = null;
}

async function importar(): Promise<void> {
  if (!archivo.value) {
    return;
  }
  ocupado.value = true;
  errorArchivo.value = null;
  const datos = new FormData();
  datos.append("proveedor", proveedor.value);
  datos.append("periodo", periodo.value);
  datos.append("archivo", archivo.value);
  try {
    const { data } = await api.post<{ data: Resumen }>(
      `${base.value}/plataformas/conciliacion`,
      datos,
    );
    resumen.value = data.data;
    toast.exito(t("operacion.plataformas.conciliacion.importado"));
  } catch (e) {
    errorArchivo.value = erroresPorCampo(e).archivo ?? mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

watch([proveedor, periodo], () => void cargar());
onMounted(cargar);
</script>

<template>
  <div class="tu-card mt-5 p-5" data-prueba="conciliacion-plataformas">
    <h2 class="text-base font-semibold">
      {{ $t("operacion.plataformas.conciliacion.titulo") }}
    </h2>
    <p class="mt-0.5 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("operacion.plataformas.conciliacion.ayuda") }}
    </p>

    <div class="mt-4 flex flex-wrap items-end gap-3">
      <label>
        <span class="tu-label">{{
          $t("operacion.plataformas.conciliacion.plataforma")
        }}</span>
        <select v-model="proveedor" class="tu-input">
          <option value="totalpass">TotalPass</option>
          <option value="fitpass">Fitpass</option>
          <option value="wellhub">Wellhub</option>
        </select>
      </label>
      <label>
        <span class="tu-label">{{
          $t("operacion.plataformas.conciliacion.mes")
        }}</span>
        <input v-model="periodo" type="month" class="tu-input" />
      </label>
    </div>

    <p
      v-if="error"
      class="mt-3 text-sm"
      role="alert"
      style="color: var(--error)"
    >
      {{ error }}
    </p>

    <dl v-if="resumen" class="cp-cifras mt-4" data-prueba="conciliacion-cifras">
      <div>
        <dt>{{ $t("operacion.plataformas.conciliacion.visitas") }}</dt>
        <dd class="tabular-nums">{{ resumen.visitas }}</dd>
      </div>
      <div>
        <dt>{{ $t("operacion.plataformas.conciliacion.esperado") }}</dt>
        <dd class="tabular-nums">
          {{ dinero(resumen.esperado_minor, moneda) }}
        </dd>
      </div>
      <div>
        <dt>{{ $t("operacion.plataformas.conciliacion.recibido") }}</dt>
        <dd class="tabular-nums">
          {{
            resumen.importado
              ? dinero(resumen.recibido_minor, moneda)
              : $t("operacion.plataformas.conciliacion.sinImportar")
          }}
        </dd>
      </div>
      <div v-if="resumen.importado">
        <dt>{{ $t("operacion.plataformas.conciliacion.diferencia") }}</dt>
        <dd
          class="tabular-nums"
          :style="{
            color:
              resumen.diferencia_minor < 0 ? 'var(--error)' : 'var(--texto)',
          }"
        >
          {{ dinero(resumen.diferencia_minor, moneda) }}
        </dd>
      </div>
    </dl>
    <p
      v-if="resumen && resumen.visitas_sin_tarifa > 0"
      class="mt-2 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{
        $t("operacion.plataformas.conciliacion.sinTarifa", {
          n: resumen.visitas_sin_tarifa,
        })
      }}
    </p>

    <div v-if="puedeImportar" class="mt-4 grid gap-4 md:grid-cols-2">
      <form class="space-y-2" @submit.prevent="guardarTarifa">
        <label class="block">
          <span class="tu-label">{{
            $t("operacion.plataformas.conciliacion.tarifa")
          }}</span>
          <input
            v-model="tarifa"
            class="tu-input"
            inputmode="decimal"
            data-prueba="tarifa-visita"
          />
        </label>
        <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("operacion.plataformas.conciliacion.tarifaAyuda") }}
        </p>
        <button
          type="submit"
          class="tu-btn tu-btn-fantasma"
          :disabled="ocupado || tarifa.trim() === ''"
        >
          {{ $t("operacion.plataformas.conciliacion.guardarTarifa") }}
        </button>
      </form>
      <form class="space-y-2" @submit.prevent="importar">
        <label class="block">
          <span class="tu-label">{{
            $t("operacion.plataformas.conciliacion.archivo")
          }}</span>
          <input
            type="file"
            accept=".csv,text/csv"
            class="tu-input"
            data-prueba="archivo-pagos"
            @change="elegirArchivo"
          />
        </label>
        <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("operacion.plataformas.conciliacion.archivoAyuda") }}
        </p>
        <p v-if="errorArchivo" class="tu-error-campo" role="alert">
          {{ errorArchivo }}
        </p>
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="ocupado || !archivo"
          data-prueba="importar-pagos"
        >
          {{ $t("operacion.plataformas.conciliacion.importar") }}
        </button>
      </form>
    </div>

    <template v-if="resumen?.importado">
      <details v-if="resumen.visitas_sin_pago.length > 0" class="mt-4">
        <summary class="cursor-pointer text-sm font-medium">
          {{
            $t("operacion.plataformas.conciliacion.visitasSinPago", {
              n: resumen.visitas_sin_pago.length,
            })
          }}
        </summary>
        <ul class="mt-2 space-y-1 text-sm">
          <li v-for="v in resumen.visitas_sin_pago" :key="v.id">
            {{ v.fecha }} · {{ v.clase ?? "—" }}
            <span v-if="v.referencia" :style="{ color: 'var(--texto-suave)' }"
              >· {{ v.referencia }}</span
            >
          </li>
        </ul>
      </details>
      <details v-if="resumen.pagos_sin_visita.length > 0" class="mt-3">
        <summary class="cursor-pointer text-sm font-medium">
          {{
            $t("operacion.plataformas.conciliacion.pagosSinVisita", {
              n: resumen.pagos_sin_visita.length,
            })
          }}
        </summary>
        <ul class="mt-2 space-y-1 text-sm">
          <li
            v-for="(p, i) in resumen.pagos_sin_visita"
            :key="`${p.fecha}-${i}`"
          >
            {{ p.fecha }} · {{ dinero(p.monto_minor, moneda) }}
            <span v-if="p.referencia" :style="{ color: 'var(--texto-suave)' }"
              >· {{ p.referencia }}</span
            >
          </li>
        </ul>
      </details>
      <details v-if="resumen.montos_distintos.length > 0" class="mt-3">
        <summary class="cursor-pointer text-sm font-medium">
          {{
            $t("operacion.plataformas.conciliacion.montosDistintos", {
              n: resumen.montos_distintos.length,
            })
          }}
        </summary>
        <ul class="mt-2 space-y-1 text-sm">
          <li
            v-for="(p, i) in resumen.montos_distintos"
            :key="`${p.fecha}-${i}`"
          >
            {{ p.fecha }} · {{ dinero(p.monto_minor, moneda) }}
            <span :style="{ color: 'var(--texto-suave)' }">{{
              $t("operacion.plataformas.conciliacion.enLugarDe", {
                monto: dinero(p.esperado_minor ?? 0, moneda),
              })
            }}</span>
          </li>
        </ul>
      </details>
    </template>
  </div>
</template>

<style scoped>
.cp-cifras {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  gap: 0.75rem;
}
.cp-cifras dt {
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.cp-cifras dd {
  font-size: 1.25rem;
  font-weight: 600;
}
</style>
