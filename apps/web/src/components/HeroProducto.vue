<script setup lang="ts">
import { computed } from "vue";
import IconoNav from "@/components/IconoNav.vue";
import type { FotoCollage } from "@/components/HeroCollage.vue";
import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import type { Modo } from "@/marketing/modalidades";

const props = defineProps<{ modo: Modo; fotos: FotoCollage[] }>();
const marca = computed(() => PRODUCTOS[productoDeModalidad(props.modo)].nombre);
const clases = computed(() => props.modo === "clases");
const eventos = computed(() =>
  clases.value
    ? [
        {
          hora: "09:00",
          nombre: "Pilates Reformer",
          detalle: "Andrea · Sala 1",
          estado: "6 de 8 lugares",
          tono: "menta",
        },
        {
          hora: "10:00",
          nombre: "Pole dance",
          detalle: "Sofía · Sala 2",
          estado: "Clase completa",
          tono: "lila",
        },
        {
          hora: "11:00",
          nombre: "HYROX",
          detalle: "Alex · Sala 1",
          estado: "10 de 12 lugares",
          tono: "arena",
        },
      ]
    : [
        {
          hora: "09:00",
          nombre: "Corte de cabello",
          detalle: "Diego · con Marco",
          estado: "Confirmada",
          tono: "menta",
        },
        {
          hora: "10:00",
          nombre: "Corte y barba",
          detalle: "Carlos · con Luis",
          estado: "Anticipo recibido",
          tono: "lila",
        },
        {
          hora: "11:00",
          nombre: "Corte de cabello",
          detalle: "Ana · con Alex",
          estado: "Confirmada",
          tono: "arena",
        },
      ],
);
</script>

<template>
  <figure class="hero-producto" data-prueba="hero-producto">
    <div class="hp-ventana">
      <div class="hp-barra">
        <span class="hp-marca">{{ marca }}</span
        ><span>Vista de ejemplo</span
        ><span class="hp-avatar" aria-hidden="true">TU</span>
      </div>
      <div class="hp-cuerpo">
        <div class="hp-cabecera">
          <div>
            <p>ASÍ SE VE UN DÍA EN ORDEN</p>
            <h2>Tu agenda, bajo control.</h2>
          </div>
          <IconoNav nombre="agenda" :tam="24" />
        </div>
        <div class="hp-resumen">
          <div>
            <span>{{
              clases ? "Lugares reservados" : "Citas confirmadas"
            }}</span
            ><strong
              >{{ clases ? "24" : "12"
              }}<small>{{ clases ? "en 3 clases" : "para hoy" }}</small></strong
            >
          </div>
          <div>
            <span>{{ clases ? "Lugares disponibles" : "Tu equipo" }}</span
            ><strong
              >{{ clases ? "4" : "3"
              }}<small>{{
                clases ? "por reservar" : "profesionales"
              }}</small></strong
            >
          </div>
        </div>
        <div class="hp-dia">
          <strong>Hoy</strong
          ><span>{{
            clases ? "Clases y cupos" : "Servicios y profesionales"
          }}</span>
        </div>
        <ol class="hp-eventos" aria-label="Agenda ilustrativa">
          <li v-for="evento in eventos" :key="evento.hora">
            <time>{{ evento.hora }}</time>
            <div :class="['hp-evento', evento.tono]">
              <div>
                <strong>{{ evento.nombre }}</strong
                ><span>{{ evento.detalle }}</span>
              </div>
              <span class="hp-estado">{{ evento.estado }}</span>
            </div>
          </li>
        </ol>
        <div class="hp-cierre">
          <IconoNav nombre="miembros" :tam="17" /><span>{{
            clases
              ? "Recepción y coaches, con la misma información."
              : "Tu disponibilidad y la de tu equipo, en un lugar."
          }}</span>
        </div>
      </div>
    </div>
    <div class="hp-fotos" aria-hidden="true">
      <div v-for="(foto, i) in fotos" :key="foto.clave" class="tu-hero-foto">
        <img
          :src="foto.src"
          :alt="foto.alt"
          width="180"
          height="130"
          :fetchpriority="i === 1 ? 'high' : 'auto'"
          decoding="async"
          :style="{ objectPosition: foto.posicion }"
        /><span>{{ foto.etiqueta }}</span>
      </div>
    </div>
    <figcaption>Vista ilustrativa del producto · Datos de ejemplo</figcaption>
  </figure>
</template>

