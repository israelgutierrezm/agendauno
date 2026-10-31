<script setup lang="ts">
import { computed, ref, useId } from "vue";
import IconoNav from "@/components/IconoNav.vue";
import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import type { Modo } from "@/marketing/modalidades";

const props = defineProps<{ modo: Modo }>();
const marca = computed(() => PRODUCTOS[productoDeModalidad(props.modo)].nombre);
const abierto = ref<string | null>(null);
const id = useId();
const modulos = computed(() => {
  const clases = props.modo === "clases";
  return [
    {
      clave: "agenda",
      nombre: "Agenda",
      icono: "agenda",
      color: "azul",
      vista: clases ? "8/8 lugares · Lista activa" : "3 horarios libres hoy",
      promesa: clases
        ? "Cupos y horarios bajo control."
        : "Tu agenda siempre en orden.",
      texto: clases
        ? "Semana, reservas y pase de lista en vivo."
        : "Servicios y horarios en una sola vista.",
      puntos: clases
        ? [
            "Clases recurrentes, instructores y salas",
            "Cupos, reservas y lista de espera",
            "Pase de lista y seguimiento de asistencia",
          ]
        : [
            "Servicios con su precio y duración",
            "Horarios y disponibilidad por profesional",
            "Reservas desde tu enlace, sin crear una cuenta",
          ],
      condicion: clases
        ? "Para clases con cupo y horarios definidos."
        : "Agenda individual o de equipo, según tu plan.",
    },
    {
      clave: "cobros",
      nombre: "Cobros",
      icono: "pasarelas",
      color: "menta",
      vista: "Efectivo · Tarjeta · Corte cuadrado",
      promesa: "Del pago al corte sin fricción.",
      texto: clases
        ? "Cada cobro ligado al plan del alumno."
        : "Anticipos, pagos y saldos al día.",
      puntos: [
        "Registro de pagos y saldos pendientes",
        "Caja, arqueos y devoluciones",
        "Pagos en línea con tu pasarela compatible*",
      ],
      condicion:
        "* Los cobros en línea están disponibles para negocios en México. Aplican las comisiones del procesador de pagos.",
    },
    {
      clave: "personas",
      nombre: clases ? "Alumnos" : "Clientes",
      icono: "miembros",
      color: "rosa",
      vista: clases
        ? "Paquete 8 clases · 6 créditos"
        : "Ficha + historial de citas",
      promesa: clases
        ? "Ficha e historial al instante."
        : "Conoce a quien vuelve.",
      texto: clases
        ? "Créditos, vigencias y asistencias en un lugar."
        : "Contacto, notas, bonos e historial a mano.",
      puntos: clases
        ? [
            "Membresías y paquetes de clases",
            "Créditos disponibles y vigencias",
            "Historial de reservas y asistencias",
          ]
        : [
            "Datos de contacto e historial de citas",
            "Notas y fichas de datos",
            "Bonos y paquetes, en los planes que los incluyen",
          ],
      condicion: clases
        ? "Cada reserva respeta las reglas del plan del alumno."
        : "Consulta el alcance de Individual, Premium y Pro en precios.",
    },
    {
      clave: "equipo",
      nombre: "Equipo",
      icono: "personas",
      color: "azul",
      vista: clases ? "Permisos por rol · Nómina" : "Horarios · Comisiones",
      promesa: "Tu equipo en sincronía.",
      texto: clases
        ? "Recepción, administración y coaches conectados."
        : "Recepción y agenda coordinadas en vivo.",
      puntos: clases
        ? [
            "Asignación de instructores a las clases",
            "Accesos según roles y permisos",
            "Seguimiento de clases y pagos a coaches",
          ]
        : [
            "Servicios y horarios de cada profesional",
            "Accesos según roles y permisos",
            "Comisiones por profesional en Premium y Pro",
          ],
      condicion: clases
        ? "Cada persona accede a lo que necesita para su trabajo."
        : "Para negocios con equipo, disponible en los planes correspondientes.",
    },
    {
      clave: "sitio",
      nombre: "Sitio web",
      icono: "contenido",
      color: "rosa",
      vista: "Enlace listo para Instagram y QR",
      promesa: "Tu enlace listo para reservar.",
      texto: clases
        ? "Horarios, planes y sucursales con tu marca."
        : "Servicios, precios y horarios en segundos.",
      puntos: clases
        ? [
            "Horario, planes, instructores y sucursales",
            "Acceso a reservas para tus alumnos",
            "Sitio personalizable y dominio propio opcional",
          ]
        : [
            "Servicios, profesionales y sucursales",
            "Enlace directo para reservar",
            "Personalización avanzada y dominio en Premium y Pro",
          ],
      condicion: clases
        ? "Desde una página de reservas hasta un sitio con más contenido."
        : "La página de reservas es el punto de partida; el alcance adicional depende del plan.",
    },
    {
      clave: "crecimiento",
      nombre: "Crecimiento",
      icono: "reportes",
      color: "menta",
      vista: "Ingresos · Asistencia · Retención",
      promesa: "Tus métricas en gráficas claras.",
      texto: clases
        ? "Ingresos, renovaciones y horarios clave."
        : "Ingresos, regresos y campañas en vivo.",
      puntos: clases
        ? [
            "Reportes de operación y cobros",
            "Seguimiento de asistencia y renovaciones",
            "Campañas, promociones y prospectos",
          ]
        : [
            "Reportes de operación y cobros",
            "Seguimiento del regreso de clientes",
            "Campañas y captación en los planes que las incluyen",
          ],
      condicion: clases
        ? "Convierte la información de tu operación en acciones de seguimiento."
        : "Las herramientas avanzadas de captación y seguimiento dependen del plan.",
    },
  ];
});
</script>

