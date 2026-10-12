<script setup lang="ts">
import { useI18n } from "vue-i18n";

import { formatoFechaHora, type EnvioMedicion } from "@/lib/medicion";

/**
 * Los últimos envíos a GA4 y Meta (ADR 0143): qué conversión, a qué destino, cuándo
 * pasó y en qué estado quedó (pendiente, aceptado por el proveedor, con error u omitido
 * con su motivo). «Aceptado» no quiere decir atribuido.
 */
withDefaults(defineProps<{ envios: EnvioMedicion[]; conNegocio?: boolean }>(), {
  conNegocio: false,
});
const { t, te } = useI18n();

const TONOS: Record<EnvioMedicion["estado"], string> = {
  pendiente: "var(--aviso)",
  enviado: "var(--exito)",
  error: "var(--error)",
  omitido: "var(--texto-suave)",
};

function detalle(e: EnvioMedicion): string {
  const partes: string[] = [];
  if (e.motivo !== null) {
    const clave = `medicion.envios.motivo.${e.motivo}`;
    partes.push(te(clave) ? t(clave) : e.motivo);
  }
  if (e.estado === "error" && e.proximo_intento_en !== null) {
    partes.push(
      t("medicion.envios.reintento", {
        fecha: formatoFechaHora(e.proximo_intento_en),
      }),
    );
  }
  return partes.join(" · ");
}
</script>

<template>
  <section class="tu-card p-4 sm:p-5" data-prueba="envios-medicion">
    <h3 class="font-medium">{{ t("medicion.envios.titulo") }}</h3>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ t("medicion.envios.aceptadoNoAtribuido") }}
    </p>
    <p
      v-if="envios.length === 0"
      class="mt-3 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("medicion.envios.vacio") }}
    </p>
    <ul v-else class="mt-3 divide-y" :style="{ borderColor: 'var(--borde)' }">
      <li
        v-for="e in envios"
        :key="e.id"
        class="flex flex-col gap-1 py-2 text-sm sm:flex-row sm:items-center sm:justify-between"
        data-prueba="envio-medicion"
      >
        <div class="min-w-0">
          <p class="font-medium">
            {{ e.nombre }}
            <span class="font-normal" :style="{ color: 'var(--texto-suave)' }">
              ·
              {{
                e.proveedor ? t(`medicion.destinos.${e.proveedor}.nombre`) : ""
              }}
              <template v-if="conNegocio && e.negocio">
                · {{ e.negocio }}</template
              >
            </span>
          </p>
          <p :style="{ color: 'var(--texto-suave)' }">
            {{ formatoFechaHora(e.ocurrido_en) }}
            <template v-if="detalle(e)"> · {{ detalle(e) }}</template>
          </p>
        </div>
        <span class="tu-estado shrink-0" :style="{ '--tono': TONOS[e.estado] }">
          {{ t(`medicion.envios.estado.${e.estado}`) }}
        </span>
      </li>
    </ul>
  </section>
</template>
