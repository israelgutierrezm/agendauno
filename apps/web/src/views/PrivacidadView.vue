<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Solicitudes de baja de datos (derechos ARCO) de los alumnos. El negocio las
 * atiende (se anonimizan sus datos) o las rechaza con motivo; la ley pide responder
 * en 20 días hábiles.
 */
interface Solicitud {
  id: string;
  persona: string | null;
  estado: "pendiente" | "atendida" | "rechazada";
  motivo: string | null;
  respuesta: string | null;
  solicitada_en: string | null;
  vence_en: string | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
// Atender una baja borra datos para siempre: el servidor pide `miembros.eliminar`
// (ADR 0207); sin él, la solicitud se ve pero no se resuelve aquí.
const puedeResolver = computed(() => sesion.puede("miembros.eliminar"));
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const solicitudes = ref<Solicitud[]>([]);
const cargando = ref(true);
const rechazando = ref<string | null>(null);
const respuesta = ref("");
const ocupado = ref<string | null>(null);

// Un instante, en la zona horaria del negocio (ADR 0099); una fecha AAAA-MM-DD, tal
// cual (sin que la zona la mueva de día).
function fecha(iso: string | null): string {
  if (!iso) {
    return "—";
  }
  const soloDia = iso.length === 10;
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    timeZone: soloDia ? "UTC" : sesion.zonaHoraria || undefined,
  }).format(new Date(soloDia ? `${iso}T12:00:00Z` : iso));
}

// Por páginas (las pendientes primero): antes se cortaban en 200 sin decirlo.
const meta = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  pendientes: number;
  vencidas: number;
  por_vencer: number;
} | null>(null);
const pagina = ref(1);
// Se buscan por nombre y se filtran por estado y por vencimiento (solo pendientes).
const buscar = ref("");
const estado = ref<"" | Solicitud["estado"]>("");
const vencimiento = ref<"" | "vencidas" | "por_vencer">("");
const ESTADOS = ["", "pendiente", "atendida", "rechazada"] as const;
const filtrando = computed(
  () =>
    buscar.value.trim() !== "" ||
    estado.value !== "" ||
    vencimiento.value !== "",
);
// El plazo, contra el día de hoy del negocio.
const hoy = computed(() => hoyEnNegocio(sesion.zonaHoraria));
const vencida = (s: Solicitud): boolean =>
  s.estado === "pendiente" && s.vence_en !== null && s.vence_en < hoy.value;

let turno = 0;
async function cargar(): Promise<void> {
  const mio = ++turno;
  cargando.value = true;
  const q = buscar.value.trim();
  try {
    const { data } = await api.get<{
      data: Solicitud[];
      meta: NonNullable<typeof meta.value>;
    }>(`${base.value}/solicitudes-privacidad`, {
      params: {
        page: pagina.value,
        per_page: 20,
        ...(q ? { q } : {}),
        ...(estado.value ? { estado: estado.value } : {}),
        ...(vencimiento.value ? { vencimiento: vencimiento.value } : {}),
      },
    });
    if (mio !== turno) return;
    solicitudes.value = data.data;
    meta.value = data.meta;
  } catch (e) {
    if (mio === turno) toast.error(mensajeDeError(e));
  } finally {
    if (mio === turno) cargando.value = false;
  }
}
// Un filtro nuevo vuelve a la primera página; la búsqueda espera a que se deje de
// escribir.
watch([estado, vencimiento], () => {
  pagina.value = 1;
  void cargar();
});
let espera: ReturnType<typeof setTimeout> | undefined;
watch(buscar, () => {
  clearTimeout(espera);
  espera = setTimeout(() => {
    pagina.value = 1;
    void cargar();
  }, 300);
});
// «Vencidas» es de las pendientes: elegirla deja el estado en pendientes.
function elegirVencimiento(v: "vencidas" | "por_vencer"): void {
  vencimiento.value = vencimiento.value === v ? "" : v;
  if (vencimiento.value !== "") estado.value = "";
}

