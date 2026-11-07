<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import { productoDeModalidad } from "@/lib/producto";
import type { Modo } from "@/marketing/modalidades";
import { usePreciosPublicos } from "@/marketing/preciosPublicos";
import { useRegistroDelProducto } from "@/marketing/registroProducto";

/*
| Sellos de confianza bajo el hero de las páginas comerciales (portada, /clases y
| /citas). Con `modo`, el sello del precio habla solo de esa modalidad
| (`landing.{modo}.confianza.cobro`); sin él, el general (`landing.confianza.cobro`).
| Los días de prueba son los publicados: los de su modalidad o, en la portada, los
| de la prueba más corta (lo general no promete de más).
| Si el producto aún no recibe registros (ADR 0108), ni prueba ni permanencia: un
| sello de «Abre pronto».
*/
const props = defineProps<{ modo?: Modo | null }>();
const { t } = useI18n();
const precios = usePreciosPublicos();
const { abierto: registroAbierto } = useRegistroDelProducto(
  props.modo ? productoDeModalidad(props.modo) : undefined,
);
const diasPrueba = computed(() =>
  props.modo
    ? precios.datos[props.modo].dias_prueba
    : Math.min(
        precios.datos.clases.dias_prueba,
        precios.datos.citas.dias_prueba,
      ),
);

type Sello =
  "prueba" | "proximamente" | "configuracion" | "cobro" | "cancelacion";
const sellos = computed<Sello[]>(() =>
  registroAbierto.value
    ? ["prueba", "configuracion", "cobro", "cancelacion"]
    : ["proximamente", "configuracion", "cobro"],
);
const activo = ref(2);

function texto(sello: Sello): string {
  if (sello === "proximamente") {
    return t("landing.prelanzamiento.sello");
  }
  return sello === "cobro" && props.modo
    ? t(`landing.${props.modo}.confianza.cobro`)
    : t(`landing.confianza.${sello}`, { dias: diasPrueba.value });
}
</script>

<template>
  <section
    class="tu-confianza"
    :aria-label="
      registroAbierto
        ? t('landing.confianza.titulo')
        : t('landing.prelanzamiento.sellosTitulo')
    "
  >
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
      <ul
        class="tu-confianza-grid"
        role="list"
        :style="{ '--sellos': sellos.length }"
      >
        <li
          v-for="(sello, i) in sellos"
          :key="sello"
          class="tu-confianza-item"
          :class="{ 'tu-confianza-item--activo': activo === i }"
          :data-sello="sello"
          @mouseenter="activo = i"
          @click="activo = i"
        >
          <span class="tu-confianza-punto" aria-hidden="true" />
          <span>{{ texto(sello) }}</span>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.tu-confianza {
  position: relative;
  z-index: 2;
  background: transparent;
  margin-top: -0.5rem;
  padding: 0 0 1.5rem;
}
.tu-confianza-grid {
  position: relative;
  overflow: hidden;
  display: grid;
  gap: 0.5rem;
  padding: 0.36rem 0.65rem;
  border: 1px solid color-mix(in srgb, var(--superficie) 65%, var(--borde));
  border-radius: 999px;
  background: color-mix(in srgb, var(--superficie) 92%, transparent);
  backdrop-filter: blur(18px);
  box-shadow:
    0 16px 38px -18px rgb(13 59 110 / 20%),
    0 2px 8px rgb(13 59 110 / 4%);
}
.tu-confianza-item {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.36rem 1.05rem;
  border-radius: 999px;
  color: var(--texto);
  font-size: 0.76rem;
  font-weight: 500;
  text-align: center;
  white-space: nowrap;
  cursor: pointer;
  transition:
    background-color 0.25s ease,
    color 0.25s ease,
    transform 0.25s ease,
    box-shadow 0.25s ease;
}
.tu-confianza-item:hover,
.tu-confianza-item--activo {
  background: color-mix(in srgb, #3a8fd4 13%, var(--superficie));
  color: var(--primario);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, #3a8fd4 32%, transparent);
}
.tu-confianza-punto {
  width: 0.44rem;
  height: 0.44rem;
  flex: 0 0 auto;
  border-radius: 50%;
  background: var(--acento);
  box-shadow: 0 0 0 3.5px color-mix(in srgb, var(--acento) 22%, transparent);
  transition:
    transform 0.25s ease,
    background-color 0.25s ease;
}
.tu-confianza-item:hover .tu-confianza-punto,
.tu-confianza-item--activo .tu-confianza-punto {
  background: var(--primario);
  transform: scale(1.1);
}
@media (min-width: 1024px) {
  .tu-confianza {
    display: flex;
    align-items: center;
    height: 3.6rem;
    min-height: 0;
    margin-top: 0;
    padding: 0 0 0.65rem;
    box-sizing: border-box;
  }
  .tu-confianza > div {
    width: 100%;
  }
  .tu-confianza-grid {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.34rem 1.25rem;
  }
  .tu-confianza-item:not(:first-child)::before {
    content: "";
    position: absolute;
    left: -0.65rem;
    top: 50%;
    width: 1px;
    height: 1.15rem;
    transform: translateY(-50%);
    background: color-mix(in srgb, var(--borde) 85%, transparent);
    pointer-events: none;
  }
}
@media (max-width: 1023px) and (min-width: 640px) {
  .tu-confianza-grid {
    border-radius: 22px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .tu-confianza-item {
    white-space: normal;
  }
}
@media (max-width: 639px) {
  .tu-confianza-grid {
    border-radius: 20px;
  }
  .tu-confianza-item {
    white-space: normal;
  }
}
@media (prefers-reduced-motion: reduce) {
  .tu-confianza-item,
  .tu-confianza-punto {
    transition: none;
  }
}
</style>
