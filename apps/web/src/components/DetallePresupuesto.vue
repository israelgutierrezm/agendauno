<script setup lang="ts">
import { useI18n } from "vue-i18n";

import { dinero } from "@/lib/formato";

/**
 * Un presupuesto como lo ve el cliente (en línea o en su cuenta): los conceptos, los
 * totales con sus impuestos y las notas del negocio.
 */
export interface LineaPresupuestoCliente {
  tipo: string;
  descripcion: string;
  cantidad: number;
  precio_unitario_minor: number;
  descuento_minor: number;
  subtotal_minor: number;
}
export interface PresupuestoCliente {
  id: string;
  folio: string;
  estado: "enviado" | "aceptado" | "rechazado" | "convertido";
  vencido: boolean;
  valido_hasta: string;
  revision: number;
  cliente: string | null;
  sucursal: { id: string; nombre: string } | null;
  moneda: string;
  impuestos: "incluidos" | "desglosados";
  tasa_impuesto: string;
  subtotal_minor: number;
  descuento_minor: number;
  impuesto_minor: number;
  total_minor: number;
  notas: string | null;
  respondido_en: string | null;
  respondido_por: "cliente" | "negocio" | null;
  lineas: LineaPresupuestoCliente[];
  servicios: { id: string; nombre: string }[];
  puede_responder: boolean;
  orden: { id: string; estado: "pendiente" | "pagada" | "cancelada" } | null;
  puede_pagar: boolean;
}

const props = defineProps<{
  presupuesto: PresupuestoCliente;
  pais?: string | null;
}>();
const { t } = useI18n();

function plata(minor: number): string {
  return dinero(minor, props.presupuesto.moneda, props.pais);
}
</script>

<template>
  <div class="dp">
    <ul class="dp-lineas">
      <li v-for="(l, i) in presupuesto.lineas" :key="i">
        <span class="min-w-0">
          {{ l.descripcion
          }}<template v-if="l.cantidad > 1"> × {{ l.cantidad }}</template>
          <small v-if="l.descuento_minor > 0" class="dp-suave block">
            {{ t("presupuestos.editor.descuentoLinea") }}: −{{
              plata(l.descuento_minor)
            }}
          </small>
        </span>
        <span class="tabular-nums">{{ plata(l.subtotal_minor) }}</span>
      </li>
    </ul>
    <dl class="dp-totales">
      <div>
        <dt>{{ t("presupuestos.editor.subtotal") }}</dt>
        <dd>{{ plata(presupuesto.subtotal_minor) }}</dd>
      </div>
      <div v-if="presupuesto.descuento_minor > 0">
        <dt>{{ t("presupuestos.editor.descuento") }}</dt>
        <dd>−{{ plata(presupuesto.descuento_minor) }}</dd>
      </div>
      <div v-if="presupuesto.impuestos === 'desglosados'">
        <dt>
          {{
            t("presupuestos.editor.impuestos", {
              tasa: presupuesto.tasa_impuesto,
            })
          }}
        </dt>
        <dd>{{ plata(presupuesto.impuesto_minor) }}</dd>
      </div>
      <div class="dp-total">
        <dt>{{ t("presupuestos.editor.total") }}</dt>
        <dd data-prueba="presupuesto-total">
          {{ plata(presupuesto.total_minor) }}
        </dd>
      </div>
    </dl>
    <p
      v-if="presupuesto.impuestos === 'incluidos'"
      class="dp-suave mt-1 text-xs text-right"
    >
      {{
        t("presupuestos.editor.incluidos", { tasa: presupuesto.tasa_impuesto })
      }}
    </p>
    <p v-if="presupuesto.notas" class="dp-notas">{{ presupuesto.notas }}</p>
  </div>
</template>

<style scoped>
.dp-lineas {
  display: grid;
  gap: 0.5rem;
  font-size: 0.925rem;
}
.dp-lineas li {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid var(--borde);
}
.dp-suave {
  color: var(--texto-suave);
}
.dp-totales {
  margin: 0.75rem 0 0 auto;
  max-width: 18rem;
  font-size: 0.925rem;
}
.dp-totales > div {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.15rem 0;
}
.dp-totales dd {
  font-variant-numeric: tabular-nums;
}
.dp-total {
  border-top: 1px solid var(--borde);
  margin-top: 0.3rem;
  padding-top: 0.4rem !important;
  font-weight: 600;
  font-size: 1rem;
}
.dp-notas {
  margin-top: 1rem;
  white-space: pre-line;
  font-size: 0.875rem;
  color: var(--texto-suave);
}
</style>