async function atender(s: Solicitud): Promise<void> {
  if (
    !(await confirmar(t("privacidadNegocio.confirmarAtender"), {
      peligro: true,
      aceptar: t("privacidadNegocio.atender"),
    }))
  ) {
    return;
  }
  ocupado.value = s.id;
  try {
    await api.post(`${base.value}/solicitudes-privacidad/${s.id}/atender`);
    toast.exito(t("privacidadNegocio.atendida"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = null;
  }
}

async function rechazar(s: Solicitud): Promise<void> {
  ocupado.value = s.id;
  try {
    await api.post(`${base.value}/solicitudes-privacidad/${s.id}/rechazar`, {
      respuesta: respuesta.value.trim(),
    });
    rechazando.value = null;
    respuesta.value = "";
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('privacidadNegocio.titulo')"
      :subtitulo="$t('privacidadNegocio.ayuda')"
    />

    <div class="mt-5 tu-card">
      <div class="tu-filtros" data-prueba="filtros-privacidad">
        <label class="tu-buscar">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="buscar"
            type="search"
            class="tu-input"
            :placeholder="$t('privacidadNegocio.buscar')"
            :aria-label="$t('privacidadNegocio.buscar')"
            data-prueba="buscar-solicitud"
          />
        </label>
        <select
          v-model="estado"
          class="tu-input max-w-[12rem]"
          :aria-label="$t('privacidadNegocio.filtroEstado')"
          data-prueba="estado-solicitud"
        >
          <option v-for="e in ESTADOS" :key="e" :value="e">
            {{
              e
                ? $t(`privacidadNegocio.estados.${e}`)
                : $t("privacidadNegocio.todas")
            }}
          </option>
        </select>
        <div class="tu-segmentado" role="group">
          <button
            type="button"
            :aria-pressed="vencimiento === 'vencidas'"
            data-prueba="ver-vencidas"
            @click="elegirVencimiento('vencidas')"
          >
            {{ $t("privacidadNegocio.vencidas", { n: meta?.vencidas ?? 0 }) }}
          </button>
          <button
            type="button"
            :aria-pressed="vencimiento === 'por_vencer'"
            data-prueba="ver-por-vencer"
            @click="elegirVencimiento('por_vencer')"
          >
            {{
              $t("privacidadNegocio.porVencer", { n: meta?.por_vencer ?? 0 })
            }}
          </button>
        </div>
      </div>
      <div class="p-5">
        <p
          v-if="cargando && solicitudes.length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>
        <p
          v-else-if="solicitudes.length === 0 && filtrando"
          class="tu-sin-resultados"
        >
          {{ $t("privacidadNegocio.sinResultados") }}
        </p>
        <EstadoVacio
          v-else-if="solicitudes.length === 0"
          class="py-6"
          icono="documentos"
          compacto
          :titulo="$t('privacidadNegocio.vacio')"
        />
        <ul v-else :class="{ 'opacity-60': cargando }">
          <li
            v-for="s in solicitudes"
            :key="s.id"
            class="border-t py-3 first:border-t-0"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div class="min-w-0">
                <p class="font-medium">{{ s.persona ?? "—" }}</p>
                <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                  {{
                    $t("privacidadNegocio.solicitada", {
                      fecha: fecha(s.solicitada_en),
                    })
                  }}
                  <template v-if="s.estado === 'pendiente'">
                    ·
                    <span
                      v-if="vencida(s)"
                      class="font-medium"
                      :style="{ color: 'var(--error)' }"
                      data-prueba="vencida"
                      >{{
                        $t("privacidadNegocio.vencio", {
                          fecha: fecha(s.vence_en),
                        })
                      }}</span
                    >
                    <template v-else>{{
                      $t("privacidadNegocio.responderAntes", {
                        fecha: fecha(s.vence_en),
                      })
                    }}</template>
                  </template>
                  <template v-if="s.motivo"> · “{{ s.motivo }}”</template>
                </p>
                <p
                  v-if="s.estado !== 'pendiente'"
                  class="text-xs"
                  :style="{
                    color:
                      s.estado === 'atendida'
                        ? 'var(--exito)'
                        : 'var(--texto-suave)',
                  }"
                >
                  {{ $t(`privacidadNegocio.estados.${s.estado}`) }}
                  <template v-if="s.respuesta"> · {{ s.respuesta }}</template>
                </p>
              </div>
              <div
                v-if="s.estado === 'pendiente' && puedeResolver"
                class="flex gap-2"
              >
                <button
                  type="button"
                  class="tu-btn tu-btn-peligro"
                  :disabled="ocupado === s.id"
                  @click="atender(s)"
                >
                  {{ $t("privacidadNegocio.atender") }}
                </button>
                <button
                  type="button"
                  class="tu-btn tu-btn-secundario"
                  @click="rechazando = rechazando === s.id ? null : s.id"
                >
                  {{ $t("privacidadNegocio.rechazar") }}
                </button>
              </div>
            </div>
            <form
              v-if="rechazando === s.id"
              class="mt-3 flex flex-wrap items-end gap-3"
              @submit.prevent="rechazar(s)"
            >
              <div class="min-w-[16rem] flex-1">
                <label class="tu-label" :for="`rz-${s.id}`">{{
                  $t("privacidadNegocio.motivoRechazo")
                }}</label>
                <input
                  :id="`rz-${s.id}`"
                  v-model="respuesta"
                  class="tu-input"
                  maxlength="500"
                  required
                />
              </div>
              <button
                type="submit"
                class="tu-btn tu-btn-secundario"
                :disabled="ocupado === s.id || respuesta.trim() === ''"
              >
                {{ $t("privacidadNegocio.confirmarRechazo") }}
              </button>
            </form>
          </li>
        </ul>
        <PaginacionListado
          v-if="meta && meta.ultima_pagina > 1"
          :page="meta.page"
          :ultima-pagina="meta.ultima_pagina"
          :total="meta.total"
          :per-page="meta.per_page"
          @ir="
            pagina = $event;
            cargar();
          "
        />
      </div>
    </div>
  </section>
</template>
