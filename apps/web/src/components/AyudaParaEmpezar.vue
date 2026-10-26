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
      "Tus alumnos, con sus datos de contacto",
      "Tu equipo de instructores",
      "Tus clases y los horarios que se repiten",
      "Los planes y créditos vigentes de cada alumno, a la fecha de corte que elijas (sin registrarlos como ventas)",
      "Las reservas que ya tenían en tus próximas clases, con o sin avisarles",
    ],
    noSeTraslada: [
      "El historial de pagos y asistencias anteriores: queda en tu sistema anterior",
    ],
    configuras: [
      "Tu sucursal y su horario",
      "Tus clases, cupos y horarios",
      "Tus planes y paquetes",
      "Tu equipo y tu página",
    ],
  },
  citas: {
    traes: [
      "Tus clientes, con sus datos de contacto",
      "Tu equipo",
      "Los bonos vigentes de cada cliente, a la fecha de corte que elijas (sin registrarlos como ventas)",
      "Las citas que ya tenías agendadas, sin avisarle nada a tus clientes",
    ],
    noSeTraslada: [
      "El historial de pagos y citas anteriores: queda en tu sistema anterior",
    ],
    configuras: [
      "Tu sucursal",
      "Tus servicios, con su duración y precio",
      "Los horarios de atención de cada profesional",
      "Tu equipo y tu página para agendar",
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
    <h3 class="ayuda-titulo">Cámbiate sin empezar de cero</h3>
    <p class="ayuda-subtitulo">
      Trae lo que ya tienes en archivos de Excel o de tu sistema actual. Antes
      de guardar revisamos cada archivo y te decimos qué filas están listas,
      cuáles ya existían y cuáles hay que corregir.
    </p>
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
        <h4 id="ayuda-configuras">El asistente de inicio te guía con</h4>
        <ul>
          <li v-for="t in contenido.configuras" :key="t">{{ t }}</li>
        </ul>
      </section>
    </div>
    <p class="ayuda-contacto">
      <strong>Te ayudamos a dejarlo listo.</strong>
      Escríbenos a
      <a class="tu-enlace" :href="`mailto:${soporte.correo}`">{{
        soporte.correo
      }}</a
      ><template v-if="whatsapp">
        o por
        <a class="tu-enlace" :href="whatsapp" target="_blank" rel="noopener"
          >WhatsApp</a
        ></template
      >: te ayudamos a configurar tu negocio y a traer tus datos de otro
      sistema.
    </p>
  </div>
</template>

<style scoped>
.ayuda {
  margin-top: 3rem;
  padding: 1.75rem;
  border: 1px solid var(--borde);
  border-radius: 18px;
  background: var(--fondo);
}
.ayuda-titulo {
  font-size: 1.35rem;
  font-weight: 600;
  letter-spacing: -0.01em;
}
.ayuda-subtitulo {
  margin-top: 0.5rem;
  max-width: 46rem;
  color: var(--texto-suave);
}
.ayuda-columnas {
  display: grid;
  gap: 1.5rem;
  margin-top: 1.5rem;
}
@media (min-width: 900px) {
  .ayuda-columnas {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
.ayuda-columnas h4 {
  font-weight: 600;
  font-size: 0.95rem;
}
.ayuda-columnas ul {
  margin-top: 0.5rem;
  display: grid;
  gap: 0.4rem;
  padding-left: 1.1rem;
  list-style: disc;
  font-size: 0.9rem;
  color: var(--texto-suave);
}
.ayuda-contacto {
  margin-top: 1.5rem;
  font-size: 0.95rem;
}
</style>
