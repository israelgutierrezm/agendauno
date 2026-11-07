<script setup lang="ts">
import type { FotoCollage } from "@/components/HeroCollage.vue";
defineProps<{ fotos: readonly FotoCollage[] }>();
</script>

<template>
  <figure class="hero-editorial">
    <div class="hero-editorial-fotos">
      <div
        v-for="(foto, i) in fotos"
        :key="foto.clave"
        class="tu-hero-foto"
        :class="`editorial-foto-${i}`"
      >
        <img
          :src="foto.src"
          :alt="foto.alt"
          width="1122"
          height="1402"
          :fetchpriority="i === 1 ? 'high' : undefined"
          decoding="async"
          :style="{ objectPosition: foto.posicion }"
        />
        <span>{{ foto.etiqueta }}</span>
      </div>
    </div>
    <figcaption>Tu negocio. Tu equipo. Una agenda que los conecta.</figcaption>
  </figure>
</template>

<style scoped>
.hero-editorial {
  margin: 0;
  min-width: 0;
  width: 100%;
}
.hero-editorial-fotos {
  display: grid;
  grid-template-columns: 1.35fr 1fr;
  grid-template-rows: 1fr 1fr;
  gap: 14px;
  height: clamp(390px, 38vw, 500px);
}
.tu-hero-foto {
  min-height: 0;
  position: relative;
  overflow: hidden;
  border-radius: 18px;
  background: var(--superficie);
}
.editorial-foto-1 {
  grid-column: 1;
  grid-row: 1 / 3;
}
.editorial-foto-0 {
  grid-column: 2;
  grid-row: 1;
  margin-top: 24px;
}
.editorial-foto-2 {
  grid-column: 2;
  grid-row: 2;
  margin-bottom: 24px;
}
.tu-hero-foto img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 600ms ease;
}
.tu-hero-foto:hover img {
  transform: scale(1.025);
}
.tu-hero-foto span {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 40px 18px 16px;
  color: #fff;
  background: linear-gradient(transparent, rgba(14, 24, 29, 0.7));
  font-size: 0.85rem;
  font-weight: 500;
}
figcaption {
  text-align: center;
  font-size: 0.75rem;
  color: var(--texto-suave);
  margin-top: 16px;
}
@media (max-width: 1023px) {
  .hero-editorial {
    max-width: 680px;
    margin-inline: auto;
  }
  .hero-editorial-fotos {
    height: 460px;
  }
}
@media (max-width: 639px) {
  .hero-editorial-fotos {
    height: 340px;
    gap: 10px;
  }
  .tu-hero-foto {
    border-radius: 14px;
  }
  .tu-hero-foto span {
    padding: 28px 12px 12px;
    font-size: 0.7rem;
  }
  .editorial-foto-0 {
    margin-top: 16px;
  }
  .editorial-foto-2 {
    margin-bottom: 16px;
  }
}
@media (prefers-reduced-motion: reduce) {
  .tu-hero-foto img {
    transition: none;
  }
  .tu-hero-foto:hover img {
    transform: none;
  }
}
</style>
