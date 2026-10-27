<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { cuandoCorto } from "@/lib/miCuenta";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Mis invitados (ADR 0182): los pases de invitado que le quedan este mes y a quién
 * invitó a sus próximas clases. Cancelar una invitación antes de la clase libera el
 * lugar y le devuelve el pase. Sin pases ni invitados, no aparece.
 */
interface Pase {
  plan: string | null;
  por_mes: number;
  usados: number;
  quedan: number;
}
interface Invitado {
  id: string;
  nombre: string;
  estado: string;
  sesion: { id: string; oferta: string | null; inicia_en: string } | null;
}

const props = defineProps<{ base: string }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const pases = ref<Pase[]>([]);
const proximos = ref<Invitado[]>([]);
const cancelando = ref<string | null>(null);
const error = ref<string | null>(null);
const aviso = ref<string | null>(null);

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{
      data: { pases?: Pase[]; proximos?: Invitado[] };
    }>(`${props.base}/mi/invitados`);
    pases.value = data.data.pases ?? [];
    proximos.value = data.data.proximos ?? [];
  } catch {
    // Sin pases (o sin perfil de alumno en el negocio) no hay nada que mostrar.
    pases.value = [];
    proximos.value = [];
  }
}

function clase(i: Invitado): string {
  return i.sesion?.oferta ?? "—";
}
function cuando(i: Invitado): string {
  return i.sesion ? cuandoCorto(i.sesion.inicia_en, sesion.zonaHoraria) : "";
}

async function cancelar(i: Invitado): Promise<void> {
  if (
    !(await confirmar(
      t("portal.invitados.cancelarConfirmar", {
        nombre: i.nombre,
        clase: clase(i),
      }),
      { peligro: true, aceptar: t("portal.invitados.cancelar") },
    ))
  ) {
    return;
  }
  cancelando.value = i.id;
  error.value = null;
  aviso.value = null;
  try {
    await api.delete(`${props.base}/mi/invitados/${i.id}`);
    aviso.value = t("portal.invitados.cancelada", { nombre: i.nombre });
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cancelando.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <section
    v-if="pases.length > 0 || proximos.length > 0 || aviso"
    class="tu-card p-5"
    data-prueba="mis-invitados"
  >
    <h2 class="tu-titulo-seccion">{{ t("portal.invitados.titulo") }}</h2>
    <p
      v-for="(p, n) in pases"
      :key="`${p.plan ?? ''}-${n}`"
      class="mt-1 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
      data-prueba="pases-invitado"
    >
      {{
        t(
          "portal.invitados.quedan",
          {
            plan: p.plan ?? t("portal.invitados.tuPlan"),
            n: p.quedan,
            total: p.por_mes,
          },
          p.quedan === 1 ? 1 : 2,
        )
      }}
    </p>
    <AvisoTono v-if="aviso" tono="exito" class="mt-3" role="status">
      {{ aviso }}
    </AvisoTono>
    <AvisoTono v-if="error" tono="peligro" class="mt-3" role="alert">
      {{ error }}
    </AvisoTono>
    <ul v-if="proximos.length > 0" class="mt-3">
      <li
        v-for="i in proximos"
        :key="i.id"
        class="mi-fila"
        data-prueba="mi-invitado"
      >
        <span class="min-w-0">
          <span class="block font-medium">{{ i.nombre }}</span>
          <span
            class="block text-sm first-letter:uppercase"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ clase(i) }} · {{ cuando(i) }}</span
          >
        </span>
        <button
          type="button"
          class="tu-btn tu-btn-peligro tu-btn-chico shrink-0"
          :disabled="cancelando !== null"
          :aria-busy="cancelando === i.id || undefined"
          data-prueba="cancelar-invitado"
          @click="cancelar(i)"
        >
          {{ t("portal.invitados.cancelar") }}
        </button>
      </li>
    </ul>
    <p v-else class="mt-2 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ t("portal.invitados.sinProximos") }}
    </p>
  </section>
</template>

<style scoped>
.mi-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.65rem 0;
  border-top: 1px solid var(--borde);
}
.mi-fila:first-child {
  border-top: 0;
}
</style>
