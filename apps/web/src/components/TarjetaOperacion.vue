<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";

/**
 * Acceso grande del Inicio del negocio («Tu operación, a mano»): el ícono en su
 * cuadro de color, el título, qué se hace ahí y un botón con flecha, sobre un fondo
 * suave del mismo color con una ilustración a la derecha. Toda la tarjeta lleva a la
 * pantalla. Las ilustraciones son vectores con los colores del tema (se leen también
 * en oscuro).
 */
export type Ilustracion =
  "agenda" | "miembros" | "recepcion" | "ventas" | "horarios";

defineProps<{
  to: RouteLocationRaw;
  icono: string;
  titulo: string;
  texto: string;
  tono: string;
  ilustracion: Ilustracion;
}>();
</script>

<template>
  <RouterLink :to="to" class="to-tarjeta tu-card" :class="`tu-tono-${tono}`">
    <span class="to-fondo" aria-hidden="true"></span>

    <!-- Ilustración -->
    <svg
      class="to-dibujo"
      viewBox="0 0 220 230"
      preserveAspectRatio="xMaxYMax meet"
      aria-hidden="true"
    >
      <template v-if="ilustracion === 'agenda'">
        <g transform="rotate(-7 140 140)">
          <rect
            x="52"
            y="36"
            width="190"
            height="210"
            rx="16"
            class="to-panel"
          />
          <text x="66" y="82" class="to-hora">9:00</text>
          <text x="66" y="128" class="to-hora">10:00</text>
          <text x="66" y="174" class="to-hora">11:00</text>
          <text x="66" y="220" class="to-hora">12:00</text>
          <line x1="100" y1="86" x2="235" y2="86" class="to-linea" />
          <line x1="100" y1="132" x2="235" y2="132" class="to-linea" />
          <line x1="100" y1="178" x2="235" y2="178" class="to-linea" />
          <rect
            x="112"
            y="58"
            width="92"
            height="38"
            rx="8"
            class="to-b-morado"
          />
          <rect
            x="122"
            y="70"
            width="40"
            height="6"
            rx="3"
            class="to-b-texto"
          />
          <rect
            x="140"
            y="106"
            width="92"
            height="38"
            rx="8"
            class="to-b-verde"
          />
          <rect
            x="150"
            y="118"
            width="46"
            height="6"
            rx="3"
            class="to-b-texto"
          />
          <rect
            x="104"
            y="152"
            width="96"
            height="38"
            rx="8"
            class="to-b-azul"
          />
          <rect
            x="114"
            y="164"
            width="52"
            height="6"
            rx="3"
            class="to-b-texto"
          />
        </g>
      </template>

      <template v-else-if="ilustracion === 'miembros'">
        <circle cx="160" cy="74" r="30" class="to-tono-fuerte" />
        <path d="M112 150a48 48 0 0 1 96 0z" class="to-tono-fuerte" />
        <circle cx="160" cy="74" r="24" class="to-tono-medio" />
        <g transform="rotate(-4 140 170)">
          <rect
            x="62"
            y="132"
            width="150"
            height="76"
            rx="14"
            class="to-panel"
          />
          <circle cx="94" cy="170" r="17" class="to-tono-suave" />
          <circle cx="94" cy="164" r="5.5" class="to-trazo" />
          <path d="M84 180a10 10 0 0 1 20 0" class="to-trazo" />
          <rect
            x="122"
            y="154"
            width="70"
            height="8"
            rx="4"
            class="to-renglon"
          />
          <rect
            x="122"
            y="168"
            width="52"
            height="8"
            rx="4"
            class="to-renglon"
          />
          <rect
            x="122"
            y="182"
            width="60"
            height="8"
            rx="4"
            class="to-renglon"
          />
        </g>
      </template>

      <template v-else-if="ilustracion === 'recepcion'">
        <rect x="58" y="96" width="70" height="120" rx="14" class="to-lector" />
        <rect
          x="70"
          y="110"
          width="46"
          height="30"
          rx="6"
          class="to-tono-medio"
        />
        <g transform="rotate(8 160 130)">
          <rect
            x="112"
            y="40"
            width="88"
            height="172"
            rx="18"
            class="to-telefono"
          />
          <rect
            x="119"
            y="52"
            width="74"
            height="148"
            rx="12"
            class="to-panel"
          />
          <g class="to-qr">
            <rect x="130" y="78" width="16" height="16" rx="2" />
            <rect x="166" y="78" width="16" height="16" rx="2" />
            <rect x="130" y="114" width="16" height="16" rx="2" />
            <rect x="152" y="80" width="6" height="6" />
            <rect x="152" y="92" width="6" height="6" />
            <rect x="152" y="104" width="6" height="6" />
            <rect x="164" y="102" width="6" height="6" />
            <rect x="176" y="104" width="6" height="6" />
            <rect x="152" y="116" width="6" height="6" />
            <rect x="166" y="114" width="6" height="6" />
            <rect x="176" y="122" width="6" height="6" />
            <rect x="158" y="124" width="6" height="6" />
          </g>
          <circle cx="156" cy="166" r="15" class="to-exito" />
          <path d="M149 166l5 5 9-10" class="to-palomita" />
        </g>
      </template>

      <template v-else-if="ilustracion === 'ventas'">
        <g transform="rotate(-14 160 80)">
          <rect
            x="108"
            y="44"
            width="104"
            height="66"
            rx="10"
            class="to-tono-fuerte"
          />
          <rect x="108" y="60" width="104" height="12" class="to-banda" />
          <rect x="122" y="84" width="20" height="14" rx="3" class="to-chip" />
        </g>
        <rect
          x="64"
          y="104"
          width="118"
          height="122"
          rx="18"
          class="to-lector"
        />
        <rect
          x="78"
          y="118"
          width="90"
          height="34"
          rx="7"
          class="to-tono-medio"
        />
        <g class="to-teclas">
          <rect x="80" y="162" width="24" height="12" rx="4" />
          <rect x="111" y="162" width="24" height="12" rx="4" />
          <rect x="142" y="162" width="24" height="12" rx="4" />
          <rect x="80" y="180" width="24" height="12" rx="4" />
          <rect x="111" y="180" width="24" height="12" rx="4" />
          <rect x="142" y="180" width="24" height="12" rx="4" />
          <rect x="80" y="198" width="24" height="12" rx="4" />
          <rect x="111" y="198" width="24" height="12" rx="4" />
          <rect x="142" y="198" width="24" height="12" rx="4" />
        </g>
      </template>

      <template v-else>
        <circle cx="150" cy="104" r="62" class="to-panel" />
        <circle cx="150" cy="104" r="62" class="to-aro" />
        <path d="M150 70v34l22 14" class="to-manecillas" />
        <rect
          x="70"
          y="186"
          width="44"
          height="26"
          rx="7"
          class="to-tono-medio"
        />
        <rect
          x="122"
          y="186"
          width="44"
          height="26"
          rx="7"
          class="to-tono-fuerte"
        />
        <rect
          x="174"
          y="186"
          width="44"
          height="26"
          rx="7"
          class="to-tono-suave"
        />
      </template>
    </svg>

    <span class="to-contenido">
      <span class="tu-icono-tono to-icono" aria-hidden="true">
        <IconoNav :nombre="icono" :tam="24" />
      </span>
      <span class="to-titulo">{{ titulo }}</span>
      <span class="to-texto">{{ texto }}</span>
      <span class="to-boton" aria-hidden="true">
        <IconoNav nombre="flecha" :tam="20" />
      </span>
    </span>
  </RouterLink>
