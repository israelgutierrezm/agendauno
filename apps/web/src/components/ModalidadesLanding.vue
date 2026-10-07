<script setup lang="ts">
import { RouterLink } from "vue-router";
import {
  NOMBRE_MODALIDAD,
  NOMBRE_RUTA_MODALIDAD,
  type Modo,
} from "@/marketing/modalidades";

/*
| Las dos modalidades en la portada, bajo el hero: cada tarjeta lleva a su landing
| (/clases, /citas) y se nombra igual que en los precios y el registro
| (NOMBRE_MODALIDAD). Al elegir emite `elegir` con el modo; la analítica la pone la
| página (`marketing_business_mode_selected`).
*/
defineEmits<{ elegir: [modo: Modo] }>();
const opciones = [
  {
    id: "clases" as const,
    titulo: NOMBRE_MODALIDAD.clases,
    para: "Pilates · Pole dance · Acuáticas · Baile · Yoga · CrossFit / HYROX",
    descripcion:
      "Cuida a tu comunidad, no una hoja de cálculo. Organiza grupos, cupos y membresías desde una misma agenda.",
    imagen: "pilates-v1.jpg",
    alt: "Alumna practicando Pilates Reformer",
    etiqueta: "Cada lugar cuenta",
    ejemplo: "Pilates Reformer",
    detalle: "18:00 · 6 de 8 lugares reservados",
    ventajas: [
      "Clases recurrentes y lista de espera",
      "Paquetes, membresías y asistencia",
      "Reservas en línea para tus alumnos",
    ],
    enlace: "Conocer AgendaUno para clases",
  },
  {
    id: "citas" as const,
    titulo: NOMBRE_MODALIDAD.citas,
    para: "Barberías · Estéticas · Spas · Psicólogos · Dentistas · Nutriólogos",
    descripcion:
      "Dale a cada profesional una agenda clara. Tus clientes eligen servicio, quién los atiende y un horario disponible.",
    imagen: "barberia-v1.jpg",
    alt: "Barbero atendiendo a un cliente en su negocio",
    etiqueta: "Cada profesional, a tiempo",
    ejemplo: "Corte y barba · Marco",
    detalle: "18:00 · 60 min · Cita confirmada",
    ventajas: [
      "Disponibilidad por profesional",
      "Servicios con duración y precio",
      "Citas en línea y desde recepción",
    ],
    enlace: "Conocer AgendaUno para citas",
  },
];
</script>

<template>
  <div class="modalidades">
    <article
      v-for="opcion in opciones"
      :key="opcion.id"
      class="modalidad"
      :class="`modalidad--${opcion.id}`"
    >
      <div class="modalidad-imagen">
        <img
          :src="`/assets/landing/disciplinas/${opcion.imagen}`"
          :alt="opcion.alt"
          width="1122"
          height="1402"
          loading="lazy"
          decoding="async"
        />
        <span class="modalidad-etiqueta">{{ opcion.etiqueta }}</span>
        <div class="modalidad-reserva" aria-hidden="true">
          <span class="modalidad-icono">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.7"
            >
              <rect x="3" y="5" width="18" height="16" rx="3" />
              <path d="M7 3v4m10-4v4M3 11h18m-14 5 3 3 6-6" />
            </svg>
          </span>
          <span
            ><small>Ejemplo de agenda</small
            ><strong>{{ opcion.ejemplo }}</strong
            ><span>{{ opcion.detalle }}</span></span
          >
        </div>
      </div>
      <div class="modalidad-contenido">
        <h3>{{ opcion.titulo }}</h3>
        <p class="modalidad-para">{{ opcion.para }}</p>
        <p class="modalidad-descripcion">{{ opcion.descripcion }}</p>
        <ul>
          <li v-for="ventaja in opcion.ventajas" :key="ventaja">
            <svg
              class="modalidad-check"
              aria-hidden="true"
              viewBox="0 0 24 24"
              width="16"
              height="16"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path d="m5 12.5 4 4 10-10" /></svg
            >{{ ventaja }}
          </li>
        </ul>
        <RouterLink
          :to="{ name: NOMBRE_RUTA_MODALIDAD[opcion.id] }"
          @click="$emit('elegir', opcion.id)"
          >{{ opcion.enlace }} <span aria-hidden="true">→</span></RouterLink
        >
      </div>
    </article>
  </div>
