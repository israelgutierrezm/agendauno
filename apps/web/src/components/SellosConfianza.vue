<script setup lang="ts">
import { useI18n } from "vue-i18n";

import type { Modo } from "@/marketing/modalidades";

/*
| Sellos de confianza bajo el hero de las páginas comerciales (portada, /clases y
| /citas). Con `modo`, el sello del precio habla solo de esa modalidad
| (`landing.{modo}.confianza.cobro`); sin él, el general (`landing.confianza.cobro`).
*/
const props = defineProps<{ modo?: Modo | null }>();
const { t } = useI18n();

const SELLOS = ["prueba", "configuracion", "cobro", "cancelacion"] as const;

function texto(sello: (typeof SELLOS)[number]): string {
  return sello === "cobro" && props.modo
    ? t(`landing.${props.modo}.confianza.cobro`)
    : t(`landing.confianza.${sello}`);
}
</script>

<template>
  <section class="tu-confianza" :aria-label="t('landing.confianza.titulo')">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
      <ul class="tu-confianza-grid" role="list">
        <li v-for="sello in SELLOS" :key="sello" class="tu-confianza-item">
          <span class="tu-confianza-punto" aria-hidden="true"></span>
          <span>{{ texto(sello) }}</span>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.tu-confianza {
  background: var(--fondo);
  padding: 0 0 2.5rem;
}
.tu-confianza-grid {
  display: grid;
  gap: 0.75rem;
  padding: 1.1rem 1.25rem;
  border-radius: var(--radio-tarjeta, 18px);
  background: var(--superficie);
}
.tu-confianza-item {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  color: var(--texto-suave);
  font-size: 0.82rem;
  font-weight: 500;
  text-align: center;
}
/* Estado como punto + texto, sin íconos. */
.tu-confianza-punto {
  width: 0.45rem;
  height: 0.45rem;
  flex: 0 0 auto;
  border-radius: 50%;
  background: var(--exito);
}
@media (min-width: 1024px) {
  .tu-confianza-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
@media (max-width: 1023px) and (min-width: 640px) {
  .tu-confianza-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 639px) {
  .tu-confianza {
    padding-bottom: 1.5rem;
  }
  .tu-confianza-grid {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
