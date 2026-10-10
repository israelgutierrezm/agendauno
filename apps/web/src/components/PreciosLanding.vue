<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";
import { trackEvent } from "@/lib/analytics";
import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import { funcionesPorNivel } from "@/lib/suscripcion";
import { NOMBRE_MODALIDAD, type Modo } from "@/marketing/modalidades";
import {
  type NivelCitas,
  desdeCitas,
  dolares,
  maxAlumnos,
  rangosClases,
  tablaCitas,
} from "@/marketing/precios";
import { usePreciosPublicos } from "@/marketing/preciosPublicos";

/*
| Precios de la suscripción a AgendaUno (ADR 0107), sin impuestos, tal como los
| publica el superadmin (`GET /api/v1/precios`; mientras llegan, el respaldo de
| `precios.ts`).
| - Sin `modo`: selector clases/citas, como en la portada de siempre.
| - Con `modo` (/clases#precios, /citas#precios): fijo, sin selector, y el registro
|   lleva `?modo=`.
| - Clases: por alumnos activos al mes. Citas: Individual, Premium o Pro por los
|   profesionales que contratas, mensual o anual; qué incluye cada uno sale del
|   reparto de funciones de la tarifa.
| La modalidad se nombra igual que en la portada y el registro (NOMBRE_MODALIDAD). Lo
| que menciona cobros en línea o facturación lleva «*» y la nota de México (ADR 0099).
*/
const props = defineProps<{ modo?: Modo }>();
const precios = usePreciosPublicos();
const elegido = ref<Modo>("clases");
const anual = ref(false);
const fijo = computed(() => props.modo !== undefined);
const modo = computed<Modo>(() => props.modo ?? elegido.value);
// El producto de la modalidad que se ve aún puede no recibir registros (ADR 0108).
const registroAbierto = computed(
  () => precios.datos.registro[productoDeModalidad(modo.value)],
);
const marca = computed(() => PRODUCTOS[productoDeModalidad(modo.value)].nombre);
const registro = computed(() =>
  fijo.value
    ? { name: "registro", query: { modo: modo.value } }
    : { name: "registro" },
);

const rangos = computed(() => rangosClases(precios.datos.clases.bandas));
const topeAlumnos = computed(() => maxAlumnos(precios.datos.clases.bandas));
const tabla = computed(() => tablaCitas(precios.datos.citas.niveles));
const desde = computed(() => desdeCitas(precios.datos.citas.niveles));
const topeProfesionales = computed(
  () => tabla.value[tabla.value.length - 1]?.profesionales ?? null,
);
const mesesAnual = computed(() => precios.datos.citas.meses_anual);
const cortesia = computed(() => Math.max(0, 12 - mesesAnual.value));
const funciones = computed(() =>
  funcionesPorNivel(precios.datos.citas.funciones ?? {}),
);
const monedaClases = computed(() => precios.datos.clases.moneda);
const monedaCitas = computed(() => precios.datos.citas.moneda);
// La moneda y los días de prueba de la modalidad que se ve (los de su tarifa).
const moneda = computed(() => precios.datos[modo.value].moneda);
const diasPrueba = computed(() => precios.datos[modo.value].dias_prueba);
const NOMBRE_MONEDA: Record<string, string> = {
  USD: "dólares estadounidenses",
  MXN: "pesos mexicanos",
};
const nombreMoneda = computed(
  () => NOMBRE_MONEDA[moneda.value] ?? moneda.value,
);

