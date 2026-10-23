<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import FormasDePago from "@/components/FormasDePago.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { dinero as dineroDelPais } from "@/lib/formato";
import { partesListas, type ParteCobro } from "@/lib/formasDePago";
import { useSucursales } from "@/lib/sucursalOperativa";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Registrar en caja el pago de lo que se debe (una cita, una compra): quién, qué,
 * cuánto, con qué se pagó y una referencia opcional. El mismo recorrido desde «Por
 * cobrar» y desde la ficha: cita → cliente → importe → registrar pago.
 */
export interface CuotaPorCobrar {
  id: string;
  concepto: string;
  nombre: string;
  monto_minor: number;
}
export interface OrdenPorCobrar {
  id: string;
  persona?: string | null;
  concepto: string | null;
  total_minor: number;
  moneda: string;
  // Las cuotas que lleva (B04): quien puede, las exenta por separado.
  cuotas?: CuotaPorCobrar[];
}

const props = defineProps<{ base: string; orden: OrdenPorCobrar | null }>();
const emit = defineEmits<{ cerrar: []; registrado: [aviso: string] }>();
const sucursales = useSucursales();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const puedeExentar = computed(() => sesion.puede("cuotas.exentar"));
// Lo que se cobra ahora (baja si se exenta una cuota).
const total = ref(0);
const cuotas = ref<CuotaPorCobrar[]>([]);
const exentando = ref<string | null>(null);
// Lo que se exentó con el diálogo abierto (para avisar al cerrar).
const exentadas = ref<string[]>([]);
const motivo = ref("");
// «tarjeta»: con la terminal del negocio (no por la pasarela en línea).
const METODOS = ["efectivo", "tarjeta", "transferencia", "ventanilla"] as const;
const metodo = ref<(typeof METODOS)[number]>("efectivo");
// Con varias formas de pago (ADR 0177): cuánto con cada una; suman el total.
const dividido = ref(false);
const partes = ref<ParteCobro[] | null>(null);
const partesOk = computed(
  () => !dividido.value || partesListas(partes.value, total.value),
);
// Precio por forma de pago (ADR 0191): todo en efectivo o todo por transferencia
// baja el porcentaje del negocio; con tarjeta o dividido, no. El servidor lo aplica.
const pctContado = computed(() => sesion.estudio?.descuento_contado_pct ?? 0);
const totalACobrar = computed(() =>
  pctContado.value > 0 &&
  !dividido.value &&
  (metodo.value === "efectivo" || metodo.value === "transferencia")
    ? total.value - Math.floor((total.value * pctContado.value) / 100)
    : total.value,
);
const referencia = ref("");
const procesando = ref(false);
const error = ref<string | null>(null);

// Cada orden empieza en efectivo y sin referencia.
watch(
  () => props.orden?.id,
  () => {
    metodo.value = "efectivo";
    dividido.value = false;
    referencia.value = "";
    error.value = null;
    total.value = props.orden?.total_minor ?? 0;
    cuotas.value = [...(props.orden?.cuotas ?? [])];
    exentando.value = null;
    motivo.value = "";
    exentadas.value = [];
  },
  { immediate: true },
);

// Exentar ya cambió la orden: al cerrar sin cobrar, se avisa como un cambio y quien
// abrió el cobro recarga (si no, mostraría el total anterior).
function cerrar(): void {
  if (exentadas.value.length > 0 && props.orden) {
    emit(
      "registrado",
      t("cobranza.pendientes.exentada", {
        cuota: exentadas.value.join("», «"),
        total: dinero(total.value, props.orden.moneda),
      }),
    );
    return;
  }
  emit("cerrar");
}

// Exentar una cuota (B04): sale de la orden y queda en la bitácora con el motivo.
async function exentar(c: CuotaPorCobrar): Promise<void> {
  const o = props.orden;
  if (!o || motivo.value.trim() === "") {
    return;
  }
  procesando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{
      data: { estado: string; total_minor: number };
    }>(`${props.base}/ordenes/${o.id}/lineas/${c.id}/exentar`, {
      motivo: motivo.value.trim(),
    });
    cuotas.value = cuotas.value.filter((x) => x.id !== c.id);
    total.value = data.data.total_minor;
    exentadas.value.push(c.nombre);
    exentando.value = null;
    motivo.value = "";
    // Si era lo único, ya no hay nada que cobrar.
    if (data.data.estado === "cancelada") {
      emit(
        "registrado",
        t("cobranza.pendientes.exentadaTodo", { cuota: c.nombre }),
      );
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    procesando.value = false;
  }
}

function dinero(minor: number, moneda: string): string {
  return dineroDelPais(minor, moneda, sesion.pais);
}