</template>

<style scoped>
.to-tarjeta {
  position: relative;
  display: block;
  min-height: 15rem;
  overflow: hidden;
  padding: 1.35rem;
  color: inherit;
  text-decoration: none;
  background: linear-gradient(
    120deg,
    var(--superficie) 40%,
    color-mix(in srgb, var(--tono) 14%, var(--superficie)) 100%
  );
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    transform 0.15s ease;
}
.to-tarjeta:hover {
  border-color: color-mix(in srgb, var(--tono) 45%, var(--borde));
  transform: translateY(-2px);
}
.to-fondo {
  position: absolute;
  top: -4rem;
  right: -4rem;
  width: 13rem;
  height: 13rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--tono) 12%, transparent);
}
.to-dibujo {
  position: absolute;
  right: 0;
  bottom: 0;
  width: 58%;
  max-width: 15rem;
  height: 92%;
}
.to-contenido {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  height: 100%;
  min-height: 12.3rem;
  max-width: 58%;
}
.to-icono {
  width: 3.1rem;
  height: 3.1rem;
  border-radius: 0.9rem;
}
.to-titulo {
  margin-top: 1rem;
  font-size: 1.2rem;
  font-weight: 700;
  line-height: 1.2;
}
.to-texto {
  margin-top: 0.4rem;
  font-size: 0.88rem;
  line-height: 1.4;
  color: var(--texto-suave);
}
.to-boton {
  margin-top: auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 999px;
  background: var(--tono);
  color: #fff;
  box-shadow: 0 6px 14px color-mix(in srgb, var(--tono) 35%, transparent);
}

/* Piezas de las ilustraciones (con los colores del tema). */
.to-panel {
  fill: var(--superficie);
  stroke: var(--borde);
  stroke-width: 1.5;
}
.to-hora {
  font-size: 10px;
  fill: var(--texto-suave);
}
.to-linea {
  stroke: var(--borde);
  stroke-width: 1.2;
}
.to-b-morado {
  fill: color-mix(in srgb, #8b5cf6 30%, var(--superficie));
}
.to-b-verde {
  fill: color-mix(in srgb, #16a34a 26%, var(--superficie));
}
.to-b-azul {
  fill: color-mix(in srgb, #3b82f6 28%, var(--superficie));
}
.to-b-texto {
  fill: color-mix(in srgb, var(--texto) 45%, transparent);
}
.to-tono-suave {
  fill: color-mix(in srgb, var(--tono) 16%, var(--superficie));
}
.to-tono-medio {
  fill: color-mix(in srgb, var(--tono) 40%, var(--superficie));
}
.to-tono-fuerte {
  fill: color-mix(in srgb, var(--tono) 70%, var(--superficie));
}
.to-trazo {
  fill: none;
  stroke: var(--tono);
  stroke-width: 2.4;
  stroke-linecap: round;
}
.to-renglon {
  fill: var(--superficie-2);
}
.to-lector {
  fill: #2b3140;
}
.to-telefono {
  fill: #1e2a3b;
}
.to-qr {
  fill: #1e2a3b;
}
.to-exito {
  fill: #16a34a;
}
.to-palomita {
  fill: none;
  stroke: #fff;
  stroke-width: 3;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.to-banda {
  fill: rgb(0 0 0 / 22%);
}
.to-chip {
  fill: #f5d46b;
}
.to-teclas {
  fill: #4b5263;
}
.to-aro {
  fill: none;
  stroke: var(--tono);
  stroke-width: 5;
}
.to-manecillas {
  fill: none;
  stroke: var(--texto);
  stroke-width: 5;
  stroke-linecap: round;
  stroke-linejoin: round;
}
</style>
