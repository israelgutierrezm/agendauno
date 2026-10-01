<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
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
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const solicitudes = ref<Solicitud[]>([]);
const cargando = ref(true);
const rechazando = ref<string | null>(null);
const respuesta = ref("");
const ocupado = ref<string | null>(null);

function fecha(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "short",
      }).format(new Date(iso.length === 10 ? `${iso}T12:00:00` : iso))
    : "—";
}

async function cargar(): Promise<void> {
  cargando.value = true;
  try {
    const { data } = await api.get<{ data: Solicitud[] }>(
      `${base.value}/solicitudes-privacidad`,
    );
    solicitudes.value = data.data;
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    cargando.value = false;
  }
}

async function atender(s: Solicitud): Promise<void> {
  if (
    !(await confirmar(t("privacidadNegocio.confirmarAtender"), {
      peligro: true,
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
  <section class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('privacidadNegocio.titulo')" />
    <p class="mt-2 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("privacidadNegocio.ayuda") }}
    </p>

    <div class="mt-5 tu-card p-5">
      <p
        v-if="cargando"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>
      <EstadoVacio
        v-else-if="solicitudes.length === 0"
        class="py-6"
        icono="documentos"
        compacto
        :titulo="$t('privacidadNegocio.vacio')"
      />
      <ul v-else>
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
                  {{
                    $t("privacidadNegocio.responderAntes", {
                      fecha: fecha(s.vence_en),
                    })
                  }}
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
            <div v-if="s.estado === 'pendiente'" class="flex gap-2">
              <button
                type="button"
                class="tu-btn tu-btn-primario text-sm"
                :disabled="ocupado === s.id"
                @click="atender(s)"
              >
                {{ $t("privacidadNegocio.atender") }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma text-sm"
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
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="ocupado === s.id || respuesta.trim() === ''"
            >
              {{ $t("privacidadNegocio.confirmarRechazo") }}
            </button>
          </form>
        </li>
      </ul>
    </div>
  </section>
</template>
