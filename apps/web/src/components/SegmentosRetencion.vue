<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { puedeEntrar } from "@/lib/acceso";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Retención por segmentos (ADR 0159): cada grupo por una señal que el sistema
 * verifica (con plan y sin venir, paquete por vencer con saldo, vino y no compró, su
 * pago falló, ya debería regresar), con cuántos son y qué hacer con todos: escribirles
 * (una difusión, a quien acepta promociones) o crear su seguimiento (una tarea por
 * persona, sin repetir). «Ver quiénes» abre la lista con el porqué de cada uno.
 */
interface Segmento {
  clave: string;
  etiqueta: string;
  total: number;
  con_promociones: number;
}
interface Persona {
  id: string;
  nombre: string;
  detalle: string;
  recibe_promociones: boolean;
  seguimiento_abierto: boolean;
}
interface Meta {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = `/api/v1/app/${sesion.slug}/retencion/segmentos`;

const segmentos = ref<Segmento[]>([]);
const cargando = ref(true);
const errorCarga = ref(false);
const abierto = ref<string | null>(null);
const personas = ref<Persona[]>([]);
const meta = ref<Meta | null>(null);
const cargandoLista = ref(false);
const errorLista = ref(false);
const creando = ref<string | null>(null);

const puedeEscribir = puedeEntrar("comunicaciones", sesion);
const puedeSeguir =
  sesion.puede("tareas.gestionar") && sesion.puede("miembros.gestionar");

async function cargar(): Promise<void> {
  cargando.value = true;
  errorCarga.value = false;
  try {
    const { data } = await api.get<{ data: Segmento[] }>(base);
    segmentos.value = data.data;
  } catch {
    errorCarga.value = true;
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);

async function cargarLista(clave: string, pagina = 1): Promise<void> {
  cargandoLista.value = true;
  errorLista.value = false;
  try {
    const { data } = await api.get<{ data: Persona[]; meta: Meta }>(
      `${base}/${clave}`,
      { params: { page: pagina } },
    );
    personas.value = data.data;
    meta.value = data.meta;
  } catch {
    errorLista.value = true;
  } finally {
    cargandoLista.value = false;
  }
}

function alternar(clave: string): void {
  if (abierto.value === clave) {
    abierto.value = null;
    return;
  }
  abierto.value = clave;
  personas.value = [];
  meta.value = null;
  void cargarLista(clave);
}

async function crearSeguimiento(s: Segmento): Promise<void> {
  const si = await confirmar(
    t("retencion.segmentos.confirmarSeguimiento", {
      n: s.total,
      grupo: s.etiqueta,
    }),
    { aceptar: t("retencion.segmentos.crearSeguimiento") },
  );
  if (!si) return;
  creando.value = s.clave;
  try {
    const { data } = await api.post<{
      data: { creadas: number; omitidas: number };
    }>(`${base}/${s.clave}/seguimientos`);
    toast.exito(
      data.data.creadas > 0
        ? t("retencion.segmentos.creadas", data.data.creadas)
        : t("retencion.segmentos.ningunaNueva"),
    );
    if (abierto.value === s.clave) {
      await cargarLista(s.clave, meta.value?.page ?? 1);
    }
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    creando.value = null;
  }
}
</script>

<template>
  <div class="mt-5" data-prueba="segmentos-retencion">
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("retencion.segmentos.ayuda") }}
    </p>

    <p
      v-if="cargando"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("retencion.segmentos.cargando") }}
    </p>
    <AvisoTono
      v-else-if="errorCarga"
      tono="peligro"
      class="mt-4"
      role="alert"
      data-prueba="error-segmentos"
    >
      {{ $t("retencion.segmentos.error") }}
      <template #acciones>
        <button type="button" class="tu-btn tu-btn-secundario" @click="cargar">
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <EstadoVacio
      v-else-if="segmentos.every((s) => s.total === 0)"
      class="tu-card mt-4"
      icono="pulso"
      :titulo="$t('retencion.segmentos.vacio')"
    />
    <ul v-else class="mt-4 space-y-3">
      <li
        v-for="s in segmentos"
        :key="s.clave"
        class="tu-card p-4"
        :data-prueba="`segmento-${s.clave}`"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium">
              {{ s.etiqueta }}
              <span
                class="tabular-nums"
                :style="{ color: 'var(--texto-suave)' }"
                >· {{ $t("retencion.segmentos.personas", s.total) }}</span
              >
            </p>
            <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`retencion.segmentos.desc.${s.clave}`) }}
            </p>
          </div>
          <div v-if="s.total > 0" class="flex flex-wrap gap-2">
            <button
              type="button"
              class="tu-btn tu-btn-secundario"
              :aria-expanded="abierto === s.clave"
              @click="alternar(s.clave)"
            >
              {{
                abierto === s.clave
                  ? $t("retencion.segmentos.ocultar")
                  : $t("retencion.segmentos.ver")
              }}
            </button>
            <RouterLink
              v-if="puedeEscribir && s.con_promociones > 0"
              class="tu-btn tu-btn-secundario"
              :to="{ name: 'comunicaciones', query: { segmento: s.clave } }"
              :data-prueba="`escribir-${s.clave}`"
            >
              {{ $t("retencion.segmentos.escribirles") }}
            </RouterLink>
            <button
              v-if="puedeSeguir"
              type="button"
              class="tu-btn tu-btn-primario"
              :disabled="creando !== null"
              :data-prueba="`seguimiento-${s.clave}`"
              @click="crearSeguimiento(s)"
            >
              {{ $t("retencion.segmentos.crearSeguimiento") }}
            </button>
          </div>
        </div>
        <p
          v-if="s.total > 0 && s.con_promociones === 0 && puedeEscribir"
          class="mt-2 text-xs"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("retencion.segmentos.sinPromociones") }}
        </p>

        <!-- Quiénes son y por qué -->
        <div v-if="abierto === s.clave" class="mt-3">
          <p
            v-if="cargandoLista"
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("comun.cargando") }}
          </p>
          <p
            v-else-if="errorLista"
            class="text-sm"
            :style="{ color: 'var(--error)' }"
            role="alert"
          >
            {{ $t("retencion.segmentos.errorLista") }}
            <button
              type="button"
              class="tu-enlace"
              @click="cargarLista(s.clave, meta?.page ?? 1)"
            >
              {{ $t("comun.reintentar") }}
            </button>
          </p>
          <template v-else>
            <ul class="divide-y" :style="{ borderColor: 'var(--borde)' }">
              <li
                v-for="p in personas"
                :key="p.id"
                class="flex flex-wrap items-baseline justify-between gap-2 py-2"
              >
                <span class="min-w-0">
                  <RouterLink
                    class="tu-enlace font-medium"
                    :to="{ name: 'ficha-miembro', params: { id: p.id } }"
                    >{{ p.nombre }}</RouterLink
                  >
                  <span
                    class="block text-sm"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ p.detalle }}</span
                  >
                </span>
                <span class="flex gap-3 text-xs">
                  <span
                    v-if="p.seguimiento_abierto"
                    class="tu-estado"
                    :style="{ '--tono': 'var(--exito)' }"
                    >{{ $t("retencion.segmentos.yaTiene") }}</span
                  >
                  <span
                    v-if="!p.recibe_promociones"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ $t("retencion.segmentos.noPromociones") }}</span
                  >
                </span>
              </li>
            </ul>
            <PaginacionListado
              v-if="meta && meta.ultima_pagina > 1"
              :page="meta.page"
              :ultima-pagina="meta.ultima_pagina"
              :total="meta.total"
              :per-page="meta.per_page"
              @ir="cargarLista(s.clave, $event)"
            />
          </template>
        </div>
      </li>
    </ul>
  </div>
</template>