<style scoped>
.hero-producto {
  min-width: 0;
  max-width: 610px;
  width: 100%;
  margin: 0 auto;
  text-align: left;
  position: relative;
}
.hp-ventana {
  border: 1px solid var(--borde);
  border-radius: 20px;
  background: var(--superficie);
  box-shadow: 0 22px 65px -30px
    color-mix(in srgb, var(--texto) 25%, transparent);
  overflow: hidden;
}
.hp-barra {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 22px;
  border-bottom: 1px solid var(--borde);
  font-size: 0.7rem;
  color: var(--texto-suave);
}
.hp-marca {
  font-weight: 600;
  font-size: 0.95rem;
  color: var(--texto);
  margin-right: auto;
}
.hp-avatar {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  background: var(--fondo);
  border-radius: 9px;
  font-size: 0.6rem;
}
.hp-cuerpo {
  padding: 22px;
}
.hp-cabecera {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.hp-cabecera p {
  font-size: 0.6rem;
  letter-spacing: 0.12em;
  color: var(--texto-suave);
}
.hp-cabecera h2 {
  font-size: 1.3rem;
  font-weight: 500;
  letter-spacing: -0.035em;
  margin-top: 6px;
}
.hp-cabecera > svg {
  color: var(--marketing-rosa);
}
.hp-resumen {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin: 20px 0;
}
.hp-resumen > div {
  background: var(--fondo);
  border: 1px solid var(--borde);
  padding: 12px 14px;
  border-radius: 12px;
}
.hp-resumen span {
  font-size: 0.69rem;
  color: var(--texto-suave);
}
.hp-resumen strong {
  font-size: 1.8rem;
  font-weight: 400;
  display: flex;
  align-items: baseline;
  gap: 9px;
}
.hp-resumen small {
  font-size: 0.65rem;
  font-weight: 400;
  color: var(--texto-suave);
}
.hp-dia {
  display: flex;
  justify-content: space-between;
  font-size: 0.7rem;
  margin-bottom: 10px;
  color: var(--texto-suave);
}
.hp-dia strong {
  color: var(--texto);
  font-weight: 500;
}
.hp-eventos {
  display: grid;
  gap: 9px;
  list-style: none;
  margin: 0;
  padding: 0;
}
.hp-eventos li {
  display: flex;
  align-items: center;
  gap: 10px;
}
.hp-eventos time {
  font-size: 0.65rem;
  color: var(--texto-suave);
  font-variant-numeric: tabular-nums;
}
.hp-evento {
  display: flex;
  flex: 1;
  min-width: 0;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 11px 12px;
  border-left: 3px solid var(--hp-acento);
  border-radius: 4px 10px 10px 4px;
  background: color-mix(in srgb, var(--hp-acento) 9%, var(--superficie));
}
.menta {
  --hp-acento: #2a967f;
}
.lila {
  --hp-acento: #9a7fc3;
}
.arena {
  --hp-acento: #b68b48;
}
.hp-evento strong {
  display: block;
  font-size: 0.77rem;
  font-weight: 500;
}
.hp-evento div > span {
  display: block;
  font-size: 0.64rem;
  color: var(--texto-suave);
  margin-top: 2px;
}
.hp-estado {
  font-size: 0.59rem;
  max-width: 95px;
  text-align: right;
  color: var(--texto);
}
.hp-cierre {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.65rem;
  color: var(--texto-suave);
  margin-top: 18px;
}
.hp-fotos {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin: 14px 18px 0;
}
.tu-hero-foto {
  position: relative;
  overflow: hidden;
  border-radius: 12px;
  height: 83px;
  background: var(--superficie);
}
.tu-hero-foto img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.tu-hero-foto span {
  position: absolute;
  inset: auto 0 0;
  padding: 17px 8px 6px;
  background: linear-gradient(transparent, #10272cbf);
  color: white;
  font-size: 0.65rem;
  font-weight: 500;
}
.hero-producto figcaption {
  text-align: center;
  font-size: 0.6rem;
  margin-top: 10px;
  color: var(--texto-suave);
}
@media (max-width: 639px) {
  .hp-cuerpo {
    padding: 16px;
  }
  .hp-barra {
    padding: 12px 16px;
  }
  .hp-cabecera h2 {
    font-size: 1.15rem;
  }
  .hp-resumen {
    margin: 16px 0;
  }
  .hp-resumen small {
    font-size: 0.58rem;
  }
  .hp-evento {
    padding: 9px;
  }
  .hp-fotos {
    margin-inline: 8px;
  }
  .hp-estado {
    max-width: 72px;
  }
  .hp-cierre {
    font-size: 0.6rem;
  }
  .tu-hero-foto {
    height: 72px;
  }
}
/* Compact product preview: keep the first action above the fold on laptops. */
.hp-cuerpo {
  padding: 16px 20px;
}
.hp-barra {
  padding: 10px 20px;
}
.hp-cabecera h2 {
  font-size: 1.15rem;
  line-height: 1.35;
}
.hp-cabecera p {
  line-height: 1.4;
}
.hp-resumen {
  margin: 14px 0;
}
.hp-resumen > div {
  padding: 9px 12px;
}
.hp-resumen strong {
  font-size: 1.65rem;
  line-height: 1.3;
}
.hp-evento {
  padding: 8px 10px;
}
.hp-evento strong,
.hp-evento div > span {
  line-height: 1.4;
}
.hp-cierre {
  margin-top: 12px;
}
</style>
