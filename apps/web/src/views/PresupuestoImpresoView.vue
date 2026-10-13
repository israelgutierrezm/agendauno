<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import { api, mensajeDeError } from "@/lib/api";
import { dinero } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Un presupuesto listo para imprimir o guardar como PDF (ADR 0127): la marca del
 * negocio, el folio, para quién, los conceptos, los totales con sus impuestos, la
 * vigencia y las notas. Sin menú ni botones al imprimir.
 */
interface Linea {
  descripcion: string;
  cantidad: number;
  precio_unitario_minor: number;
  descuento_minor: number;
  subtotal_minor: number;
}
interface Presupuesto {
  folio: string;
  contacto: {
    nombre: string | null;
    email: string | null;
    celular: string | null;
  };
  valido_hasta: string;
  creado_en: string | null;
  moneda: string;
  impuestos: "incluidos" | "desglosados";
  tasa_impuesto: string;
  subtotal_minor: number;
  descuento_minor: number;
  impuesto_minor: number;
  total_minor: number;
  notas: string | null;
  lineas: Linea[];
}

const route = useRoute();
const sesion = useSesionTenantStore();
const presupuesto = ref<Presupuesto | null>(null);
const logo = ref<string | null>(null);
const error = ref<string | null>(null);
const negocio = computed(() => sesion.estudio?.nombre ?? "");

function plata(minor: number): string {
  return dinero(minor, presupuesto.value?.moneda ?? "MXN", sesion.pais);
}

