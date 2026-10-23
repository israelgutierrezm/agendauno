<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Devolver algunos productos de una venta del mostrador (ADR 0178): cuántas piezas de
 * cada línea, el motivo y, si se cobró con varias formas, por cuál sale el dinero. Cada
 * pieza vale lo que se cobró por ella; la propina no se devuelve aquí. El dinero sale
 * de la caja abierta y las piezas vuelven al inventario.
 */
export interface LineaDevolvible {
  id: string;
  articulo: string | null;
  cantidad: number;
  cantidad_devuelta: number;
  subtotal_minor: number;
}

const props = defineProps<{
  base: string;
  venta: {
    id: string;
    moneda: string;
    lineas: LineaDevolvible[];
    partes?: { metodo: string; monto_minor: number }[];
  };
}>();
const emit = defineEmits<{ devuelto: []; cerrar: [] }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();

const piezas = ref<Record<string, number>>({});
const motivo = ref("");
const metodo = ref(props.venta.partes?.[0]?.metodo ?? "");
const enviando = ref(false);
const error = ref<string | null>(null);
// Una por intento: repetir (doble clic) no devuelve dos veces.
const llave = crypto.randomUUID();

const mixta = computed(() => (props.venta.partes?.length ?? 0) > 1);
function quedan(l: LineaDevolvible): number {
  return l.cantidad - l.cantidad_devuelta;
}
function porPieza(l: LineaDevolvible): number {
  return Math.trunc(l.subtotal_minor / Math.max(1, l.cantidad));
}
const total = computed(() =>
  props.venta.lineas.reduce(
    (s, l) => s + porPieza(l) * (piezas.value[l.id] ?? 0),
    0,
  ),
);
const listo = computed(
  () =>
    total.value > 0 &&
    motivo.value.trim() !== "" &&
    (!mixta.value || metodo.value !== ""),
);

function dinero(minor: number): string {
  return dineroDelPais(minor, props.venta.moneda, sesion.pais);
}

async function devolver(): Promise<void> {
  if (!listo.value || enviando.value) return;
  if (
    !(await confirmar(
      t("devolverVenta.confirmar", { monto: dinero(total.value) }),
      { aceptar: t("devolverVenta.devolver"), peligro: true },
    ))
  ) {
    return;
  }
  enviando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{
      devolucion?: {
        monto_minor: number;
        partes: { metodo: string; monto_minor: number }[];
      };
    }>(
      `${props.base}/pos/ventas/${props.venta.id}/devoluciones`,
      {
        lineas: props.venta.lineas
          .filter((l) => (piezas.value[l.id] ?? 0) > 0)
          .map((l) => ({ id: l.id, cantidad: piezas.value[l.id] })),
        motivo: motivo.value.trim(),
        ...(mixta.value ? { metodo: metodo.value } : {}),
      },
      { headers: { "Idempotency-Key": llave } },
    );
    // Lo que salió de verdad según el servidor (con una llave repetida, lo de ese intento).
    const salio = data.devolucion?.monto_minor ?? total.value;
    const partes = data.devolucion?.partes ?? [];
    toast.exito(
      partes.length > 1
        ? t("devolverVenta.okPartes", {
            monto: dinero(salio),
            partes: partes
              .map(
                (p) =>
                  `${t(`pos.metodos.${p.metodo}`)} ${dinero(p.monto_minor)}`,
              )
              .join(", "),
          })
        : t("devolverVenta.ok", { monto: dinero(salio) }),
    );
    emit("devuelto");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    enviando.value = false;
  }
}
</script>

<template>
  <form
    class="space-y-3 py-2"
    data-prueba="devolver-venta"
    @submit.prevent="devolver"
  >
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("devolverVenta.ayuda") }}
    </p>
    <ul class="space-y-2">
      <li
        v-for="l in venta.lineas"
        :key="l.id"
        class="flex flex-wrap items-center justify-between gap-3 text-sm"
      >
        <span class="min-w-0">
          {{ l.articulo ?? "—" }}
          <span
            class="block text-xs"
            :style="{ color: 'var(--texto-suave)' }"
            >{{
              $t("devolverVenta.quedan", {
                n: quedan(l),
                precio: dinero(porPieza(l)),
              })
            }}</span
          >
        </span>
        <input
          v-model.number="piezas[l.id]"
          type="number"
          min="0"
          :max="quedan(l)"
          class="tu-input w-24"
          :disabled="quedan(l) === 0"
          :aria-label="$t('devolverVenta.piezasDe', { articulo: l.articulo })"
          :data-prueba="`piezas-${l.id}`"
        />
      </li>
    </ul>
    <div v-if="mixta">
      <label class="tu-label" for="dv-forma">{{
        $t("devolverVenta.salePor")
      }}</label>
      <select
        id="dv-forma"
        v-model="metodo"
        class="tu-input"
        aria-describedby="dv-forma-ayuda"
        data-prueba="forma-devolucion"
      >
        <option v-for="p in venta.partes" :key="p.metodo" :value="p.metodo">
          {{
            $t("formasDePago.detalle", {
              forma: $t(`pos.metodos.${p.metodo}`),
              monto: dinero(p.monto_minor),
            })
          }}
        </option>
      </select>
      <p id="dv-forma-ayuda" class="tu-hint">
        {{ $t("devolverVenta.salePorAyuda") }}
      </p>
    </div>
    <div>
      <label class="tu-label" for="dv-motivo"
        >{{ $t("devolverVenta.motivo") }}
        <span class="tu-obligatorio" aria-hidden="true">*</span></label
      >
      <input
        id="dv-motivo"
        v-model="motivo"
        class="tu-input"
        maxlength="255"
        required
        :placeholder="$t('devolverVenta.motivoPh')"
        data-prueba="motivo-devolucion"
      />
    </div>
    <p v-if="error" class="tu-error-campo" role="alert">{{ error }}</p>
    <div class="flex flex-wrap items-center justify-end gap-2">
      <button
        type="button"
        class="tu-btn tu-btn-secundario"
        :disabled="enviando"
        @click="emit('cerrar')"
      >
        {{ $t("comun.cancelar") }}
      </button>
      <button
        type="submit"
        class="tu-btn tu-btn-solido tu-tono-peligro"
        :disabled="!listo || enviando"
        data-prueba="confirmar-devolucion"
      >
        {{ $t("devolverVenta.devolverMonto", { monto: dinero(total) }) }}
      </button>
    </div>
  </form>
</template>
