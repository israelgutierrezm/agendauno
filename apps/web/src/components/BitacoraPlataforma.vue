<script setup lang="ts">
import axios from "axios";
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { mensajeDeError } from "@/lib/api";
import { PRODUCTOS, type Producto } from "@/lib/producto";

/**
 * Superadmin, «Bitácora» (ADR 0132): lo que cambió cada operador, de lo más
 * reciente a lo más viejo, con su negocio, sus datos (sin secretos), su IP y su
 * resultado. Se filtra por operador, por negocio o por acción.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

interface Registro {
  id: string;
  fecha: string;
  accion: string;
  metodo: string;
  ruta: string;
  estado: number;
  ip: string | null;
  operador: { nombre: string; email: string } | null;
  negocio: { slug: string; nombre: string; producto: Producto } | null;
  datos: Record<string, unknown> | null;
}
interface Operador {
  id: string;
  nombre: string;
}

const { t, te } = useI18n();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});
const encabezados = () => ({
  headers: { Authorization: `Bearer ${props.token}` },
});

const registros = ref<Registro[]>([]);
const operadores = ref<Operador[]>([]);
const filtros = ref({ operador: "", estudio: "", accion: "" });
const pagina = ref(1);
const ultimaPagina = ref(1);
const total = ref(0);
const cargando = ref(true);
const error = ref<string | null>(null);
const abierto = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await cliente.get<{
      data: Registro[];
      meta: { pagina: number; ultima_pagina: number; total: number };
    }>("/api/v1/plataforma/bitacora", {
      ...encabezados(),
      params: {
        ...Object.fromEntries(
          Object.entries(filtros.value).filter(([, v]) => v.trim() !== ""),
        ),
        pagina: pagina.value,
      },
    });
    registros.value = data.data;
    ultimaPagina.value = data.meta.ultima_pagina;
    total.value = data.meta.total;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function cargarOperadores(): Promise<void> {
  try {
    const { data } = await cliente.get<{ data: Operador[] }>(
      "/api/v1/plataforma/administradores",
      encabezados(),
    );
    operadores.value = data.data;
  } catch {
    operadores.value = [];
  }
}

function buscar(): void {
  pagina.value = 1;
  void cargar();
}
function irA(p: number): void {
  pagina.value = p;
  void cargar();
}

/** El nombre de la acción si lo conocemos; si no, la clave tal cual. */
function accion(r: Registro): string {
  const clave = `plataformaAdmin.bitacora.acciones.${r.accion.replaceAll(".", "_")}`;
  return te(clave) ? t(clave) : r.accion;
}

function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso));
}

onMounted(() => {
  void cargar();
  void cargarOperadores();
});
</script>

<template>
  <section class="tu-card p-5" data-prueba="bitacora-plataforma">
    <h2 class="font-medium text-lg">
      {{ t("plataformaAdmin.bitacora.titulo") }}
      <span
        v-if="!cargando"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
        >· {{ total }}</span
      >
    </h2>
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ t("plataformaAdmin.bitacora.ayuda") }}
    </p>

    <form
      class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end"
      @submit.prevent="buscar"
    >
      <div>
        <label class="tu-label" for="bt-operador">{{
          t("plataformaAdmin.bitacora.operador")
        }}</label>
        <select id="bt-operador" v-model="filtros.operador" class="tu-input">
          <option value="">{{ t("plataformaAdmin.bitacora.todos") }}</option>
          <option v-for="o in operadores" :key="o.id" :value="o.id">
            {{ o.nombre }}
          </option>
        </select>
      </div>
      <div>
        <label class="tu-label" for="bt-negocio">{{
          t("plataformaAdmin.bitacora.negocio")
        }}</label>
        <input
          id="bt-negocio"
          v-model="filtros.estudio"
          class="tu-input"
          :placeholder="t('plataformaAdmin.bitacora.negocioPh')"
        />
      </div>
      <div>
        <label class="tu-label" for="bt-accion">{{
          t("plataformaAdmin.bitacora.accion")
        }}</label>
        <input
          id="bt-accion"
          v-model="filtros.accion"
          class="tu-input"
          :placeholder="t('plataformaAdmin.bitacora.accionPh')"
        />
      </div>
      <button type="submit" class="tu-btn tu-btn-primario">
        {{ t("plataformaAdmin.bitacora.buscar") }}
      </button>
    </form>

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-peligro tu-btn-chico"
          @click="cargar"
        >
          {{ t("plataformaAdmin.bitacora.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <p v-else-if="cargando" class="mt-4 text-sm" role="status">
      {{ t("plataformaAdmin.bitacora.cargando") }}
    </p>
    <p
      v-else-if="registros.length === 0"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("plataformaAdmin.bitacora.vacio") }}
    </p>
    <ul v-else class="mt-4">
      <li
        v-for="r in registros"
        :key="r.id"
        class="bt-fila"
        data-prueba="registro-bitacora"
      >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <p class="font-medium">{{ accion(r) }}</p>
          <span
            class="tu-estado text-sm"
            :style="{
              '--tono': r.estado < 400 ? 'var(--exito)' : 'var(--error)',
            }"
            >{{
              r.estado < 400
                ? t("plataformaAdmin.bitacora.hecho")
                : t("plataformaAdmin.bitacora.rechazado", { estado: r.estado })
            }}</span
          >
        </div>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ fecha(r.fecha) }} ·
          {{ r.operador?.nombre ?? t("plataformaAdmin.bitacora.sinOperador") }}
          <template v-if="r.negocio">
            · {{ r.negocio.nombre }} ({{
              PRODUCTOS[r.negocio.producto].nombre
            }})
          </template>
          <template v-if="r.ip"> · {{ r.ip }}</template>
        </p>
        <button
          v-if="r.datos && Object.keys(r.datos).length > 0"
          type="button"
          class="tu-enlace text-xs"
          :aria-expanded="abierto === r.id"
          @click="abierto = abierto === r.id ? null : r.id"
        >
          {{
            abierto === r.id
              ? t("plataformaAdmin.bitacora.ocultarDatos")
              : t("plataformaAdmin.bitacora.verDatos")
          }}
        </button>
        <pre v-if="abierto === r.id" class="bt-datos mt-1 text-xs">{{
          JSON.stringify(r.datos, null, 2)
        }}</pre>
      </li>
    </ul>

    <div
      v-if="ultimaPagina > 1"
      class="mt-4 flex items-center justify-between gap-3 text-sm"
    >
      <button
        type="button"
        class="tu-enlace"
        :disabled="pagina <= 1 || cargando"
        @click="irA(pagina - 1)"
      >
        {{ t("plataformaAdmin.bitacora.anterior") }}
      </button>
      <span :style="{ color: 'var(--texto-suave)' }">{{
        t("plataformaAdmin.bitacora.pagina", { pagina, de: ultimaPagina })
      }}</span>
      <button
        type="button"
        class="tu-enlace"
        :disabled="pagina >= ultimaPagina || cargando"
        @click="irA(pagina + 1)"
      >
        {{ t("plataformaAdmin.bitacora.siguiente") }}
      </button>
    </div>
  </section>
</template>

<style scoped>
.bt-fila {
  border-top: 1px solid var(--borde);
  padding: 0.75rem 0;
}
.bt-datos {
  max-height: 16rem;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-word;
  background: var(--superficie-2, var(--superficie));
  border: 1px solid var(--borde);
  border-radius: 0.5rem;
  padding: 0.5rem 0.75rem;
}
</style>