onMounted(async () => {
  try {
    const [p, marca] = await Promise.all([
      api.get<{ data: Presupuesto }>(
        `/api/v1/app/${sesion.slug}/presupuestos/${String(route.params.id)}`,
      ),
      api.get<{ data: { logo_url: string | null } }>(
        `/api/v1/app/${sesion.slug}/marca`,
      ),
    ]);
    presupuesto.value = p.data.data;
    logo.value = marca.data.data.logo_url;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
});

/** «11 de octubre de 2026» a partir de una fecha o un instante ISO. */
function fechaCompleta(iso: string): string {
  const [a, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(new Date(a ?? 1970, (m ?? 1) - 1, d ?? 1));
}

function imprimir(): void {
  window.print();
}
</script>

<template>
  <section class="pi">
    <div class="pi-barra">
      <RouterLink :to="{ name: 'presupuestos' }" class="tu-volver">
        {{ $t("presupuestos.impreso.volver") }}
      </RouterLink>
      <button
        v-if="presupuesto"
        type="button"
        class="tu-btn tu-btn-primario"
        @click="imprimir"
      >
        {{ $t("presupuestos.impreso.imprimir") }}
      </button>
    </div>

    <AvisoTono v-if="error" tono="peligro" class="mt-6" role="alert">
      {{ error }}
    </AvisoTono>

    <article
      v-if="presupuesto"
      class="pi-hoja"
      data-prueba="presupuesto-impreso"
    >
      <header class="pi-cabecera">
        <div class="flex items-center gap-3">
          <img v-if="logo" :src="logo" alt="" class="pi-logo" />
          <strong class="text-lg">{{ negocio }}</strong>
        </div>
        <div class="text-right text-sm">
          <p class="font-semibold text-base">
            {{ $t("presupuestos.impreso.folio", { folio: presupuesto.folio }) }}
          </p>
          <p v-if="presupuesto.creado_en">
            {{
              $t("presupuestos.impreso.fecha", {
                fecha: fechaCompleta(presupuesto.creado_en),
              })
            }}
          </p>
          <p>
            {{
              $t("presupuestos.impreso.vigencia", {
                fecha: fechaCompleta(presupuesto.valido_hasta),
              })
            }}
          </p>
        </div>
      </header>

      <div class="mt-6 text-sm">
        <p class="pi-etiqueta">{{ $t("presupuestos.impreso.para") }}</p>
        <p class="font-medium">{{ presupuesto.contacto.nombre }}</p>
        <p v-if="presupuesto.contacto.email">
          {{ presupuesto.contacto.email }}
        </p>
        <p v-if="presupuesto.contacto.celular">
          {{ presupuesto.contacto.celular }}
        </p>
      </div>

      <table class="pi-tabla mt-6">
        <thead>
          <tr>
            <th>{{ $t("presupuestos.impreso.concepto") }}</th>
            <th class="text-right">
              {{ $t("presupuestos.editor.cantidad") }}
            </th>
            <th class="text-right">{{ $t("presupuestos.editor.precio") }}</th>
            <th class="text-right">
              {{ $t("presupuestos.impreso.importe") }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in presupuesto.lineas" :key="i">
            <td>
              {{ l.descripcion }}
              <small v-if="l.descuento_minor > 0" class="block">
                {{ $t("presupuestos.editor.descuentoLinea") }}: −{{
                  plata(l.descuento_minor)
                }}
              </small>
            </td>
            <td class="text-right tabular-nums">{{ l.cantidad }}</td>
            <td class="text-right tabular-nums">
              {{ plata(l.precio_unitario_minor) }}
            </td>
            <td class="text-right tabular-nums">
              {{ plata(l.subtotal_minor) }}
            </td>
          </tr>
        </tbody>
      </table>

      <dl class="pi-totales">
        <div>
          <dt>{{ $t("presupuestos.editor.subtotal") }}</dt>
          <dd>{{ plata(presupuesto.subtotal_minor) }}</dd>
        </div>
        <div v-if="presupuesto.descuento_minor > 0">
          <dt>{{ $t("presupuestos.editor.descuento") }}</dt>
          <dd>−{{ plata(presupuesto.descuento_minor) }}</dd>
        </div>
        <div v-if="presupuesto.impuestos === 'desglosados'">
          <dt>
            {{
              $t("presupuestos.editor.impuestos", {
                tasa: presupuesto.tasa_impuesto,
              })
            }}
          </dt>
          <dd>{{ plata(presupuesto.impuesto_minor) }}</dd>
        </div>
        <div class="pi-total">
          <dt>{{ $t("presupuestos.editor.total") }}</dt>
          <dd>{{ plata(presupuesto.total_minor) }}</dd>
        </div>
      </dl>
      <p
        v-if="presupuesto.impuestos === 'incluidos'"
        class="mt-2 text-xs text-right"
      >
        {{
          $t("presupuestos.editor.incluidos", {
            tasa: presupuesto.tasa_impuesto,
          })
        }}
      </p>
      <p v-if="presupuesto.notas" class="pi-notas">{{ presupuesto.notas }}</p>
    </article>
  </section>
</template>

<style scoped>
.pi {
  max-width: 52rem;
  margin: 0 auto;
  padding: 1.5rem 1rem 3rem;
}
.pi-barra {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}
.pi-hoja {
  margin-top: 1.25rem;
  padding: 2rem;
  background: #fff;
  color: #111827;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
}
.pi-cabecera {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}
.pi-logo {
  height: 3rem;
  width: auto;
  object-fit: contain;
}
.pi-etiqueta {
  font-size: 0.75rem;
  color: #6b7280;
}
.pi-tabla {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}
.pi-tabla th {
  text-align: left;
  font-weight: 500;
  color: #6b7280;
  border-bottom: 1px solid #e5e7eb;
  padding: 0.5rem 0.25rem;
}
.pi-tabla td {
  border-bottom: 1px solid #f3f4f6;
  padding: 0.55rem 0.25rem;
  vertical-align: top;
}
.pi-totales {
  margin: 1rem 0 0 auto;
  max-width: 18rem;
  font-size: 0.9rem;
}
.pi-totales > div {
  display: flex;
  justify-content: space-between;
  padding: 0.2rem 0;
}
.pi-totales dd {
  font-variant-numeric: tabular-nums;
}
.pi-total {
  border-top: 1px solid #e5e7eb;
  margin-top: 0.3rem;
  padding-top: 0.4rem !important;
  font-weight: 600;
  font-size: 1rem;
}
.pi-notas {
  margin-top: 1.5rem;
  white-space: pre-line;
  font-size: 0.85rem;
  color: #374151;
}
@media print {
  .pi-barra {
    display: none;
  }
  .pi {
    padding: 0;
    max-width: none;
  }
  .pi-hoja {
    border: 0;
    padding: 0;
  }
}
</style>
