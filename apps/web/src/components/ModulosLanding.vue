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
        v-for="modulo in modulos"
        :key="modulo.clave"
        class="modulo"
        :class="[
          `modulo-${modulo.color}`,
          { 'modulo-abierto': abierto === modulo.clave },
        ]"
        data-scroll="acomodo"
      >
        <div class="modulo-marca">
          <svg
            v-if="modulo.clave === 'agenda'"
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <defs>
              <linearGradient
                id="mod-agenda-bg"
                x1="0%"
                y1="0%"
                x2="100%"
                y2="100%"
              >
                <stop offset="0%" stop-color="#e0f2fe" />
                <stop offset="100%" stop-color="#bae6fd" />
              </linearGradient>
              <linearGradient
                id="mod-agenda-top"
                x1="0%"
                y1="0%"
                x2="100%"
                y2="0%"
              >
                <stop offset="0%" stop-color="#7dd3fc" />
                <stop offset="100%" stop-color="#c4b5fd" />
              </linearGradient>
            </defs>
            <!-- Calendario pastel con cabecera cielo-lavanda y bloque de turno en rosa pastel -->
            <rect
              x="6"
              y="9"
              width="36"
              height="33"
              rx="9"
              fill="url(#mod-agenda-bg)"
              stroke="#7dd3fc"
              stroke-width="1.2"
            />
            <path
              d="M6 18C6 13.0294 10.0294 9 15 9H33C37.9706 9 42 13.0294 42 18V20H6V18Z"
              fill="url(#mod-agenda-top)"
            />
            <rect x="14" y="5" width="4" height="8" rx="2" fill="#38bdf8" />
            <rect x="30" y="5" width="4" height="8" rx="2" fill="#a78bfa" />
            <rect
              x="11"
              y="24"
              width="7"
              height="5.5"
              rx="2"
              fill="#ffffff"
              fill-opacity="0.92"
            />
            <rect
              x="20.5"
              y="24"
              width="7"
              height="5.5"
              rx="2"
              fill="#ffffff"
              fill-opacity="0.92"
            />
            <rect
              x="30"
              y="24"
              width="7"
              height="5.5"
              rx="2"
              fill="#ffffff"
              fill-opacity="0.92"
            />
            <rect
              x="11"
              y="32"
              width="7"
              height="5.5"
              rx="2"
              fill="#ffffff"
              fill-opacity="0.92"
            />
            <rect
              x="20.5"
              y="32"
              width="16.5"
              height="5.5"
              rx="2.75"
              fill="#f9a8d4"
            />
            <circle cx="24" cy="34.75" r="1.4" fill="#ffffff" />
          </svg>
          <svg
            v-else-if="modulo.clave === 'cobros'"
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <defs>
              <linearGradient
                id="mod-cobros-card"
                x1="0%"
                y1="0%"
                x2="100%"
                y2="100%"
              >
                <stop offset="0%" stop-color="#bae6fd" />
                <stop offset="60%" stop-color="#e9d5ff" />
                <stop offset="100%" stop-color="#fbcfe8" />
              </linearGradient>
              <linearGradient
                id="mod-cobros-mint"
                x1="0%"
                y1="0%"
                x2="100%"
                y2="100%"
              >
                <stop offset="0%" stop-color="#a7f3d0" />
                <stop offset="100%" stop-color="#6ee7b7" />
              </linearGradient>
            </defs>
            <!-- Tarjeta de cobro pastel + insignia de pago confirmado en menta suave -->
            <rect
              x="5"
              y="10"
              width="32"
              height="23"
              rx="6.5"
              fill="url(#mod-cobros-card)"
              stroke="#93c5fd"
              stroke-width="1.2"
            />
            <rect
              x="5"
              y="15.5"
              width="32"
              height="4.5"
              fill="#7dd3fc"
              fill-opacity="0.65"
            />
            <rect
              x="10"
              y="24"
              width="9"
              height="4"
              rx="1.8"
              fill="#ffffff"
              fill-opacity="0.92"
            />
            <circle
              cx="34"
              cy="32"
              r="9.5"
              fill="url(#mod-cobros-mint)"
              stroke="#ffffff"
              stroke-width="2"
            />
            <path
              d="M30.2 32.2L32.8 34.8L37.8 29.5"
              stroke="#0d3b6e"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          <svg
            v-else-if="modulo.clave === 'personas'"
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <defs>
              <linearGradient
                id="mod-personas-a"
                x1="0%"
                y1="0%"
                x2="100%"
                y2="100%"
              >
                <stop offset="0%" stop-color="#bae6fd" />
                <stop offset="100%" stop-color="#7dd3fc" />
              </linearGradient>
              <linearGradient
                id="mod-personas-b"
                x1="0%"
                y1="0%"
                x2="100%"
                y2="100%"
              >
                <stop offset="0%" stop-color="#fbcfe8" />
                <stop offset="100%" stop-color="#f9a8d4" />
              </linearGradient>
            </defs>
            <!-- Ficha de clientes/alumnos con perfiles en azul cielo y rosa pastel -->
            <rect
              x="6"
              y="7"
              width="36"
              height="34"
              rx="10"
              fill="#f0f9ff"
              stroke="#bae6fd"
              stroke-width="1.2"
            />
            <circle cx="19" cy="18.5" r="5.2" fill="url(#mod-personas-a)" />
            <path
              d="M10.5 33.5C10.5 29.3579 14.3056 26 19 26C23.6944 26 27.5 29.3579 27.5 33.5"
              fill="url(#mod-personas-a)"
            />
            <circle cx="30.5" cy="17.5" r="4.5" fill="url(#mod-personas-b)" />
            <path
              d="M24 33.5C24.4 29.9 27.3 27.2 30.8 27.2C34.5 27.2 37.5 30 37.5 33.5"
              fill="url(#mod-personas-b)"
            />
            <rect
              x="13"
              y="36"
              width="22"
              height="2.4"
              rx="1.2"
              fill="#c4b5fd"
            />
          </svg>
          <svg
            v-else-if="modulo.clave === 'equipo'"
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <defs>
              <linearGradient
                id="mod-equipo-aro"
                x1="0%"
                y1="0%"
                x2="100%"
                y2="100%"
              >
                <stop offset="0%" stop-color="#7dd3fc" />
                <stop offset="50%" stop-color="#c4b5fd" />
                <stop offset="100%" stop-color="#f9a8d4" />
              </linearGradient>
            </defs>
            <!-- Red de equipo coordinada en tonos pastel (cielo, lavanda y rosa) -->
            <circle
              cx="24"
              cy="25"
              r="13.5"
              stroke="url(#mod-equipo-aro)"
              stroke-width="2.6"
              stroke-dasharray="5 3"
            />
            <circle
              cx="24"
              cy="11"
              r="6"
              fill="#bae6fd"
              stroke="#ffffff"
              stroke-width="1.8"
            />
            <circle cx="24" cy="11" r="2.3" fill="#38bdf8" />
            <circle
              cx="12"
              cy="32"
              r="6"
              fill="#e9d5ff"
              stroke="#ffffff"
              stroke-width="1.8"
            />
            <circle cx="12" cy="32" r="2.3" fill="#a78bfa" />
            <circle
              cx="36"
              cy="32"
              r="6"
              fill="#fbcfe8"
              stroke="#ffffff"
              stroke-width="1.8"
            />
            <circle cx="36" cy="32" r="2.3" fill="#f472b6" />
          </svg>
          <svg
            v-else-if="modulo.clave === 'sitio'"
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <defs>
              <linearGradient
                id="mod-sitio-hero"
                x1="0%"
                y1="0%"
                x2="100%"
                y2="100%"
              >
                <stop offset="0%" stop-color="#bae6fd" />
                <stop offset="55%" stop-color="#e9d5ff" />
                <stop offset="100%" stop-color="#fbcfe8" />
              </linearGradient>
            </defs>
            <!-- Ventana de página web de reservas en tonos pastel -->
            <rect
              x="5"
              y="8"
              width="38"
              height="32"
              rx="8"
              fill="#f8fafc"
              stroke="#93c5fd"
              stroke-width="1.3"
            />
            <path
              d="M5 15C5 11.134 8.13401 8 12 8H36C39.866 8 43 11.134 43 15V16.5H5V15Z"
              fill="#e0f2fe"
            />
            <circle cx="10.5" cy="12.5" r="1.5" fill="#f9a8d4" />
            <circle cx="15" cy="12.5" r="1.5" fill="#c4b5fd" />
            <circle cx="19.5" cy="12.5" r="1.5" fill="#7dd3fc" />
            <rect
              x="9.5"
              y="20"
              width="29"
              height="9"
              rx="3.5"
              fill="url(#mod-sitio-hero)"
            />
            <rect
              x="9.5"
              y="32"
              width="15"
              height="4.5"
              rx="2.25"
              fill="#7dd3fc"
            />
            <rect
              x="27"
              y="32"
              width="11.5"
              height="4.5"
              rx="2.25"
              fill="#f9a8d4"
            />
          </svg>
          <svg
            v-else
            class="modulo-isotipo"
            viewBox="0 0 48 48"
            fill="none"
            aria-hidden="true"
          >
            <defs>
              <linearGradient id="mod-crec-1" x1="0%" y1="0%" x2="0%" y2="100%">
                <stop offset="0%" stop-color="#bae6fd" />
                <stop offset="100%" stop-color="#7dd3fc" />
              </linearGradient>
              <linearGradient id="mod-crec-2" x1="0%" y1="0%" x2="0%" y2="100%">
                <stop offset="0%" stop-color="#e9d5ff" />
                <stop offset="100%" stop-color="#c4b5fd" />
              </linearGradient>
              <linearGradient id="mod-crec-3" x1="0%" y1="0%" x2="0%" y2="100%">
                <stop offset="0%" stop-color="#fbcfe8" />
                <stop offset="100%" stop-color="#f472b6" />
              </linearGradient>
            </defs>
            <!-- Gráfica ascendente en barras pastel con curva orbital y esfera en la cima -->
            <rect
              x="8"
              y="27"
              width="8"
              height="13"
              rx="3.5"
              fill="url(#mod-crec-1)"
            />
            <rect
              x="20"
              y="20"
              width="8"
              height="20"
              rx="3.5"
              fill="url(#mod-crec-2)"
            />
            <rect
              x="32"
              y="13"
              width="8"
              height="27"
              rx="3.5"
              fill="url(#mod-crec-3)"
            />
            <path
              d="M10 22C17 20 25 15 36 8.5"
              stroke="#38bdf8"
              stroke-width="2.2"
              stroke-linecap="round"
            />
            <circle
              cx="36"
              cy="8.5"
              r="3.4"
              fill="#f472b6"
              stroke="#ffffff"
              stroke-width="1.4"
            />
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
  align-items: stretch;
}
.modulo {
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  height: 100%;
  border: 1px solid color-mix(in srgb, var(--modulo-acento) 24%, var(--borde));
  background:
    radial-gradient(
      ellipse 68% 54% at 95% 5%,
      color-mix(in srgb, var(--modulo-acento) 12%, transparent) 0%,
      transparent 70%
    ),
    var(--superficie);
  border-radius: 24px;
  padding: 26px;
  box-shadow:
    0 18px 42px -26px rgb(13 59 110 / 18%),
    0 2px 8px rgb(13 59 110 / 4%);
  transition:
    transform 0.28s cubic-bezier(0.22, 1, 0.36, 1),
    border-color 0.28s ease,
    box-shadow 0.28s ease;
}
.modulo:hover,
.modulo:focus-within {
  border-color: color-mix(in srgb, var(--modulo-acento) 52%, var(--borde));
  box-shadow:
    0 24px 48px -24px color-mix(in srgb, var(--modulo-acento) 34%, transparent),
    0 4px 12px rgb(13 59 110 / 6%);
  transform: translateY(-3px);
}
.modulo-azul {
  --modulo-acento: #3a8fd4;
}
.modulo-menta {
  --modulo-acento: #0ea5e9;
}
.modulo-rosa {
  --modulo-acento: #ec4899;
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
  min-height: 2.6rem;
}
.modulo-texto {
  flex: 1;
  margin-top: 6px;
  margin-bottom: 20px;
  font-size: 0.87rem;
  line-height: 1.55;
  color: var(--texto-suave);
  min-height: 4.2rem;
}
.modulo button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
  padding: 16px 0 0;
  margin-top: auto;
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
