<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";
import SolucionesEnlaces from "@/components/SolucionesEnlaces.vue";
import {
  ETIQUETA_MENU,
  NOMBRE_MODALIDAD,
  perfilDeSolucion,
  rutaModalidad,
} from "@/marketing/modalidades";
import { soluciones } from "@/marketing/soluciones";
import { trackEvent } from "@/lib/analytics";
const props = defineProps<{ slug: string }>();
const solucion = computed(() => soluciones.find((s) => s.slug === props.slug)!);
// Cada giro es de una sola modalidad (ADR 0104): su página, sus anclas y el registro
// con su `?modo=` (solo se ven sus giros). Si la página es de un solo giro del
// registro, también lo manda (`?giro=`) y el registro llega con él elegido.
const modo = computed(() => solucion.value.modo);
const giro = computed(() => perfilDeSolucion(props.slug));
const esClases = computed(() => modo.value === "clases");
const paginaModalidad = computed(() => rutaModalidad(modo.value));
const registro = computed(() => ({
  name: "registro",
  query:
    giro.value === null
      ? { modo: modo.value }
      : { modo: modo.value, giro: giro.value },
}));
function medir(placement: string): void {
  trackEvent("marketing_cta_clicked", {
    placement,
    destination: "register",
    solution: props.slug,
    mode: modo.value,
    ...(giro.value === null ? {} : { business_profile: giro.value }),
  });
}
</script>

<template>
  <article class="solucion">
    <section class="solucion-hero">
      <div>
        <nav class="solucion-miga" aria-label="Ruta de navegación">
          <ol>
            <li><RouterLink class="tu-enlace" to="/">AgendaUno</RouterLink></li>
            <li>
              <RouterLink
                class="tu-enlace"
                :to="paginaModalidad"
                data-prueba="enlace-modalidad"
                >{{ ETIQUETA_MENU[modo] }}</RouterLink
              >
            </li>
            <li aria-current="page">{{ solucion.nombre }}</li>
          </ol>
        </nav>
        <p class="solucion-etiqueta">
          {{ esClases ? "Software de reservas para" : "Agenda de citas para" }}
          {{ solucion.nombre }}
        </p>
        <h1>{{ solucion.encabezado }}</h1>
        <p class="solucion-resumen">{{ solucion.resumen }}</p>
        <!-- El botón del hero va en azul; el rosa queda para el menú y el cierre. -->
        <RouterLink
          class="tu-btn tu-btn-primario tu-btn-azul px-7 py-3"
          :to="registro"
          data-cta="hero"
          @click="medir('solution_hero')"
          >Probar gratis <span aria-hidden="true">↗</span></RouterLink
        >
        <p class="solucion-confianza">30 días para probarlo · Sin tarjeta</p>
        <RouterLink
          class="tu-enlace text-sm"
          :to="`${paginaModalidad}#producto`"
          >Ver la agenda en acción</RouterLink
        >
      </div>
      <figure class="solucion-foto">
        <img
          :src="`/assets/landing/disciplinas/${solucion.imagen}`"
          :alt="solucion.alt"
          width="800"
          height="900"
          fetchpriority="high"
        />
        <figcaption class="solucion-reserva">
          <span class="solucion-hora">10:30</span>
          <div>
            <small>Ejemplo de {{ esClases ? "clase" : "cita" }}</small
            ><strong>{{ solucion.ejemplo }}</strong
            ><span>Tu día, a la vista.</span>
          </div>
        </figcaption>
      </figure>
    </section>
    <section class="solucion-bloque" aria-labelledby="beneficios-titulo">
      <p class="solucion-etiqueta">Menos pendientes, más claridad</p>
      <h2 id="beneficios-titulo">
        Lo que tu {{ esClases ? "academia" : "negocio" }} necesita para
        organizar su día.
      </h2>
      <div class="solucion-beneficios">
        <div
          v-for="beneficio in solucion.beneficios"
          :key="beneficio.titulo"
          class="tu-card p-6"
        >
          <h3>{{ beneficio.titulo }}</h3>
          <p>{{ beneficio.texto }}</p>
        </div>
      </div>
    </section>
    <section class="solucion-bloque solucion-empezar">
      <div>
        <p class="solucion-etiqueta">Del registro a tu primera reserva</p>
        <h2>Pruébalo con la forma en que trabajas.</h2>
        <!-- Clases: registro cerrado (ADR 0093), las cuentas las da el negocio.
             Citas: el cliente agenda desde la página sin cuenta. -->
        <p v-if="esClases">
          Crea tu negocio, activa tu cuenta y configura una clase con horario,
          instructor y cupo. Después da de alta a tus alumnos, invítalos a su
          cuenta y comparte tu enlace con tus horarios.
        </p>
        <p v-else>
          Crea tu negocio, activa tu cuenta y configura un servicio con
          duración, profesional y disponibilidad. Después comparte tu enlace
          para que tus clientes elijan servicio, profesional y horario.
        </p>
      </div>
      <div class="tu-card p-6" data-prueba="solucion-precio">
        <h3>Una prueba con tu operación real</h3>
        <p>
          En {{ NOMBRE_MODALIDAD[modo] }}, la suscripción se cobra
          {{
            esClases
              ? "por rango de alumnos activos al mes."
              : "por profesional activo al mes."
          }}
          Consulta las tarifas vigentes antes de contratar; los importes son más
          IVA.
        </p>
        <p class="solucion-enlaces">
          <RouterLink class="tu-enlace" :to="`${paginaModalidad}#precios`"
            >Conocer precios y condiciones →</RouterLink
          >
          <RouterLink class="tu-enlace" :to="paginaModalidad"
            >Todo lo que incluye {{ NOMBRE_MODALIDAD[modo] }} →</RouterLink
          >
        </p>
      </div>
    </section>
    <section
      class="solucion-bloque solucion-preguntas"
      aria-labelledby="preguntas-titulo"
    >
      <h2 id="preguntas-titulo">Antes de empezar</h2>
      <details
        v-for="pregunta in solucion.preguntas"
        :key="pregunta.pregunta"
        class="tu-card p-5"
      >
        <summary>{{ pregunta.pregunta }}</summary>
        <p>{{ pregunta.respuesta }}</p>
      </details>
    </section>
    <section class="solucion-bloque solucion-cierre">
      <h2>Tu próxima reserva empieza con una agenda más clara.</h2>
      <p>
        Configura tu negocio y comprueba si AgendaUno encaja con tu operación.
      </p>
      <RouterLink
        class="tu-btn tu-btn-primario px-7 py-3"
        :to="registro"
        @click="medir('solution_final')"
        >Probar gratis durante 30 días</RouterLink
      >
    </section>
    <section class="solucion-bloque">
      <h2>Otras formas de trabajar con AgendaUno</h2>
      <!-- Cada giro es de una sola modalidad: los demás, en «Clases» y «Citas». -->
      <SolucionesEnlaces dos-columnas :excluir="solucion.slug" />
    </section>
  </article>
