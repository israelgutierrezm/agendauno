<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import InterruptorSiNo from "@/components/InterruptorSiNo.vue";
import { puedeEntrar } from "@/lib/acceso";
import { api, mensajeDeError } from "@/lib/api";
import { dinero } from "@/lib/formato";
import { claveSegunModalidad } from "@/lib/menu";
import type { OcultosSitio, TipoSeccion } from "@/lib/sitioWeb";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * «Reservas y ventas» del sitio (ADR 0167): qué de tu negocio se muestra y qué puede
 * hacer quien visita. Servicios, precios, horarios, equipo y sucursales salen de sus
 * módulos (no hay un segundo catálogo): aquí se decide si su sección se ve y qué de
 * cada una (lo oculto sigue a la venta y se agenda igual), y cada cosa se edita en su
 * lugar, así el precio de la agenda es el de la página. Todo es del borrador.
 */
const props = defineProps<{
  secciones: { tipo: string; visible: boolean }[];
  ocultos: OcultosSitio;
}>();
const emit = defineEmits<{
  mostrar: [tipo: TipoSeccion, visible: boolean];
  ocultar: [grupo: keyof OcultosSitio, id: string, oculto: boolean];
}>();

interface ConPrecio {
  id?: string;
  nombre: string;
  precio_minor?: number | null;
  moneda?: string | null;
  duracion_minutos?: number | null;
}
interface Escaparate {
  servicios: ConPrecio[];
  productos: ConPrecio[];
  instructores: { id?: string; nombre: string }[];
  sucursales: { id: string; nombre: string; direccion?: string | null }[];
  horario_clases: { clase: string; dia: number; hora: string }[];
  captacion?: { activa: boolean } | null;
}
interface Grupo {
  tipo: TipoSeccion;
  titulo: string;
  // Con qué lista de ocultos se decide cada uno (sin ella, se ven todos).
  ocultable?: keyof OcultosSitio;
  items: { clave: string; nombre: string; detalle: string | null }[];
  ruta: string;
  editar: string;
}

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const datos = ref<Escaparate | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

function tm(clave: string): string {
  return t(claveSegunModalidad(clave, sesion.esCitas, te));
}
function precio(i: ConPrecio): string | null {
  const partes: string[] = [];
  if (typeof i.precio_minor === "number" && i.precio_minor > 0) {
    partes.push(dinero(i.precio_minor, i.moneda ?? sesion.moneda, sesion.pais));
  }
  if (sesion.esCitas && i.duracion_minutos) {
    partes.push(t("sitioWeb.reservas.minutos", { n: i.duracion_minutos }));
  }
  return partes.length > 0 ? partes.join(" · ") : null;
}

const visibles = computed(
  () => new Map(props.secciones.map((s) => [s.tipo, s.visible])),
);
const grupos = computed<Grupo[]>(() => {
  const d = datos.value;
  if (d === null) {
    return [];
  }
  const lista: Grupo[] = [
    {
      tipo: "servicios",
      titulo: tm("sitioWeb.reservas.servicios"),
      ocultable: "servicios",
      items: d.servicios.map((s) => ({
        clave: s.id ?? s.nombre,
        nombre: s.nombre,
        detalle: precio(s),
      })),
      ruta: "catalogo",
      editar: tm("sitioWeb.reservas.editarServicios"),
    },
    {
      tipo: "precios",
      titulo: tm("sitioWeb.reservas.precios"),
      ocultable: "productos",
      items: d.productos.map((p) => ({
        clave: p.id ?? p.nombre,
        nombre: p.nombre,
        detalle: precio(p),
      })),
      ruta: sesion.esCitas ? "ventas" : "planes",
      editar: tm("sitioWeb.reservas.editarPrecios"),
    },
    {
      tipo: "horario",
      titulo: t("sitioWeb.reservas.horario"),
      items: d.horario_clases.map((h, i) => ({
        clave: `${h.dia}-${h.hora}-${i}`,
        nombre: h.clase,
        detalle: `${t(`sitioWeb.reservas.dias.${h.dia}`)} ${h.hora.slice(0, 5)}`,
      })),
      ruta: "agenda",
      editar: t("sitioWeb.reservas.editarHorario"),
    },
    {
      tipo: "equipo",
      titulo: tm("sitioWeb.reservas.equipo"),
      ocultable: "equipo",
      items: d.instructores.map((p, i) => ({
        clave: p.id ?? `${p.nombre}-${i}`,
        nombre: p.nombre,
        detalle: null,
      })),
      ruta: "instructores",
      editar: tm("sitioWeb.reservas.editarEquipo"),
    },
    {
      tipo: "sucursales",
      titulo: t("sitioWeb.reservas.sucursales"),
      items: d.sucursales.map((s) => ({
        clave: s.id,
        nombre: s.nombre,
        detalle: s.direccion ?? null,
      })),
      ruta: "sedes",
      editar: t("sitioWeb.reservas.editarSucursales"),
    },
  ];
  // Solo las secciones de su tipo de negocio (el horario semanal es de clases).
  return lista.filter((g) => visibles.value.has(g.tipo));
});
const agendaVisible = computed(() => visibles.value.get("agenda") === true);