<template>
  <div class="modulos-catalogo" data-prueba="modulos-landing">
    <div class="modulos-grid">
      <article
        v-for="(modulo, i) in modulos"
        :key="modulo.clave"
        class="modulo"
        :class="[
          `modulo-${modulo.color}`,
          { 'modulo-abierto': abierto === modulo.clave },
        ]"
        data-scroll="acomodo"
        :style="{
          '--desplaza-x': i % 3 === 0 ? '-28px' : i % 3 === 2 ? '28px' : '0px',
          '--desplaza-y': i < 3 ? '24px' : '38px',
        }"
      >
        <div class="modulo-marca">
          <svg
            v-if="modulo.clave === 'agenda'"
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <!-- Cuaderno/calendario geométrico de agenda con lomo marino, anillas y bloque de reserva ámbar -->
            <rect x="7" y="10" width="34" height="32" rx="7" fill="#268EF0" />
            <path
              d="M7 17C7 13.134 10.134 10 14 10H34C37.866 10 41 13.134 41 17V19H7V17Z"
              fill="#0D3B6E"
            />
            <!-- Anillas de encuadernado de agenda -->
            <rect x="14" y="6" width="4" height="8" rx="2" fill="#0D3B6E" />
            <rect x="30" y="6" width="4" height="8" rx="2" fill="#0D3B6E" />
            <rect x="15" y="7" width="2" height="4" rx="1" fill="#3A8FD4" />
            <rect x="31" y="7" width="2" height="4" rx="1" fill="#3A8FD4" />
            <!-- Celdas de cuadrícula de agenda -->
            <rect
              x="12"
              y="24"
              width="6"
              height="5"
              rx="1.5"
              fill="#FFFFFF"
              fill-opacity="0.85"
            />
            <rect
              x="21"
              y="24"
              width="6"
              height="5"
              rx="1.5"
              fill="#FFFFFF"
              fill-opacity="0.85"
            />
            <rect
              x="30"
              y="24"
              width="6"
              height="5"
              rx="1.5"
              fill="#FFFFFF"
              fill-opacity="0.85"
            />
            <rect
              x="12"
              y="32"
              width="6"
              height="5"
              rx="1.5"
              fill="#FFFFFF"
              fill-opacity="0.85"
            />
            <!-- Bloque de cita/reserva destacada en ámbar -->
            <rect x="21" y="32" width="15" height="5" rx="2" fill="#F5B825" />
          </svg>
          <svg
            v-else-if="modulo.clave === 'cobros'"
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <!-- Pilar verde esmeralda sobre base trapezoidal ámbar con intersección oliva -->
            <rect x="14" y="6" width="20" height="28" rx="5" fill="#22B573" />
            <path
              d="M9.5 23C10.3284 21.3431 12.0219 20.3 13.8744 20.3H34.1256C35.9781 20.3 37.6716 21.3431 38.5 23L42.2 35.2C43.1889 38.4633 40.7478 42 37.3372 42H10.6628C7.2522 42 4.81112 38.4633 5.8 35.2L9.5 23Z"
              fill="#F5B825"
            />
            <path
              d="M14 20.3H34V29C34 31.7614 31.7614 34 29 34H19C16.2386 34 14 31.7614 14 29V20.3Z"
              fill="#659E38"
            />
          </svg>
          <svg
            v-else-if="modulo.clave === 'personas'"
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <!-- Dos esferas orgánicas entrelazadas (menta + azul cielo + lente cobalto) -->
            <circle cx="16.5" cy="27.5" r="11.5" fill="#32C481" />
            <circle cx="30.5" cy="24.5" r="14.5" fill="#268EF0" />
            <path
              d="M28 27.5C28 32.1846 25.1995 36.2145 21.181 38.0124C17.8012 35.4024 15.625 31.3095 15.625 26.7C15.625 21.662 18.2348 17.2369 22.1765 14.7037C25.7369 17.523 28 22.2156 28 27.5Z"
              fill="#1155A8"
            />
          </svg>
          <svg
            v-else-if="modulo.clave === 'equipo'"
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <!-- Engranaje/red de equipo en ámbar con arco violeta y nodo coral -->
            <circle
              cx="23"
              cy="25"
              r="11.5"
              stroke="#F6B924"
              stroke-width="6.5"
            />
            <circle cx="23" cy="8.5" r="3.8" fill="#F6B924" />
            <circle cx="8.5" cy="20" r="3.8" fill="#F6B924" />
            <circle cx="11.5" cy="36.5" r="3.8" fill="#F6B924" />
            <circle cx="27.5" cy="40.5" r="3.8" fill="#F6B924" />
            <path
              d="M23 13.5C29.3513 13.5 34.5 18.6487 34.5 25C34.5 28.05 33.312 30.8225 31.3728 32.881"
              stroke="#9864E2"
              stroke-width="6.5"
              stroke-linecap="round"
            />
            <circle cx="38.5" cy="14.5" r="4.2" fill="#9864E2" />
            <circle cx="34.5" cy="25" r="3.2" fill="#E84E8A" />
          </svg>
          <svg
            v-else-if="modulo.clave === 'sitio'"
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <!-- Dos alas angulares (coral + azul cielo) con rombo marino central -->
            <path
              d="M7.5 24.2C5.8 22.8 6.1 20.1 8 19.1L20.2 12.6C22.1 11.6 24.4 13 24.4 15.2V35.5C24.4 37.9 21.7 39.3 19.8 37.8L7.5 24.2Z"
              fill="#F2633A"
            />
            <path
              d="M40.5 20.8C42.2 19.4 41.9 16.7 40 15.7L27.8 9.2C25.9 8.2 23.6 9.6 23.6 11.8V35.5C23.6 37.9 26.3 39.3 28.2 37.8L40.5 20.8Z"
              fill="#268EF0"
            />
            <path
              d="M24 17.5L30.5 25.5L24 37.8L17.5 25.5L24 17.5Z"
              fill="#0D3B6E"
            />
          </svg>
          <svg
            v-else
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <!-- Dos cimas ascendentes (ámbar + verde esmeralda) con cruce verde profundo -->
            <path
              d="M8 15C8 11.6863 10.6863 9 14 9C16.4 9 18.5 10.4 19.5 12.6L28 31.5V40H13C10.2386 40 8 37.7614 8 35V15Z"
              fill="#F5B825"
            />
            <path
              d="M40 13C40 9.68629 37.3137 7 34 7C31.6 7 29.5 8.4 28.5 10.6L20 31.5V40H35C37.7614 40 40 37.7614 40 35V13Z"
              fill="#22B573"
            />
            <path d="M24 22.6L28.5 32.5V40H19.5V32.5L24 22.6Z" fill="#0F7A4C" />
          </svg>
          <div class="modulo-titulos">
            <span>{{ marca }}</span>
            <h3>{{ modulo.nombre }}</h3>
          </div>
          <span class="modulo-vista" aria-hidden="true">{{
            modulo.vista
          }}</span>
        </div>
        <p class="modulo-promesa">{{ modulo.promesa }}</p>
        <p class="modulo-texto">{{ modulo.texto }}</p>
        <button
          type="button"
          :aria-label="`${abierto === modulo.clave ? 'Cerrar' : 'Explorar'} módulo ${modulo.nombre}`"
          :aria-expanded="abierto === modulo.clave"
          :aria-controls="`${id}-${modulo.clave}`"
          @click="abierto = abierto === modulo.clave ? null : modulo.clave"
        >
          {{ abierto === modulo.clave ? "Cerrar detalle" : "Explorar módulo"
          }}<IconoNav
            :nombre="abierto === modulo.clave ? 'arriba' : 'flecha'"
            :tam="16"
          />
        </button>
        <div
          v-show="abierto === modulo.clave"
          :id="`${id}-${modulo.clave}`"
          class="modulo-detalle"
        >
          <ul>
            <li v-for="punto in modulo.puntos" :key="punto">{{ punto }}</li>
          </ul>
          <p>{{ modulo.condicion }}</p>
        </div>
      </article>
    </div>
    <p class="modulos-conexion">
      Módulos conectados en una misma plataforma. Consulta en precios las
      herramientas incluidas en tu plan.
    </p>
  </div>