async function registrar(): Promise<void> {
  const o = props.orden;
  if (!o) {
    return;
  }
  procesando.value = true;
  error.value = null;
  try {
    // En la sucursal de la barra: su caja lo registra (ADR 0175).
    const sede = sucursales.fija.value;
    await api.post(`${props.base}/ordenes/${o.id}/liquidar`, {
      metodo: metodo.value,
      ...(dividido.value && partes.value !== null
        ? { partes: partes.value }
        : {}),
      referencia: referencia.value.trim() || null,
      ...(sede !== null ? { sucursal_id: sede } : {}),
    });
    emit(
      "registrado",
      t("cobranza.pendientes.registrado", {
        nombre: o.persona ?? "—",
        monto: dinero(totalACobrar.value, o.moneda),
      }),
    );
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    procesando.value = false;
  }
}
</script>

<template>
  <ModalDialogo
    :abierto="orden !== null"
    :titulo="$t('cobranza.pendientes.registrarTitulo')"
    tam="md"
    :ocupado="procesando || exentando !== null"
    @cerrar="cerrar"
  >
    <form
      v-if="orden"
      id="form-registrar-pago"
      class="space-y-4"
      data-prueba="confirmar-pago"
      @submit.prevent="registrar"
    >
      <p class="text-sm">
        <span v-if="orden.persona" class="font-medium"
          >{{ orden.persona }} ·
        </span>
        {{ orden.concepto ?? "—" }}
      </p>
      <p class="text-2xl font-semibold tabular-nums" data-prueba="total-cobro">
        {{ dinero(totalACobrar, orden.moneda) }}
      </p>
      <p
        v-if="totalACobrar !== total"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
        data-prueba="descuento-contado"
      >
        {{
          $t("cobranza.pendientes.descuentoContado", {
            pct: pctContado,
            antes: dinero(total, orden.moneda),
          })
        }}
      </p>
      <p
        v-else-if="pctContado > 0"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{
          $t("cobranza.pendientes.descuentoContadoAyuda", { pct: pctContado })
        }}
      </p>
      <ul
        v-if="cuotas.length > 0"
        class="space-y-2 text-sm"
        data-prueba="cuotas-orden"
      >
        <li v-for="c in cuotas" :key="c.id" class="space-y-2">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span>
              {{ c.nombre }} ·
              <span class="tabular-nums">{{
                dinero(c.monto_minor, orden.moneda)
              }}</span>
            </span>
            <button
              v-if="puedeExentar && exentando !== c.id"
              type="button"
              class="tu-btn tu-btn-secundario tu-btn-chico"
              data-prueba="exentar-cuota"
              @click="
                exentando = c.id;
                motivo = '';
              "
            >
              {{ $t("cobranza.pendientes.exentar") }}
            </button>
          </div>
          <div v-if="exentando === c.id" class="flex flex-wrap gap-2">
            <input
              v-model="motivo"
              class="tu-input min-w-0 flex-1"
              maxlength="300"
              :placeholder="$t('ventas.vender.motivoExencion')"
              :aria-label="$t('ventas.vender.motivoExencion')"
              data-prueba="motivo-cuota"
            />
            <!-- La acción principal del diálogo es «Registrar pago» (en el pie). -->
            <button
              type="button"
              class="tu-btn tu-btn-secundario"
              :disabled="procesando || motivo.trim() === ''"
              :aria-busy="procesando || undefined"
              data-prueba="confirmar-exencion"
              @click="exentar(c)"
            >
              {{ $t("cobranza.pendientes.exentar") }}
            </button>
          </div>
        </li>
      </ul>
      <div>
        <p class="tu-label">{{ $t("ventas.vender.metodo") }}</p>
        <div v-if="!dividido" class="tu-segmentado w-full" role="group">
          <button
            v-for="m in METODOS"
            :key="m"
            type="button"
            class="flex-1"
            :aria-pressed="metodo === m"
            @click="metodo = m"
          >
            {{ $t(`ventas.metodos.${m}`) }}
          </button>
        </div>
        <FormasDePago
          v-model:dividido="dividido"
          v-model:partes="partes"
          class="mt-2"
          :total="total"
          :metodos="METODOS"
          prefijo="ventas.metodos"
          :inicial="metodo"
          :moneda="orden.moneda"
          :pais="sesion.pais"
        />
      </div>
      <div>
        <label class="tu-label" for="cobro-ref">{{
          $t("cobranza.pendientes.referencia")
        }}</label>
        <input
          id="cobro-ref"
          v-model="referencia"
          class="tu-input"
          maxlength="255"
        />
      </div>
      <AvisoTono v-if="error" tono="peligro" role="alert">
        {{ error }}
      </AvisoTono>
    </form>
    <template #pie>
      <button
        type="button"
        class="tu-btn tu-btn-secundario"
        :disabled="procesando"
        @click="cerrar"
      >
        {{ $t("comun.cancelar") }}
      </button>
      <button
        type="submit"
        form="form-registrar-pago"
        class="tu-btn tu-btn-primario"
        :disabled="procesando || !partesOk"
        :aria-busy="procesando || undefined"
        data-prueba="boton-confirmar-pago"
      >
        {{ $t("cobranza.pendientes.registrar") }}
      </button>
    </template>
  </ModalDialogo>
</template>