const beneficiosClases = [
  "Agenda de clases y control de cupos",
  "Membresías y paquetes de clases",
  "Reservas en línea para tus alumnos",
  "Asistencia y registro de cobros",
];
const NIVELES: { nivel: NivelCitas; nombre: string }[] = [
  { nivel: "individual", nombre: "Individual" },
  { nivel: "premium", nombre: "Premium" },
  { nivel: "pro", nombre: "Pro" },
];
const tarjetas = computed(() =>
  modo.value === "clases"
    ? rangos.value.slice(0, 3).map((b) => ({
        clave: b.capacidad,
        nombre: b.capacidad,
        capacidad: "",
        desde: false,
        importe: b.subtotal,
        periodo: `${monedaClases.value} / mes`,
        nota: "",
        funciones: beneficiosClases,
      }))
    : NIVELES.map((n) => ({
        clave: n.nivel,
        nombre: n.nombre,
        capacidad:
          n.nivel === "individual"
            ? "1 profesional"
            : `Desde ${tabla.value[0]?.profesionales ?? 2} profesionales`,
        desde: n.nivel !== "individual",
        importe: anual.value
          ? desde.value[n.nivel] * mesesAnual.value
          : desde.value[n.nivel],
        periodo: `${monedaCitas.value} / ${anual.value ? "año" : "mes"}`,
        nota:
          n.nivel === "individual" ? "" : "por los profesionales que contratas",
        funciones: funciones.value[n.nivel],
      })),
);
const ventasCorreo = computed(
  () => precios.datos.ventas.correo ?? "ventas@agendauno.mx",
);
const ventasWhatsApp = computed(() => {
  const numero = String(
    precios.datos.ventas.whatsapp ?? import.meta.env.VITE_VENTAS_WHATSAPP ?? "",
  ).replace(/\D/g, "");
  return numero === "" ? null : `https://wa.me/${numero}`;
});

// Sin registro abierto, las tarjetas llevan a la lista de interesados: `waitlist`.
function medirTarjeta(): void {
  trackEvent("marketing_cta_clicked", {
    placement: "pricing_card",
    destination: registroAbierto.value ? "register" : "waitlist",
    mode: modo.value,
  });
}

function elegirModo(valor: Modo) {
  elegido.value = valor;
  trackEvent("marketing_business_mode_selected", {
    mode: valor,
    placement: "pricing",
  });
}
</script>