</template>

<style scoped>
.modulos-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 20px;
  align-items: start;
}
.modulo {
  position: relative;
  overflow: hidden;
  border: 1px solid var(--borde);
  background: var(--superficie);
  border-radius: 22px;
  padding: 26px;
  transition:
    transform 0.25s,
    border-color 0.25s,
    box-shadow 0.25s;
}
.modulo:hover,
.modulo:focus-within {
  border-color: color-mix(in srgb, var(--modulo-acento) 50%, var(--borde));
  box-shadow: 0 12px 35px -24px var(--modulo-acento);
  transform: translateY(-3px);
}
.modulo-azul {
  --modulo-acento: #3a8fd4;
}
.modulo-menta {
  --modulo-acento: #0d3b6e;
}
.modulo-rosa {
  --modulo-acento: #256eb5;
}
.modulo-marca {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  align-items: center;
  margin-bottom: 18px;
}
.modulo-isotipo {
  width: 48px;
  height: 48px;
  flex-shrink: 0;
  transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1);
}
.modulo:hover .modulo-isotipo {
  transform: scale(1.06) rotate(-2deg);
}
.modulo-titulos > span {
  display: block;
  font-size: 0.76rem;
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--texto);
  line-height: 1.15;
}
.modulo h3 {
  margin: 2px 0 0;
  font-size: 1.52rem;
  font-weight: 300;
  letter-spacing: -0.03em;
  color: var(--texto);
  line-height: 1.12;
}
.modulo-vista {
  margin-left: auto;
  padding: 0.25rem 0.65rem;
  border: 1px solid color-mix(in srgb, var(--modulo-acento) 28%, var(--borde));
  border-radius: 999px;
  background: color-mix(in srgb, var(--modulo-acento) 8%, var(--fondo));
  color: var(--primario);
  font-size: 0.68rem;
  font-weight: 600;
  white-space: nowrap;
}
.modulo-promesa {
  font-size: 0.98rem;
  line-height: 1.4;
  font-weight: 600;
  min-height: 2.2rem;
}
.modulo-texto {
  margin-top: 6px;
  font-size: 0.87rem;
  line-height: 1.55;
  color: var(--texto-suave);
  min-height: 2.8rem;
}
.modulo button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
  padding: 16px 0 0;
  margin-top: 22px;
  border: 0;
  border-top: 1px solid var(--borde);
  background: transparent;
  color: var(--texto);
  font-size: 0.85rem;
  cursor: pointer;
}
.modulo button:focus-visible {
  outline: 2px solid var(--enlace);
  outline-offset: 6px;
  border-radius: 3px;
}
.modulo-detalle {
  padding-top: 20px;
}
.modulo-detalle ul {
  padding-left: 18px;
  display: grid;
  gap: 8px;
  list-style: disc;
  font-size: 0.85rem;
  line-height: 1.6;
}
.modulo-detalle > p {
  font-size: 0.75rem;
  color: var(--texto-suave);
  line-height: 1.65;
  margin-top: 18px;
}
.modulos-conexion {
  text-align: center;
  max-width: 650px;
  margin: 26px auto 0;
  font-size: 0.85rem;
  line-height: 1.7;
  color: var(--texto-suave);
}
@media (max-width: 1023px) {
  .modulos-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 639px) {
  .modulos-grid {
    grid-template-columns: 1fr;
    gap: 14px;
  }
  .modulo {
    padding: 24px;
  }
  .modulo-texto,
  .modulo-promesa {
    min-height: 0;
  }
  .modulo-marca {
    margin-bottom: 20px;
  }
}
@media (prefers-reduced-motion: reduce) {
  .modulo {
    transition: none;
  }
  .modulo:hover,
  .modulo:focus-within {
    transform: none;
  }
}
</style>
