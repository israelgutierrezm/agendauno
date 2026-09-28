<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";
import { trackEvent } from "@/lib/analytics";
import { bandasEstudios, ejemplosCitas, pesos } from "@/marketing/precios";

const modo = ref<"clases" | "citas">("clases");
const tarjetas = computed(() =>
  modo.value === "clases" ? bandasEstudios.slice(0, 3) : ejemplosCitas,
);
const beneficios = computed(() =>
  modo.value === "clases"
    ? [
        "Agenda de clases y control de cupos",
        "Membresías y paquetes de clases",
        "Reservas en línea para tus alumnos",
        "Asistencia y registro de cobros",
      ]
    : [
        "Agenda y disponibilidad por profesional",
        "Servicios con su duración y precio",
        "Página de reservas para tus clientes",
        "Registro de clientes y cobros",
      ],
);

function elegirModo(valor: "clases" | "citas") {
  modo.value = valor;
  trackEvent("marketing_business_mode_selected", {
    mode: valor,
    placement: "pricing",
  });
}
</script>

<template>
  <div class="precios">
    <div
      class="precios-selector"
      role="group"
      aria-label="Tipo de negocio para consultar precios"
    >
      <button
        type="button"
        :aria-pressed="modo === 'clases'"
        @click="elegirModo('clases')"
      >
        Estudios y academias
      </button>
      <button
        type="button"
        :aria-pressed="modo === 'citas'"
        @click="elegirModo('citas')"
      >
        Citas por profesional
      </button>
    </div>

    <div class="precios-intro" aria-live="polite" aria-atomic="true">
      <template v-if="modo === 'clases'">
        <h3>Por alumnos activos, no por el tamaño de tu directorio.</h3>
        <p>
          Pilates, Pole dance, yoga, acuáticas y academias. Desde
          <strong>{{ pesos(33900) }} MXN/mes + IVA</strong> para 1–49 alumnos
          activos.
        </p>
      </template>
      <template v-else>
        <h3>Una agenda para cada profesional. Una operación conectada.</h3>
        <p>
          Barberías, estéticas, spas y consultorios. Desde
          <strong>{{ pesos(13450) }} MXN/mes + IVA</strong> para un profesional
          de medio tiempo; tiempo completo desde {{ pesos(26900) }} + IVA.
        </p>
      </template>
    </div>

    <div class="precios-tarjetas">
      <article
        v-for="(tarjeta, indice) in tarjetas"
        :key="`${modo}-${indice}`"
        class="precio-tarjeta"
      >
        <p class="precio-contexto">
          {{
            modo === "clases"
              ? "Estudios y academias"
              : "Citas · tiempo completo"
          }}
        </p>
        <h4>{{ tarjeta.capacidad }}</h4>
        <p class="precio-importe">
          <strong>{{ pesos(tarjeta.subtotal) }}</strong
          ><span> MXN / mes</span>
        </p>
        <p class="precio-impuestos">+ IVA</p>
        <ul>
          <li v-for="beneficio in beneficios" :key="beneficio">
            <span aria-hidden="true">✓</span>{{ beneficio }}
          </li>
        </ul>
        <RouterLink
          class="tu-btn tu-btn-primario precio-cta"
          :to="{ name: 'registro' }"
          @click="
            trackEvent('marketing_cta_clicked', {
              placement: 'pricing_card',
              destination: 'register',
              mode: modo,
            })
          "
          >Probar 30 días gratis<span class="sr-only">
            · {{ tarjeta.capacidad }}</span
          ></RouterLink
        >
        <p class="precio-sin-tarjeta">Sin tarjeta para empezar</p>
      </article>
    </div>

    <p class="precios-aclaracion">
      Las mismas herramientas dentro de cada modalidad. Cambia el uso de tu
      negocio, no las funciones incluidas. Importes por un mes completo; el
      cargo depende de la actividad del periodo.
    </p>

    <details :key="modo" class="precios-detalle">
      <summary>
        {{
          modo === "clases"
            ? "Ver todos los rangos y qué cuenta como alumno activo"
            : "Ver cómo crece el precio y las reglas de medio tiempo"
        }}
      </summary>
      <div v-if="modo === 'clases'" class="precios-reglas">
        <table>
          <caption>
            Tarifa mensual para estudios y academias · MXN + IVA
          </caption>
          <thead>
            <tr>
              <th scope="col">Alumnos activos</th>
              <th scope="col">Precio mensual</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="banda in bandasEstudios" :key="banda.capacidad">
              <th scope="row">{{ banda.capacidad }}</th>
              <td>{{ pesos(banda.subtotal) }}</td>
            </tr>
          </tbody>
        </table>
        <p>
          Cuenta una persona con una reserva confirmada en una sesión no
          cancelada o una compra pagada durante el mes. No cuenta solo por estar
          registrada. Se excluyen personas archivadas, no facturables y personal
          del negocio.
        </p>
        <p>
          Se aplica una sola banda a todo el mes, no un precio por cada alumno.
          Sin alumnos activos, la renta por uso es $0.
        </p>
      </div>
      <div v-else class="precios-reglas">
        <table>
          <caption>
            Precio marginal por profesional equivalente · MXN / mes + IVA
          </caption>
          <thead>
            <tr>
              <th scope="col">Tramo</th>
              <th scope="col">Base por equivalente</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <th scope="row">Primero</th>
              <td>$269</td>
            </tr>
            <tr>
              <th scope="row">Segundo</th>
              <td>$226</td>
            </tr>
            <tr>
              <th scope="row">Del 3.º al 10.º</th>
              <td>$135</td>
            </tr>
            <tr>
              <th scope="row">Del 11.º al 20.º</th>
              <td>$89</td>
            </tr>
          </tbody>
        </table>
        <p>
          Los tramos se suman: 2 profesionales cuestan $269 + $226 = $495 antes
          de IVA. El componente por profesionales tiene un tope de
          {{ pesos(246500) }} + IVA al mes, a partir de 20 equivalentes.
        </p>
        <p>
          Cuenta el profesional con al menos una sesión no cancelada en el mes.
          Con un horario configurado mayor a 0 y menor a 20 horas semanales
          cuenta como medio equivalente; con 20 horas o más, o sin horario
          configurado, como uno completo.
        </p>
        <p>
          ¿También das clases o talleres? Cada equivalente incluye 10 personas
          con reservas grupales en el mes (hasta 100 en total). Cada persona
          adicional suma {{ pesos(900) }} + IVA al mes. Este cargo es adicional
          al componente por profesionales y no se aplica a los clientes
          atendidos solo por cita.
        </p>
      </div>
    </details>
    <aside
      v-if="modo === 'clases'"
      class="precios-contacto"
      aria-label="Cotización para más de 2,000 alumnos"
    >
      <div>
        <h4>¿Más de 2,000 alumnos activos?</h4>
        <p>Contáctanos para una propuesta a la medida de tu operación.</p>
      </div>
      <a
        class="tu-btn tu-btn-primario"
        href="mailto:ventas@agendauno.mx?subject=Cotizaci%C3%B3n%20para%20m%C3%A1s%20de%202%2C000%20alumnos"
        >Contáctanos</a
      >
    </aside>
    <p class="precios-aclaracion">
      La suscripción a AgendaUno y los pagos de tus clientes son distintos. Las
      comisiones de una pasarela de pagos, cuando aplique, no están incluidas en
      estos importes.
    </p>
  </div>
