<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import CalificacionEstrellas from "@/components/CalificacionEstrellas.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import { cuandoCorto } from "@/lib/miCuenta";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Historial de clases y citas del portal (GET /mi/historial): lo que tomó, faltó o
 * canceló y si cambió de horario. La reseña va junto a cada clase realizada: se ve
 * la que dejó o se califica ahí mismo mientras esté a tiempo. Desde cada una se
 * vuelve a reservar lo mismo. Con filtro de fechas y paginado.
 */
export interface ItemHistorial {
  id: string;
  tipo: "clase" | "cita" | null;
  oferta: string | null;
  oferta_id: string | null;
  sucursal: string | null;
  sucursal_id: string | null;
  instructor: string | null;
  instructor_id: string | null;
  inicia_en: string | null;
  zona_horaria: string | null;
  estado: string;
  // Llegó tarde (cuenta como asistencia, ADR 0101).
  retardo?: boolean;
  cancelada_por: string | null;
  reprogramada: boolean;
  resena: { calificacion: number; comentario: string | null } | null;
  calificable: boolean;
}
interface Meta {
  page: number;
  ultima_pagina: number;
  total: number;
  per_page: number;
}

const POR_PAGINA = 10;

const emit = defineEmits<{ reservarDeNuevo: [item: ItemHistorial] }>();
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const items = ref<ItemHistorial[]>([]);
const meta = ref<Meta | null>(null);
const pagina = ref(1);
const desde = ref("");
const hasta = ref("");
const cargando = ref(true);
const error = ref<string | null>(null);
// La que se está calificando (una a la vez) y lo que lleva escrito.
const calificando = ref<string | null>(null);
const estrellas = ref(0);
const comentario = ref("");
const enviando = ref(false);

const filtrando = computed(() => desde.value !== "" || hasta.value !== "");

let pedido = 0;
async function cargar(): Promise<void> {
  const mio = ++pedido;
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: ItemHistorial[]; meta?: Meta }>(
      `${base.value}/mi/historial`,
      {
        params: {
          page: pagina.value,
          per_page: POR_PAGINA,
          ...(desde.value !== "" ? { desde: desde.value } : {}),
          ...(hasta.value !== "" ? { hasta: hasta.value } : {}),
        },
      },
    );
    if (mio !== pedido) {
      return; // Ya se pidió otra página o filtro.
    }
    items.value = data.data;
    meta.value = data.meta ?? null;
    pagina.value = data.meta?.page ?? 1;
  } catch (e) {
    if (mio === pedido) {
      items.value = [];
      error.value = mensajeDeError(e);
    }
  } finally {
    if (mio === pedido) {
      cargando.value = false;
    }
  }
}

function ir(n: number): void {
  pagina.value = n;
  void cargar();
}
function quitarFiltro(): void {
  desde.value = "";
  hasta.value = "";
}
// Cambiar las fechas vuelve a la primera página.
watch([desde, hasta], () => {
  pagina.value = 1;
  void cargar();
});

function estado(i: ItemHistorial): { texto: string; tono: string } {
  switch (i.estado) {
    case "asistio":
      return {
        texto: t(
          i.retardo
            ? "portal.historial.estados.llegasteTarde"
            : "portal.historial.estados.asistio",
        ),
        tono: "var(--exito)",
      };
    case "no_asistio":
      return {
        texto: t("portal.historial.estados.no_asistio"),
        tono: "var(--error)",
      };
    case "cancelada":
      return i.cancelada_por === "cliente"
        ? {
            texto: t("portal.historial.estados.cancelaste"),
            tono: "var(--texto-suave)",
          }
        : {
            texto: t("portal.historial.estados.cancelada_negocio"),
            tono: "var(--aviso)",
          };
    case "sin_pagar":
      return {
        texto: t("portal.historial.estados.sin_pagar"),
        tono: "var(--aviso)",
      };
    case "expirada":
    case "sin_lugar":
      return {
        texto: t(`portal.historial.estados.${i.estado}`),
        tono: "var(--texto-suave)",
      };
    default:
      return {
        texto: t("portal.historial.estados.tomada"),
        tono: "var(--texto-suave)",
      };
  }
}

function abrirCalificar(i: ItemHistorial): void {
  calificando.value = calificando.value === i.id ? null : i.id;
  estrellas.value = 0;
  comentario.value = "";
}
async function enviarResena(i: ItemHistorial): Promise<void> {
  if (estrellas.value < 1) {
    return;
  }
  enviando.value = true;
  try {
    await api.post(`${base.value}/mi/reservas/${i.id}/resena`, {
      calificacion: estrellas.value,
      comentario: comentario.value.trim() || null,
    });
    toast.exito(t("resenas.gracias"));
    // Se queda en la misma fila, ya con su reseña.
    i.resena = {
      calificacion: estrellas.value,
      comentario: comentario.value.trim() || null,
    };
    i.calificable = false;
    calificando.value = null;
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    enviando.value = false;
  }
}

defineExpose({ cargar });
onMounted(cargar);
</script>

