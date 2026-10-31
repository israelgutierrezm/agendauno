<script setup lang="ts">
import { computed } from "vue";

import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import type { Modo } from "@/marketing/modalidades";

/*
| «Cámbiate sin empezar de cero» (landing): qué se trae del sistema anterior, qué no se
| traslada y con quién hablar para dejarlo listo. Solo lo que existe hoy: los
| importadores de alumnos/clientes, equipo, saldos a una fecha de corte y, en clases,
| las clases que se repiten (ver Importar* en el panel). Lo que no se importa se dice.
*/
const props = defineProps<{ modo: Modo }>();
const producto = computed(() => PRODUCTOS[productoDeModalidad(props.modo)]);

const CONTENIDO: Record<
  Modo,
  { traes: string[]; noSeTraslada: string[]; configuras: string[] }
> = {
  clases: {
    traes: [
      "Alumnos, contacto e instructores",
      "Tus clases y los horarios que se repiten",
      "Planes y créditos vigentes a tu fecha de corte",
      "Las reservas que ya tenían en tus próximas clases",
    ],
    noSeTraslada: [
      "Pagos y asistencias pasadas (quedan en tu sistema anterior)",
    ],
    configuras: [
      "Sucursal, clases, cupos y horarios",
      "Planes, paquetes, equipo y tu página",
    ],
  },
  citas: {
    traes: [
      "Clientes, contacto y tu equipo",
      "Los bonos vigentes de cada cliente a tu fecha de corte",
      "Las citas que ya tenías agendadas, sin avisarle nada a tus clientes",
    ],
    noSeTraslada: ["Pagos y citas pasadas (quedan en tu sistema anterior)"],
    configuras: [
      "Sucursal, servicios y precios",
      "Horarios del equipo y tu página",
    ],
  },
};
const contenido = computed(() => CONTENIDO[props.modo]);
const soporte = computed(() => producto.value.soporte);
const whatsapp = computed(() => {
  const numero = soporte.value.whatsapp.replace(/\D/g, "");
  return numero === "" ? null : `https://wa.me/${numero}`;
});
</script>

<template>
  <div class="ayuda" data-prueba="ayuda-para-empezar">
    <div class="ayuda-cabecera">
      <div>
        <h3 class="ayuda-titulo">Cámbiate sin empezar de cero</h3>
        <p class="ayuda-subtitulo">
          Importa tus archivos de Excel o de tu sistema actual con validación
          automática fila por fila.
        </p>
      </div>
    </div>
    <div class="ayuda-columnas">
      <section aria-labelledby="ayuda-traes">
        <h4 id="ayuda-traes">Lo que traes</h4>
        <ul>
          <li v-for="t in contenido.traes" :key="t">{{ t }}</li>
        </ul>
      </section>
      <section aria-labelledby="ayuda-no">
        <h4 id="ayuda-no">Lo que no se traslada</h4>
        <ul>
          <li v-for="t in contenido.noSeTraslada" :key="t">{{ t }}</li>
        </ul>
      </section>
      <section aria-labelledby="ayuda-configuras">
        <h4 id="ayuda-configuras">Asistente guiado</h4>
        <ul>
          <li v-for="t in contenido.configuras" :key="t">{{ t }}</li>
        </ul>
      </section>
    </div>
    <p class="ayuda-contacto">
      <strong>Te ayudamos a dejarlo listo:</strong>
      <a class="tu-enlace" :href="`mailto:${soporte.correo}`">{{
        soporte.correo
      }}</a
      ><template v-if="whatsapp">
        ·
        <a class="tu-enlace" :href="whatsapp" target="_blank" rel="noopener"
          >WhatsApp</a
        ></template
      >
    </p>
  </div>
</template>

<style scoped>
.ayuda {
  margin-top: 2.25rem;
  padding: 1.35rem 1.5rem;
  border: 1px solid color-mix(in srgb, var(--acento) 26%, var(--borde));
  border-radius: 20px;
  background:
    radial-gradient(
      ellipse 60% 60% at 90% 15%,
      color-mix(in srgb, #3a8fd4 12%, transparent) 0%,
      transparent 70%
    ),
    var(--fondo);
  box-shadow: 0 14px 34px -24px rgb(13 59 110 / 22%);
}
.ayuda-cabecera {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}
.ayuda-titulo {
  font-size: 1.15rem;
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--primario);
}
.ayuda-subtitulo {
  margin-top: 0.25rem;
  max-width: 34rem;
  font-size: 0.85rem;
  color: var(--texto-suave);
}
.ayuda-columnas {
  display: grid;
  gap: 0.85rem;
  margin-top: 1rem;
}
@media (min-width: 900px) {
  .ayuda-columnas {
    grid-template-columns: 1.3fr 0.9fr 1fr;
  }
}
.ayuda-columnas > section {
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 14px;
  background: var(--superficie);
}
.ayuda-columnas h4 {
  font-weight: 600;
  font-size: 0.84rem;
  color: var(--primario);
}
.ayuda-columnas ul {
  margin-top: 0.4rem;
  display: grid;
  gap: 0.28rem;
  padding-left: 1rem;
  list-style: disc;
  font-size: 0.8rem;
  line-height: 1.4;
  color: var(--texto-suave);
}
.ayuda-contacto {
  margin-top: 0.9rem;
  font-size: 0.82rem;
}
</style>