function mostrar(tipo: TipoSeccion, visible: boolean): void {
  emit("mostrar", tipo, visible);
}
function seVe(g: Grupo, clave: string): boolean {
  return (
    g.ocultable === undefined || !props.ocultos[g.ocultable].includes(clave)
  );
}
function alternar(g: Grupo, clave: string, visible: boolean): void {
  if (g.ocultable !== undefined) {
    emit("ocultar", g.ocultable, clave, !visible);
  }
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Escaparate }>(
      `/api/v1/app/${sesion.slug}/sitio/oferta`,
    );
    datos.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <div class="space-y-6" data-prueba="sitio-reservas">
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("sitioWeb.reservas.ayuda") }}
    </p>
    <p v-if="cargando" class="text-sm" role="status">
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="error"
      class="text-sm"
      role="alert"
      style="color: var(--error)"
    >
      {{ error }}
      <button type="button" class="tu-enlace ml-2" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </p>

    <template v-else-if="datos">
      <!-- Qué puede hacer quien visita -->
      <div class="tu-card p-5" data-prueba="sitio-visitante">
        <h2 class="text-lg font-medium">
          {{ $t("sitioWeb.reservas.visitante") }}
        </h2>
        <ul class="mt-3 space-y-3 text-sm">
          <li class="flex flex-wrap items-center justify-between gap-3">
            <span>{{ tm("sitioWeb.reservas.agendar") }}</span>
            <InterruptorSiNo
              :model-value="agendaVisible"
              :etiqueta="$t('sitioWeb.reservas.seMuestra')"
              prueba="mostrar-agenda"
              @update:model-value="mostrar('agenda', $event)"
            />
          </li>
          <li class="flex flex-wrap items-center justify-between gap-3">
            <span>{{ $t("sitioWeb.reservas.dejarDatos") }}</span>
            <span class="flex items-center gap-2">
              <span
                class="tu-estado"
                :style="{
                  '--tono': datos.captacion?.activa
                    ? 'var(--exito)'
                    : 'var(--texto-suave)',
                }"
                >{{
                  datos.captacion?.activa
                    ? $t("sitioWeb.reservas.activo")
                    : $t("sitioWeb.reservas.inactivo")
                }}</span
              >
              <RouterLink
                v-if="puedeEntrar('captacion-formularios', sesion)"
                :to="{ name: 'captacion-formularios' }"
                class="tu-enlace"
                >{{ $t("sitioWeb.reservas.irFormularios") }}</RouterLink
              >
            </span>
          </li>
        </ul>
      </div>

      <!-- Lo que se muestra de cada módulo -->
      <div
        v-for="g in grupos"
        :key="g.tipo"
        class="tu-card p-5"
        :data-prueba="`sitio-grupo-${g.tipo}`"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-medium">{{ g.titulo }}</h2>
          <InterruptorSiNo
            :model-value="visibles.get(g.tipo) === true"
            :etiqueta="$t('sitioWeb.reservas.seMuestra')"
            :prueba="`mostrar-${g.tipo}`"
            @update:model-value="mostrar(g.tipo, $event)"
          />
        </div>
        <ul v-if="g.items.length > 0" class="mt-3 text-sm sr-lista">
          <li
            v-for="i in g.items"
            :key="i.clave"
            class="flex flex-wrap items-center justify-between gap-2 py-2"
            :class="{ 'sr-oculto': !seVe(g, i.clave) }"
            :data-prueba="`item-${g.tipo}`"
          >
            <span class="min-w-0">
              <span class="font-medium">{{ i.nombre }}</span>
              <span
                v-if="i.detalle"
                class="block"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ i.detalle }}</span
              >
            </span>
            <InterruptorSiNo
              v-if="g.ocultable"
              :model-value="seVe(g, i.clave)"
              :etiqueta="$t('sitioWeb.reservas.itemSeVe')"
              :prueba="`ver-${g.tipo}-${i.clave}`"
              @update:model-value="alternar(g, i.clave, $event)"
            />
          </li>
        </ul>
        <p v-else class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("sitioWeb.reservas.vacio") }}
        </p>
        <RouterLink
          v-if="puedeEntrar(g.ruta, sesion)"
          :to="{ name: g.ruta }"
          class="tu-enlace mt-3 inline-block text-sm"
          :data-prueba="`editar-${g.tipo}`"
          >{{ g.editar }}</RouterLink
        >
      </div>
    </template>
  </div>
</template>

<style scoped>
.sr-lista {
  border-color: var(--borde);
}
.sr-lista > li + li {
  border-top: 1px solid var(--borde);
}
.sr-oculto {
  opacity: 0.6;
}
</style>
