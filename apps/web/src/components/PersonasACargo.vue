<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useParticipantes, type Participante } from "@/lib/participantes";
import { useToastStore } from "@/stores/toast";

/**
 * Las personas a cargo de la cuenta (familias, ADR 0123): la autorización que falta
 * (se lee el texto vigente del negocio y se acepta), pasar a su cuenta y retirar la
 * autorización. Solo desde la cuenta propia.
 */
const { t } = useI18n();
const participantes = useParticipantes();
const toast = useToastStore();

const leyendo = ref<Participante | null>(null);
const acepto = ref(false);
const guardando = ref(false);
const error = ref<string | null>(null);

const visibles = computed(() =>
  participantes.lista.filter((p) => p.estado !== "retirada"),
);

function abrir(p: Participante): void {
  leyendo.value = p;
  acepto.value = false;
  error.value = null;
}

async function autorizar(): Promise<void> {
  const p = leyendo.value;
  if (p === null || !acepto.value) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await participantes.autorizar(p.id);
    leyendo.value = null;
    toast.exito(t("familias.autorizada", { nombre: p.persona.nombre }));
  } catch (e) {
    // Si el negocio cambió el texto mientras lo leía, se muestra el nuevo.
    error.value = mensajeDeError(e);
    void participantes.cargar().catch(() => undefined);
    acepto.value = false;
  } finally {
    guardando.value = false;
  }
}

// A quién se le está retirando la autorización (no se pide dos veces).
const retirando = ref<string | null>(null);
async function retirar(p: Participante): Promise<void> {
  if (
    retirando.value !== null ||
    !(await confirmar(
      t("familias.confirmarRetiro", { nombre: p.persona.nombre }),
      { peligro: true },
    ))
  ) {
    return;
  }
  retirando.value = p.id;
  try {
    await participantes.retirar(p.id);
    toast.exito(t("familias.retirada", { nombre: p.persona.nombre }));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    retirando.value = null;
  }
}
</script>

<template>
  <section
    v-if="participantes.actual === null && visibles.length > 0"
    class="tu-card px-5 py-4"
    data-prueba="personas-a-cargo"
  >
    <h2 class="font-medium">{{ $t("familias.aCargo") }}</h2>
    <ul class="mt-2 divide-y divide-[var(--borde)]">
      <li
        v-for="p in visibles"
        :key="p.id"
        class="flex flex-wrap items-center justify-between gap-3 py-2.5"
      >
        <div class="min-w-0">
          <div class="text-sm font-medium truncate">{{ p.persona.nombre }}</div>
          <div
            class="mt-0.5 inline-flex items-center gap-1.5 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            <span
              class="inline-block size-1.5 rounded-full"
              :style="{
                background:
                  p.estado === 'activa' ? 'var(--exito)' : 'var(--aviso)',
              }"
              aria-hidden="true"
            />
            {{ $t(`familias.estados.${p.estado}`) }}
          </div>
        </div>
        <div class="flex items-center gap-3 text-sm">
          <template v-if="p.estado === 'pendiente'">
            <button
              type="button"
              class="tu-btn tu-btn-primario"
              @click="abrir(p)"
            >
              {{ $t("familias.autorizar") }}
            </button>
          </template>
          <template v-else>
            <button
              type="button"
              class="tu-enlace"
              @click="participantes.elegir(p.persona.id)"
            >
              {{ $t("familias.verSuCuenta") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-contorno tu-tono-peligro tu-btn-chico"
              :disabled="retirando !== null"
              data-prueba="retirar"
              @click="retirar(p)"
            >
              {{ $t("familias.retirar") }}
            </button>
          </template>
        </div>
      </li>
    </ul>

    <ModalDialogo
      :abierto="leyendo !== null"
      :titulo="$t('familias.tituloAutorizacion')"
      tam="md"
      :ocupado="guardando"
      @cerrar="leyendo = null"
    >
      <div v-if="leyendo" class="grid gap-3 text-sm">
        <p>
          {{
            $t("familias.introAutorizacion", { nombre: leyendo.persona.nombre })
          }}
        </p>
        <blockquote
          class="rounded-lg border px-4 py-3 whitespace-pre-line"
          :style="{ borderColor: 'var(--borde)' }"
          data-prueba="texto-autorizacion"
        >
          {{ participantes.texto }}
        </blockquote>
        <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("familias.version", { n: participantes.version }) }}
        </p>
        <label class="flex items-start gap-2">
          <input v-model="acepto" type="checkbox" class="mt-0.5" />
          <span>{{ $t("familias.acepto") }}</span>
        </label>
        <AvisoTono v-if="error" tono="peligro" role="alert">{{
          error
        }}</AvisoTono>
      </div>
      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="guardando"
            @click="leyendo = null"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="!acepto || guardando"
            @click="autorizar"
          >
            {{ $t("familias.autorizar") }}
          </button>
        </div>
      </template>
    </ModalDialogo>
  </section>
</template>