</template>

<style scoped>
.solucion {
  max-width: 80rem;
  margin: auto;
  padding-inline: clamp(1rem, 4vw, 3rem);
}
.solucion-hero {
  display: grid;
  grid-template-columns: 1.1fr 1fr;
  gap: clamp(2rem, 5vw, 5rem);
  align-items: center;
  padding-block: 4rem 5rem;
}
.solucion-etiqueta {
  color: var(--enlace);
  font-size: 0.8rem;
  font-weight: 600;
  margin: 1.5rem 0 1rem;
}
h1 {
  font-size: clamp(1.85rem, 3.5vw, 3.2rem);
  font-weight: 300;
  line-height: 1.12;
  text-wrap: balance;
}
h2 {
  font-size: clamp(1.4rem, 2.6vw, 2.1rem);
  font-weight: 300;
  line-height: 1.2;
  text-wrap: balance;
  max-width: 45rem;
}
h3 {
  font-weight: 500;
  font-size: 1.05rem;
}
.solucion-miga ol {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.875rem;
  color: var(--texto-suave);
}
.solucion-miga li + li::before {
  content: "/";
  content: "/" / "";
  margin-right: 0.35rem;
}
.solucion-bloque p.solucion-enlaces {
  display: grid;
  justify-items: start;
  gap: 0.5rem;
  margin-bottom: 0;
}
.solucion-resumen {
  font-size: 1.05rem;
  line-height: 1.8;
  color: var(--texto-suave);
  margin: 1.5rem 0 2rem;
}
.solucion-confianza {
  font-size: 0.8rem;
  color: var(--texto-suave);
  margin: 1rem 0;
}
.solucion-foto {
  position: relative;
  margin: 0 0 2rem;
}
.solucion-foto > img {
  width: 100%;
  aspect-ratio: 4 / 4.2;
  object-fit: cover;
  border-radius: var(--radio-imagen, 22px);
}
.solucion-reserva {
  position: absolute;
  bottom: -1.5rem;
  left: -1rem;
  right: 1.5rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.25rem;
  border-radius: 1.2rem;
  background: var(--superficie);
  border: 1px solid var(--borde);
  box-shadow: 0 14px 35px #031b4e18;
}
.solucion-reserva div {
  display: grid;
  gap: 0.2rem;
}
.solucion-reserva small,
.solucion-reserva div > span {
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.solucion-hora {
  padding: 0.75rem;
  border-radius: 0.8rem;
  background: var(--primario-suave);
  color: var(--enlace);
  font-weight: 600;
}
.solucion-bloque {
  padding-block: 3.5rem;
  border-top: 1px solid var(--borde);
}
.solucion-beneficios {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1.2rem;
  margin-top: 2rem;
}
.solucion-bloque p:not(.solucion-etiqueta) {
  color: var(--texto-suave);
  margin-block: 1rem;
  line-height: 1.8;
}
.solucion-empezar {
  display: grid;
  grid-template-columns: 1.3fr 1fr;
  gap: 3rem;
  align-items: center;
}
.solucion-preguntas details {
  margin-top: 1rem;
}
.solucion-preguntas summary {
  font-weight: 600;
  cursor: pointer;
}
.solucion-cierre {
  text-align: center;
}
.solucion-cierre h2 {
  margin-inline: auto;
}
@media (max-width: 767px) {
  .solucion-hero,
  .solucion-beneficios,
  .solucion-empezar {
    grid-template-columns: 1fr;
  }
  .solucion-hero {
    padding-top: 2rem;
  }
  .solucion-foto {
    max-width: 30rem;
    margin: 0 auto 1rem;
    width: 100%;
  }
  .solucion-reserva {
    left: 0.5rem;
    right: 0.5rem;
  }
  .solucion-bloque {
    padding-block: 2.5rem;
  }
}
</style>