</template>

<style scoped>
.precios {
  margin-top: 2.25rem;
}
.precios-contacto {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.5rem;
  margin-top: 1.5rem;
  border: 1px solid var(--borde);
  border-radius: 16px;
  background: var(--superficie);
}
.precios-contacto h4 {
  font-weight: 600;
}
.precios-contacto p {
  margin-top: 0.4rem;
  color: var(--texto-suave);
  font-size: 0.9rem;
}
.precios-contacto a {
  padding: 0.75rem 1.25rem;
}
.precios-selector {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.4rem;
  width: fit-content;
  background: var(--superficie);
  border: 1px solid var(--borde);
  border-radius: 14px;
}
.precios-selector button {
  padding: 0.8rem 1.25rem;
  border-radius: 10px;
  font: inherit;
  font-weight: 500;
  color: var(--texto-suave);
  cursor: pointer;
}
.precios-selector button[aria-pressed="true"] {
  background: var(--primario);
  color: var(--primario-contraste);
}
.precios-selector button:focus-visible,
summary:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 4px;
}
.precios-intro {
  margin: 1.75rem 0;
  max-width: 54rem;
}
.precios-intro h3 {
  font-size: clamp(1.15rem, 2vw, 1.5rem);
  font-weight: 500;
  line-height: 1.4;
}
.precios-intro p {
  color: var(--texto-suave);
  line-height: 1.75;
  margin-top: 0.5rem;
}
.precios-intro strong {
  color: var(--texto);
  font-weight: 500;
}
.precios-tarjetas {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1rem;
}
.precio-tarjeta {
  display: flex;
  flex-direction: column;
  min-width: 0;
  background: var(--superficie);
  border: 1px solid var(--borde);
  border-top: 3px solid var(--primario);
  border-radius: 18px;
  padding: 1.6rem;
}
.precio-contexto {
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.precio-tarjeta h4 {
  margin-top: 0.65rem;
  font-size: 1.15rem;
  font-weight: 500;
}
.precio-importe {
  margin-top: 1.5rem;
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.3rem;
}
.precio-importe strong {
  font-size: clamp(1.8rem, 3vw, 2.5rem);
  font-weight: 500;
  letter-spacing: -0.04em;
  font-variant-numeric: tabular-nums;
}
.precio-importe span,
.precio-impuestos {
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.precio-impuestos {
  margin-top: 0.25rem;
}
.precio-tarjeta ul {
  display: grid;
  gap: 0.8rem;
  margin: 1.5rem 0 2rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--borde);
  font-size: 0.9rem;
  line-height: 1.6;
  flex: 1;
}
.precio-tarjeta li {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
}
.precio-tarjeta li span {
  color: var(--exito);
}
.precio-cta {
  width: 100%;
  padding: 0.8rem 1rem;
  text-align: center;
  justify-content: center;
}
.precio-sin-tarjeta {
  color: var(--texto-suave);
  font-size: 0.75rem;
  text-align: center;
  margin-top: 0.7rem;
}
.precios-aclaracion {
  color: var(--texto-suave);
  font-size: 0.85rem;
  line-height: 1.75;
  margin-top: 1.25rem;
  max-width: 65rem;
}
.precios-detalle {
  margin-top: 1.5rem;
  border: 1px solid var(--borde);
  background: var(--superficie);
  border-radius: 16px;
}
.precios-detalle summary {
  cursor: pointer;
  padding: 1.25rem;
  font-weight: 500;
}
.precios-reglas {
  padding: 0 1.25rem 1.25rem;
  font-size: 0.85rem;
  line-height: 1.7;
}
.precios-reglas p {
  margin-top: 1rem;
  color: var(--texto-suave);
}
table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}
caption {
  text-align: left;
  color: var(--texto-suave);
  padding: 0.5rem 0 1rem;
}
th,
td {
  padding: 0.65rem 0.4rem;
  border-bottom: 1px solid var(--borde);
}
th {
  font-weight: 500;
}
td {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
@media (max-width: 800px) {
  .precios-tarjetas {
    grid-template-columns: 1fr;
  }
  .precios-selector {
    width: 100%;
  }
  .precios-selector button {
    flex: 1;
    padding: 0.75rem 0.5rem;
    font-size: 0.85rem;
  }
  .precio-tarjeta {
    padding: 1.5rem;
  }
  .precios-reglas {
    padding: 0 0.8rem 1rem;
  }
  th,
  td {
    padding: 0.6rem 0.2rem;
    font-size: 0.75rem;
  }
}
</style>
