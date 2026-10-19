<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRoute } from "vue-router";

import FormularioCaptacion from "@/components/FormularioCaptacion.vue";
import HechoCon from "@/components/HechoCon.vue";
import type { FormularioPublico } from "@/lib/captacion";

/**
 * Un formulario de captación por su enlace o QR (`/f/{negocio}/{formulario}`), o
 * embebido en el sitio del negocio con `?embed=1` (sin encabezado ni pie): avisa su
 * altura a la página que lo contiene para que no tenga barras de desplazamiento.
 */
const props = defineProps<{ slug: string; formulario: string }>();

const route = useRoute();
const embebido = computed(() => route.query.embed === "1");
const negocio = ref<string | null>(null);
const caja = ref<HTMLElement | null>(null);
let observador: ResizeObserver | null = null;

function avisarAltura(): void {
  if (!embebido.value || window.parent === window || caja.value === null) {
    return;
  }
  window.parent.postMessage(
    {
      tipo: "agendauno:altura",
      altura: Math.ceil(caja.value.scrollHeight) + 8,
    },
    "*",
  );
}

function alCargar(f: FormularioPublico): void {
  negocio.value = f.negocio?.nombre ?? null;
  if (!embebido.value && f.titulo) {
    document.title = negocio.value
      ? `${f.titulo} · ${negocio.value}`
      : f.titulo;
  }
}

onMounted(() => {
  if (embebido.value && typeof ResizeObserver !== "undefined" && caja.value) {
    observador = new ResizeObserver(avisarAltura);
    observador.observe(caja.value);
  }
});
onBeforeUnmount(() => observador?.disconnect());
</script>

<template>
  <section
    ref="caja"
    class="mx-auto w-full"
    :class="embebido ? 'p-3' : 'max-w-xl px-4 py-8 sm:py-12'"
  >
    <p
      v-if="negocio && !embebido"
      class="mb-3 text-sm font-medium"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ negocio }}
    </p>
    <div :class="embebido ? '' : 'tu-card p-5 sm:p-7'">
      <FormularioCaptacion
        :slug="props.slug"
        :formulario="props.formulario"
        :canal="embebido ? 'embebido' : 'enlace'"
        @cargado="alCargar"
        @enviado="avisarAltura"
      />
    </div>
    <!-- Dentro de otra web no hay pie: la leyenda fija va aquí (ADR 0167). -->
    <p v-if="embebido" class="mt-3 text-center">
      <HechoCon />
    </p>
  </section>
</template>