<template>
  <div class="tu-card overflow-hidden" data-prueba="historial">
    <div class="tu-filtros p-5 pb-3">
      <label class="flex items-center gap-2 text-sm">
        <span :style="{ color: 'var(--texto-suave)' }">{{
          $t("portal.historial.desde")
        }}</span>
        <input
          v-model="desde"
          class="tu-input"
          type="date"
          :max="hasta || undefined"
          data-prueba="historial-desde"
        />
      </label>
      <label class="flex items-center gap-2 text-sm">
        <span :style="{ color: 'var(--texto-suave)' }">{{
          $t("portal.historial.hasta")
        }}</span>
        <input
          v-model="hasta"
          class="tu-input"
          type="date"
          :min="desde || undefined"
          data-prueba="historial-hasta"
        />
      </label>
      <button
        v-if="filtrando"
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        @click="quitarFiltro"
      >
        {{ $t("portal.historial.quitarFiltro") }}
      </button>
    </div>

    <p v-if="error" class="px-5 pb-5 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-else-if="cargando && items.length === 0"
      class="px-5 pb-5 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="items.length === 0"
      class="px-5 pb-5 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{
        filtrando
          ? $t("portal.historial.sinEnFechas")
          : $t("portal.historial.vacio")
      }}
    </p>
    <ul v-else>
      <li
        v-for="i in items"
        :key="i.id"
        class="hr-fila"
        data-prueba="historial-fila"
      >
        <div class="hr-info">
          <p class="truncate font-medium">{{ i.oferta ?? "—" }}</p>
          <p class="hr-detalle first-letter:uppercase">
            {{ cuandoCorto(i.inicia_en, i.zona_horaria)
            }}{{ i.sucursal ? ` · ${i.sucursal}` : ""
            }}{{ i.instructor ? ` · ${i.instructor}` : "" }}
          </p>
          <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span class="tu-pildora" :style="{ '--tono': estado(i).tono }">{{
              estado(i).texto
            }}</span>
            <span
              v-if="i.reprogramada"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("portal.historial.reprogramada") }}
            </span>
          </p>
          <!-- Su reseña, junto a la clase -->
          <p
            v-if="i.resena"
            class="mt-2 flex flex-wrap items-center gap-2 text-sm"
            data-prueba="historial-resena"
          >
            <CalificacionEstrellas :valor="i.resena.calificacion" />
            <span
              v-if="i.resena.comentario"
              class="min-w-0 truncate"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ i.resena.comentario }}</span
            >
          </p>
        </div>
        <div class="hr-acciones">
          <button
            v-if="i.calificable"
            type="button"
            class="tu-btn tu-btn-fantasma text-sm"
            :aria-expanded="calificando === i.id"
            data-prueba="historial-calificar"
            @click="abrirCalificar(i)"
          >
            {{ $t("portal.historial.calificar") }}
          </button>
          <button
            v-if="i.oferta_id"
            type="button"
            class="tu-btn tu-btn-fantasma text-sm"
            data-prueba="historial-de-nuevo"
            @click="emit('reservarDeNuevo', i)"
          >
            {{
              i.tipo === "cita"
                ? $t("portal.historial.agendarDeNuevo")
                : $t("portal.historial.reservarDeNuevo")
            }}
          </button>
        </div>

        <!-- Calificar ahí mismo -->
        <form
          v-if="calificando === i.id"
          class="hr-calificar"
          @submit.prevent="enviarResena(i)"
        >
          <div
            class="flex gap-1"
            role="radiogroup"
            :aria-label="$t('resenas.calificacion')"
          >
            <button
              v-for="n in 5"
              :key="n"
              type="button"
              class="text-2xl leading-none"
              role="radio"
              :aria-checked="estrellas === n"
              :aria-label="$t('resenas.estrellas', { n })"
              :style="{
                color: n <= estrellas ? 'var(--aviso)' : 'var(--borde)',
              }"
              @click="estrellas = n"
            >
              ★
            </button>
          </div>
          <div class="mt-2 flex flex-wrap gap-2">
            <input
              v-model="comentario"
              class="tu-input min-w-0 flex-1"
              maxlength="1000"
              :placeholder="$t('resenas.comentarioPh')"
            />
            <button
              type="submit"
              class="tu-btn tu-btn-primario text-sm"
              :disabled="enviando || estrellas < 1"
            >
              {{ $t("resenas.enviar") }}
            </button>
          </div>
        </form>
      </li>
    </ul>
    <PaginacionListado
      v-if="meta && meta.ultima_pagina > 1"
      :page="meta.page"
      :ultima-pagina="meta.ultima_pagina"
      :total="meta.total"
      :per-page="meta.per_page"
      @ir="ir"
    />
  </div>
</template>

<style scoped>
/* Una fila por clase o cita: lo que fue a la izquierda; calificar y volver a
   reservar a la derecha (debajo, en el teléfono). */
.hr-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.5rem 1rem;
  padding: 0.9rem 1.25rem;
  border-top: 1px solid var(--borde);
}
.hr-info {
  flex: 1 1 16rem;
  min-width: 0;
}
.hr-detalle {
  font-size: 0.875rem;
  color: var(--texto-suave);
}
.hr-acciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-left: auto;
}
.hr-calificar {
  flex-basis: 100%;
  min-width: 0;
}
</style>