</template>

<style scoped>
.modalidades {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1.5rem;
  margin-top: 2.5rem;
}
.modalidad {
  --acento: var(--primario-fuerte);
  overflow: hidden;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta, 18px);
  background: var(--fondo);
}
.modalidad--citas {
  --acento: var(--enlace);
}
.modalidad-imagen {
  position: relative;
  height: 18rem;
  overflow: hidden;
  background: #031b4e;
}
.modalidad-imagen::after {
  content: "";
  position: absolute;
  inset: 0;
  background: linear-gradient(0deg, rgb(3 27 78 / 60%), transparent 65%);
  pointer-events: none;
}
.modalidad-imagen img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center 40%;
  transition: transform 0.7s ease;
}
.modalidad:hover .modalidad-imagen img {
  transform: scale(1.035);
}
.modalidad-etiqueta {
  position: absolute;
  top: 1rem;
  left: 1rem;
  padding: 0.4rem 0.7rem;
  border-radius: 8px;
  background: #031b4e;
  color: #fff;
  font-size: 0.75rem;
  font-weight: 600;
}
.modalidad-reserva {
  position: absolute;
  z-index: 1;
  bottom: 1rem;
  left: 1rem;
  right: 1rem;
  display: flex;
  gap: 0.8rem;
  align-items: center;
  padding: 0.85rem;
  border: 1px solid rgb(255 255 255 / 70%);
  border-radius: 1rem;
  background: rgb(255 255 255 / 96%);
  color: #031b4e;
}
.modalidad-reserva strong,
.modalidad-reserva small,
.modalidad-reserva span > span {
  display: block;
}
.modalidad-reserva small {
  color: #516078;
  font-size: 0.75rem;
  margin-bottom: 0.15rem;
}
.modalidad-reserva strong {
  font-size: 0.95rem;
}
.modalidad-reserva span > span {
  font-size: 0.75rem;
  margin-top: 0.2rem;
}
.modalidad-icono {
  display: grid;
  place-items: center;
  width: 2.5rem;
  height: 2.5rem;
  flex-shrink: 0;
  border-radius: 0.75rem;
  background: #eaf1f3;
  color: #24566b;
}
.modalidad-icono svg {
  width: 1.5rem;
  height: 1.5rem;
}
.modalidad-contenido {
  padding: clamp(1.25rem, 3vw, 2rem);
}
/* Títulos de tarjeta con peso 500, como los de precios y funciones. */
h3 {
  font-size: clamp(1.45rem, 2.6vw, 1.75rem);
  font-weight: 500;
  letter-spacing: -0.03em;
  line-height: 1.15;
}
.modalidad-para {
  margin-top: 0.65rem;
  color: var(--acento);
  font-size: 0.8rem;
  font-weight: 600;
}
.modalidad-descripcion {
  margin-top: 1rem;
  color: var(--texto-suave);
  line-height: 1.6;
}
ul {
  display: grid;
  gap: 0.65rem;
  margin: 1.4rem 0;
  padding: 0;
  list-style: none;
}
li {
  display: flex;
  gap: 0.65rem;
  font-size: 0.88rem;
}
/* Marca de verificación sin texto ni emoji (SVG, como en /clases y /citas). */
.modalidad-check {
  flex: 0 0 auto;
  margin-top: 0.2rem;
  color: var(--acento);
}
a {
  display: inline-flex;
  align-items: center;
  gap: 0.75rem;
  min-height: 2.75rem;
  color: var(--acento);
  font-weight: 600;
  text-decoration: underline;
  text-underline-offset: 0.3rem;
}
a:focus-visible {
  outline: 2px solid var(--acento);
  outline-offset: 4px;
  border-radius: 0.25rem;
}
@media (max-width: 639px) {
  .modalidades {
    grid-template-columns: 1fr;
  }
  .modalidad-imagen {
    height: 15rem;
  }
}
@media (prefers-reduced-motion: reduce) {
  .modalidad-imagen img {
    transition: none;
  }
  .modalidad:hover .modalidad-imagen img {
    transform: none;
  }
}
</style>
