<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * El asistente sobre los datos del negocio (ADR 0196): se le pregunta en lenguaje
 * natural por ocupación, ingresos, demanda o retención, y contesta con los reportes del
 * negocio. Lo que propone hacer (generar la agenda, mandar una campaña) aparece como una
 * propuesta que se revisa y se aplica aquí; él no aplica nada.
 */
interface Mensaje {
  rol: "usuario" | "asistente";
  texto: string;
}
interface Propuesta {
  tipo: "generar_agenda" | "campana";
  resumen: string;
  datos: Record<string, string>;
  token: string;
  aplicada?: string;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}/asistente`);

const disponible = ref<boolean | null>(null);
const mensajes = ref<Mensaje[]>([]);
const propuestas = ref<Propuesta[]>([]);
const escrito = ref("");
const pensando = ref(false);
const error = ref<string | null>(null);
const lista = ref<HTMLElement | null>(null);

const SUGERENCIAS = computed(() => [
  t("asistenteIA.sugerencias.ingresos"),
  t("asistenteIA.sugerencias.ocupacion"),
  t("asistenteIA.sugerencias.retencion"),
]);

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: { disponible: boolean } }>(
      base.value,
    );
    disponible.value = data.data.disponible;
  } catch {
    disponible.value = false;
  }
});

async function preguntar(texto = escrito.value): Promise<void> {
  const pregunta = texto.trim();
  if (pregunta === "" || pensando.value) {
    return;
  }
  mensajes.value.push({ rol: "usuario", texto: pregunta });
  escrito.value = "";
  pensando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{
      data: { respuesta: string; propuestas: Propuesta[] };
    }>(base.value, { mensajes: mensajes.value.slice(-20) });
    mensajes.value.push({ rol: "asistente", texto: data.data.respuesta });
    propuestas.value.push(...data.data.propuestas);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    pensando.value = false;
    await nextTick();
    lista.value?.scrollTo?.({ top: lista.value.scrollHeight });
  }
}

async function aplicar(p: Propuesta): Promise<void> {
  if (
    !(await confirmar(
      t("asistenteIA.aplicarConfirmar", { resumen: p.resumen }),
      {
        aceptar: t("asistenteIA.aplicar"),
      },
    ))
  ) {
    return;
  }
  try {
    const { data } = await api.post<{
      data: { tipo: string; resultado: Record<string, number> };
    }>(`${base.value}/acciones`, { token: p.token });
    p.aplicada =
      data.data.tipo === "campana"
        ? t("asistenteIA.aplicadaCampana", {
            n: data.data.resultado.enviados ?? 0,
          })
        : t("asistenteIA.aplicadaAgenda", {
            n: data.data.resultado.creadas ?? 0,
          });
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

function detalle(p: Propuesta): string {
  return p.tipo === "campana"
    ? `${p.datos.asunto ?? ""} — ${p.datos.cuerpo ?? ""}`
    : t("asistenteIA.periodo", {
        desde: p.datos.desde ?? "",
        hasta: p.datos.hasta ?? "",
      });
}
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('asistenteIA.titulo')"
      :subtitulo="$t('asistenteIA.subtitulo')"
    />

    <AvisoTono
      v-if="disponible === false"
      tono="aviso"
      class="mt-6"
      data-prueba="asistente-no-disponible"
    >
      {{ $t("asistenteIA.noDisponible") }}
    </AvisoTono>

    <div v-else-if="disponible" class="mt-6 grid gap-4">
      <div
        ref="lista"
        class="tu-card p-4 space-y-3 max-h-[60vh] overflow-y-auto"
        data-prueba="asistente-mensajes"
      >
        <template v-if="mensajes.length === 0">
          <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("asistenteIA.vacio") }}
          </p>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="s in SUGERENCIAS"
              :key="s"
              type="button"
              class="tu-btn tu-btn-secundario tu-btn-chico"
              @click="preguntar(s)"
            >
              {{ s }}
            </button>
          </div>
        </template>
        <p
          v-for="(m, i) in mensajes"
          :key="i"
          class="whitespace-pre-line rounded-xl px-3 py-2 text-sm"
          :class="m.rol === 'usuario' ? 'ml-auto max-w-[85%]' : 'max-w-[95%]'"
          :style="
            m.rol === 'usuario'
              ? { background: 'var(--superficie-2)' }
              : { background: 'var(--fondo)' }
          "
          :data-rol="m.rol"
        >
          {{ m.texto }}
        </p>
        <p
          v-if="pensando"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("asistenteIA.pensando") }}
        </p>
      </div>

      <!-- Lo que propone hacer: se revisa y se aplica aquí. -->
      <div
        v-for="p in propuestas"
        :key="p.token"
        class="tu-card p-4"
        data-prueba="asistente-propuesta"
      >
        <p class="font-medium">{{ p.resumen }}</p>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ detalle(p) }}
        </p>
        <p v-if="p.aplicada" class="mt-2 text-sm" role="status">
          {{ p.aplicada }}
        </p>
        <div v-else class="mt-3 flex justify-end">
          <button
            type="button"
            class="tu-btn tu-btn-primario tu-btn-chico"
            data-prueba="aplicar-propuesta"
            @click="aplicar(p)"
          >
            {{ $t("asistenteIA.aplicar") }}
          </button>
        </div>
      </div>

      <form class="flex gap-2" @submit.prevent="preguntar()">
        <input
          v-model="escrito"
          class="tu-input grow"
          maxlength="2000"
          :placeholder="$t('asistenteIA.placeholder')"
          :aria-label="$t('asistenteIA.placeholder')"
          data-prueba="asistente-pregunta"
        />
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="pensando || escrito.trim() === ''"
        >
          {{ $t("asistenteIA.preguntar") }}
        </button>
      </form>
      <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("asistenteIA.aviso") }}
      </p>
      <AvisoTono v-if="error" tono="peligro" role="alert">{{
        error
      }}</AvisoTono>
    </div>
  </section>
</template>
