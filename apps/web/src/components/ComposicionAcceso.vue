<script setup lang="ts">
import { computed } from "vue";
import IconoNav from "@/components/IconoNav.vue";
import type { Producto } from "@/lib/producto";

const props = withDefaults(
  defineProps<{ producto: Producto; registro?: boolean }>(),
  { registro: false },
);
const collage = computed(() =>
  props.producto === "turnouno"
    ? "entrar.collageCitas"
    : "entrar.collageClases",
);
</script>

<template>
  <div class="acceso-escena" :data-producto="producto">
    <!-- La fotografía no contiene texto: la composición conserva texto real y escalable. -->
    <img
      class="acceso-escena-imagen"
      :src="`/assets/landing/acceso-${producto}-v2.webp`"
      alt=""
      width="1448"
      height="1086"
      decoding="async"
      fetchpriority="high"
    />
    <div class="acceso-escena-mensaje">
      <h2>
        {{
          $t(registro ? "entrar.visualRegistroTitulo" : "entrar.panelTitulo")
        }}
      </h2>
      <p>
        {{
          $t(
            registro
              ? "entrar.visualRegistroDesc"
              : producto === "turnouno"
                ? "entrar.panelDescCitas"
                : "entrar.panelDesc",
          )
        }}
      </p>
    </div>
    <div class="acceso-escena-ejemplos" aria-hidden="true">
      <div
        v-for="(clave, i) in ['uno', 'dos', 'tres']"
        :key="clave"
        class="acceso-escena-cita"
        :class="`acceso-escena-cita--${i + 1}`"
      >
        <span class="acceso-escena-icono">
          <span v-if="producto === 'agendauno' && i === 1">7</span>
          <IconoNav v-else :nombre="i === 2 ? 'agenda' : 'hecho'" :tam="18" />
        </span>
        <span>
          <strong>{{ $t(`${collage}.${clave}`) }}</strong>
          <small>{{ $t(`${collage}.${clave}Detalle`) }}</small>
        </span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.acceso-escena {
  position: relative;
  container-type: inline-size;
  width: 100%;
  margin-inline: auto;
  color: #071d44;
}
.acceso-escena-imagen {
  display: block;
  width: 100%;
  height: auto;
}
/* El texto cabe en el claro de arriba a la izquierda, sin tocar la foto de abajo. */
.acceso-escena-mensaje {
  position: absolute;
  top: 14.5%;
  left: 12%;
  width: 34%;
}
.acceso-escena-mensaje h2 {
  margin: 0;
  font-size: 5.2cqw;
  line-height: 1.04;
  font-weight: 500;
  letter-spacing: -0.045em;
  text-wrap: balance;
}
.acceso-escena-mensaje p {
  margin-top: 1.4cqw;
  font-size: 1.8cqw;
  line-height: 1.45;
  color: #425b77;
}
.acceso-escena-cita {
  position: absolute;
  display: flex;
  align-items: center;
  gap: 1.2cqw;
  padding: 1.4cqw 1.8cqw;
  border: 1px solid rgb(255 255 255 / 85%);
  border-radius: 2cqw;
  background: #ffffff;
  box-shadow: 0 0.7rem 1.8rem rgb(26 65 93 / 13%);
}
.acceso-escena-cita--1 {
  left: 51%;
  top: 43%;
}
.acceso-escena-cita--2 {
  left: 12%;
  bottom: 5%;
}
.acceso-escena-cita--3 {
  right: 3%;
  bottom: 5%;
}
.acceso-escena-icono {
  display: grid;
  flex: 0 0 auto;
  width: 4.4cqw;
  height: 4.4cqw;
  place-items: center;
  border-radius: 50%;
  background: #198754;
  color: #fff;
  font-size: 2.3cqw;
  font-weight: 700;
}
.acceso-escena-icono :deep(svg) {
  width: 2.5cqw;
  height: 2.5cqw;
}
.acceso-escena-cita--2 .acceso-escena-icono {
  background: #1775bd;
}
.acceso-escena-cita strong,
.acceso-escena-cita small {
  display: block;
}
.acceso-escena-cita strong {
  font-size: 2.05cqw;
  font-weight: 600;
  line-height: 1.3;
}
.acceso-escena-cita small {
  margin-top: 0.3cqw;
  font-size: 1.55cqw;
  color: #53677f;
}
/* En paneles estrechos, menos detalle antes que texto diminuto. */
@container (max-width: 560px) {
  .acceso-escena-cita small {
    display: none;
  }
  .acceso-escena-cita strong {
    font-size: 2.5cqw;
  }
  .acceso-escena-mensaje p {
    font-size: 2.35cqw;
    line-height: 1.35;
  }
}
@container (max-width: 480px) {
  .acceso-escena-mensaje p {
    display: none;
  }
}
</style>