<template>
  <div class="precios">
    <p v-if="fijo" class="precios-modelo">
      <span class="precios-modelo-titulo">{{ NOMBRE_MODALIDAD[modo] }}</span
      >{{ " · "
      }}<strong>{{
        modo === "clases" ? "Por alumnos activos" : "Por plan y profesionales"
      }}</strong>
    </p>
    <div
      v-else
      class="precios-selector"
      role="group"
      aria-label="Tipo de negocio para consultar precios"
    >
      <button
        type="button"
        :aria-pressed="modo === 'clases'"
        @click="elegirModo('clases')"
      >
        <span class="precios-modelo-titulo">{{ NOMBRE_MODALIDAD.clases }}</span>
        <strong>Por alumnos activos</strong>
        <span class="precios-modelo-negocios">
          Pilates, Pole dance, yoga, natación, baile y CrossFit / HYROX.
        </span>
      </button>
      <button
        type="button"
        :aria-pressed="modo === 'citas'"
        @click="elegirModo('citas')"
      >
        <span class="precios-modelo-titulo">{{ NOMBRE_MODALIDAD.citas }}</span>
        <strong>Por plan y profesionales</strong>
        <span class="precios-modelo-negocios">
          Barberías, estéticas, spas, psicólogos, dentistas y nutriólogos.
        </span>
      </button>
    </div>

    <div class="precios-intro" aria-live="polite" aria-atomic="true">
      <template v-if="modo === 'clases'">
        <h3>Tu comunidad crece. Tu plan la acompaña.</h3>
        <p>
          Organiza tus clases, cupos y membresías desde
          <strong
            >{{ dolares(rangos[0]?.subtotal ?? 0) }} {{ monedaClases }} al
            mes</strong
          >
          para {{ rangos[0]?.capacidad.toLowerCase() ?? "" }}.
        </p>
      </template>
      <template v-else>
        <h3>Tu agenda, a solas o con todo tu equipo.</h3>
        <p>
          Organiza servicios, disponibilidad y reservas desde
          <strong
            >{{ dolares(desde.individual) }} {{ monedaCitas }} al mes</strong
          >
          para un profesional. Con tu equipo, eliges Premium o Pro por los
          profesionales que contratas.
        </p>
        <div
          class="tu-segmentado precios-periodo"
          role="group"
          aria-label="Pago mensual o anual"
        >
          <button type="button" :aria-pressed="!anual" @click="anual = false">
            Mensual
          </button>
          <button type="button" :aria-pressed="anual" @click="anual = true">
            Anual<template v-if="cortesia > 0">
              · {{ cortesia }} {{ cortesia === 1 ? "mes" : "meses" }} de
              cortesía</template
            >
          </button>
        </div>
        <p class="precios-periodo-nota" data-prueba="anual-adelantado">
          El plan anual se paga completo por adelantado: {{ mesesAnual }} meses
          por 12 de servicio.
        </p>
      </template>
    </div>

    <div class="precios-tarjetas">
      <article
        v-for="tarjeta in tarjetas"
        :key="`${modo}-${tarjeta.clave}`"
        class="precio-tarjeta"
      >
        <p class="precio-contexto">{{ NOMBRE_MODALIDAD[modo] }}</p>
        <h4>{{ tarjeta.nombre }}</h4>
        <p v-if="tarjeta.capacidad" class="precio-capacidad">
          {{ tarjeta.capacidad }}
        </p>
        <p class="precio-importe">
          <span v-if="tarjeta.desde">Desde</span>
          <strong>{{ dolares(tarjeta.importe) }}</strong
          ><span> {{ tarjeta.periodo }}</span>
        </p>
        <p class="precio-impuestos">
          Más impuestos<template v-if="tarjeta.nota">
            · {{ tarjeta.nota }}</template
          >
        </p>
        <ul>
          <li v-for="beneficio in tarjeta.funciones" :key="beneficio">
            <svg
              class="precio-check"
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
            >{{ beneficio }}
          </li>
        </ul>
        <RouterLink
          class="tu-btn tu-btn-primario tu-btn-azul precio-cta"
          :to="registro"
          @click="medirTarjeta"
          >{{
            !registroAbierto
              ? "Quiero que me avisen"
              : diasPrueba > 0
                ? `Probar ${diasPrueba} días gratis`
                : "Crear mi cuenta"
          }}<span class="sr-only"> · {{ tarjeta.nombre }}</span></RouterLink
        >
        <p class="precio-sin-tarjeta">
          {{ registroAbierto ? "Sin tarjeta para empezar" : "Próximamente" }}
        </p>
      </article>
    </div>

    <p class="precios-aclaracion">
      Precios en {{ nombreMoneda }}, más impuestos.
      <template v-if="moneda !== 'MXN'">
        En México se cobran en pesos al tipo de cambio del día del cobro, más
        IVA.
      </template>
      <template v-if="modo === 'clases'">
        Todas las herramientas para clases, desde el primer plan; la tarifa
        mensual depende de los alumnos activos de tu negocio.
      </template>
      <template v-else>
        <template v-if="registroAbierto"
          >En la prueba gratis tienes todo lo de Pro.</template
        >
        Subir de plan se cobra al momento por los días que faltan; el anual
        cuesta {{ mesesAnual }} meses y se paga por adelantado.
      </template>
    </p>

    <details :key="modo" class="precios-detalle">
      <summary>
        {{
          modo === "clases"
            ? "Ver todos los rangos y qué cuenta como alumno activo"
            : "Ver el precio según tu número de profesionales"
        }}
      </summary>
      <div v-if="modo === 'clases'" class="precios-reglas">
        <table>
          <caption>
            Tarifa mensual para estudios y academias ·
            {{
              monedaClases
            }}
            más impuestos
          </caption>
          <thead>
            <tr>
              <th scope="col">Alumnos activos</th>
              <th scope="col">Precio mensual</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="banda in rangos" :key="banda.capacidad">
              <th scope="row">{{ banda.capacidad }}</th>
              <td>{{ dolares(banda.subtotal) }}</td>
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
          Sin alumnos activos, la suscripción de ese mes es de
          {{ dolares(0) }} {{ monedaClases }}. Se cobra al cerrar el mes.
        </p>
      </div>
      <div v-else class="precios-reglas">
        <table>
          <caption>
            Precio mensual por profesionales contratados ·
            {{
              monedaCitas
            }}
            más impuestos
          </caption>
          <thead>
            <tr>
              <th scope="col">Profesionales</th>
              <th scope="col">Premium</th>
              <th scope="col">Pro</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <th scope="row">1 (Individual)</th>
              <td colspan="2">{{ dolares(desde.individual) }}</td>
            </tr>
            <tr v-for="fila in tabla" :key="fila.profesionales">
              <th scope="row">{{ fila.profesionales }}</th>
              <td>{{ dolares(fila.premium) }}</td>
              <td>{{ dolares(fila.pro) }}</td>
            </tr>
          </tbody>
        </table>
        <p>
          Pagas por los profesionales que contratas, por adelantado. Puedes
          cambiar de plan cuando quieras: subir se cobra al momento por los días
          que faltan y bajar aplica desde el siguiente periodo.
        </p>
      </div>
    </details>
    <aside
      class="precios-contacto"
      :aria-label="
        modo === 'clases'
          ? `Cotización para más de ${(topeAlumnos ?? 0).toLocaleString('es-MX')} alumnos`
          : `Cotización para más de ${topeProfesionales ?? 0} profesionales`
      "
    >
      <div>
        <h4>
          {{
            modo === "clases"
              ? `¿Más de ${(topeAlumnos ?? 0).toLocaleString("es-MX")} alumnos activos?`
              : `¿Más de ${topeProfesionales ?? 0} profesionales?`
          }}
        </h4>
        <p>Contáctanos para una propuesta a la medida de tu operación.</p>
      </div>
      <div class="precios-contacto-acciones">
        <a
          v-if="ventasWhatsApp"
          class="tu-btn tu-btn-fantasma"
          :href="ventasWhatsApp"
          target="_blank"
          rel="noopener"
          >WhatsApp</a
        >
        <!-- Azul, como las tarjetas: el rosa queda para el menú y el cierre. -->
        <a
          class="tu-btn tu-btn-primario tu-btn-azul"
          :href="`mailto:${ventasCorreo}?subject=${encodeURIComponent(`Cotización ${marca}`)}`"
          >Contáctanos</a
        >
      </div>
    </aside>
    <p class="precios-aclaracion">
      {{
        !fijo
          ? "Tú pones el valor a tus servicios, clases y paquetes."
          : modo === "clases"
            ? "Tú pones el precio de tus clases, paquetes y membresías."
            : "Tú pones el precio de tus servicios y paquetes."
      }}
      {{ marca }} te ayuda a ofrecerlos y gestionar sus cobros. Las comisiones
      del proveedor de pagos en línea* no están incluidas en la suscripción.
    </p>
    <!-- «Pagos en línea*»: solo en México (ADR 0099), como landing.soloMexico. -->
    <p class="tu-nota-mexico">
      * Solo para negocios en México (cobros en pesos).
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
.precios-contacto-acciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.precios-periodo {
  margin-top: 1rem;
}
.precios-periodo-nota {
  margin-top: 0.6rem;
  color: var(--texto-suave);
  font-size: 0.82rem;
}
.precio-capacidad {
  margin-top: 0.25rem;
  color: var(--texto-suave);
  font-size: 0.85rem;
}
.precios-modelo {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.35rem 0.75rem;
}
.precios-modelo strong {
  font-size: clamp(1.05rem, 2vw, 1.25rem);
  font-weight: 500;
}
.precios-selector {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}
.precios-selector button {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.45rem;
  padding: 1.25rem 1.5rem;
  border: 2px solid var(--borde);
  border-radius: 16px;
  background: var(--superficie);
  text-align: left;
  font: inherit;
  color: var(--texto);
  cursor: pointer;
}
.precios-selector button[aria-pressed="true"] {
  border-color: var(--primario);
  background: color-mix(in srgb, var(--primario) 8%, var(--superficie));
}
.precios-modelo-titulo {
  font-size: 0.85rem;
  color: var(--texto-suave);
}
.precios-selector strong {
  font-size: clamp(1.05rem, 2vw, 1.25rem);
  font-weight: 600;
}
.precios-modelo-negocios {
  font-size: 0.85rem;
  line-height: 1.6;
  color: var(--texto-suave);
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
/* Borde de arriba rosa y botón azul (`tu-btn-azul`): al revés que el hero. */
.precio-tarjeta {
  display: flex;
  flex-direction: column;
  min-width: 0;
  background: var(--superficie);
  border: 1px solid var(--borde);
  border-top: 3px solid var(--marketing-cta);
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
  align-items: flex-start;
  gap: 0.6rem;
}
/* Marca de verificación en SVG, sin «✓» de texto. */
.precio-check {
  flex: 0 0 auto;
  margin-top: 0.25rem;
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
    padding: 1rem;
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
@media (max-width: 540px) {
  .precios-selector {
    grid-template-columns: 1fr;
  }
}
</style>
