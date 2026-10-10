<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
import { computed, ref, watch } from "vue";

import type { Modo } from "@/marketing/modalidades";

/*
| Agenda de ejemplo (datos ficticios, sin API: se prerenderiza).
| - Sin `modo`: selector clases/citas con v-model, como en la portada de siempre.
| - Con `modo` (/clases, /citas): fija y sin selector. Clases muestra la semana con el
|   cupo de cada clase (el panel de clases no tiene columnas por instructor); citas, el
|   día con una columna por profesional.
*/
const props = defineProps<{ modo?: Modo }>();
const modelo = defineModel<Modo>({ default: "clases" });

const fijo = computed(() => props.modo !== undefined);
const modo = computed<Modo>(() => props.modo ?? modelo.value);
const semana = computed(() => fijo.value && modo.value === "clases");

const seleccion = ref(0);
watch(modo, () => {
  seleccion.value = 0;
});

interface EventoDemo {
  titulo: string;
  hora: string;
  /** Día de la semana (solo en la semana de clases). */
  dia?: string;
  persona: string;
  detalle: string;
  estado: string;
  /** El estado pide atención (clase llena): punto ámbar. */
  atencion?: boolean;
  nota: string;
  color: "azul" | "violeta" | "verde";
  columna: number;
  fila: number;
}

const ejemplos: Record<Modo, EventoDemo[]> = {
  clases: [
    {
      titulo: "Pilates Reformer",
      hora: "09:00",
      persona: "Andrea",
      detalle: "6 de 8 lugares reservados",
      estado: "2 lugares disponibles",
      nota: "Consulta la lista de alumnos y registra su asistencia al llegar.",
      color: "azul",
      columna: 2,
      fila: 2,
    },
    {
      titulo: "Pole dance básico",
      hora: "10:00",
      persona: "Sofía",
      detalle: "8 de 8 lugares reservados",
      estado: "Clase completa",
      nota: "Identifica las clases llenas y consulta su lista de espera.",
      color: "violeta",
      columna: 3,
      fila: 4,
    },
    {
      titulo: "Yoga flow",
      hora: "09:30",
      persona: "Elena",
      detalle: "5 de 10 lugares reservados",
      estado: "5 lugares disponibles",
      nota: "Revisa los cupos y las reservas de cada clase desde la agenda.",
      color: "verde",
      columna: 4,
      fila: 3,
    },
    {
      titulo: "Pilates mat",
      hora: "11:00",
      persona: "Andrea",
      detalle: "7 de 10 lugares reservados",
      estado: "3 lugares disponibles",
      nota: "Organiza horarios recurrentes y revisa quién asistirá.",
      color: "azul",
      columna: 2,
      fila: 6,
    },
  ],
  citas: [
    {
      titulo: "Corte de cabello",
      hora: "09:00",
      persona: "Marco",
      detalle: "Diego · 45 min",
      estado: "Confirmada",
      nota: "Servicio, cliente y profesional a la vista en una misma cita.",
      color: "azul",
      columna: 2,
      fila: 2,
    },
    {
      titulo: "Corte y barba",
      hora: "10:00",
      persona: "Luis",
      detalle: "Carlos · 60 min",
      estado: "Confirmada",
      nota: "Cada profesional tiene su horario y sus citas organizadas.",
      color: "violeta",
      columna: 3,
      fila: 4,
    },
    {
      titulo: "Corte de cabello",
      hora: "09:30",
      persona: "Alex",
      detalle: "Daniel · 45 min",
      estado: "Confirmada",
      nota: "Consulta la duración del servicio y la disponibilidad de tu equipo.",
      color: "verde",
      columna: 4,
      fila: 3,
    },
    {
      titulo: "Arreglo de barba",
      hora: "11:00",
      persona: "Marco",
      detalle: "Pablo · 30 min",
      estado: "Confirmada",
      nota: "Encuentra los espacios disponibles sin revisar agendas por separado.",
      color: "azul",
      columna: 2,
      fila: 6,
    },
  ],
};

// La semana de /clases: una columna por día y el cupo de cada clase.
const DIAS_SEMANA = ["Lun", "Mar", "Mié", "Jue", "Vie"] as const;
const semanaClases: EventoDemo[] = [
  {
    titulo: "Pilates Reformer",
    hora: "09:00",
    dia: "Lunes",
    persona: "Andrea",
    detalle: "6 de 8 lugares",
    estado: "2 lugares disponibles",
    nota: "Consulta el cupo de cada clase y quién reservó, sin abrir otra pantalla.",
    color: "azul",
    columna: 2,
    fila: 2,
  },
  {
    titulo: "Pole dance básico",
    hora: "10:00",
    dia: "Martes",
    persona: "Sofía",
    detalle: "8 de 8 lugares",
    estado: "Clase llena · 2 en lista de espera",
    atencion: true,
    nota: "Si alguien cancela, el lugar se ofrece a quien sigue en la lista de espera.",
    color: "violeta",
    columna: 3,
    fila: 4,
  },
  {
    titulo: "Yoga flow",
    hora: "09:30",
    dia: "Miércoles",
    persona: "Elena",
    detalle: "5 de 10 lugares",
    estado: "5 lugares disponibles",
    nota: "Programa la misma clase cada semana y ajusta su cupo cuando lo necesites.",
    color: "verde",
    columna: 4,
    fila: 3,
  },
  {
    titulo: "Pilates mat",
    hora: "11:00",
    dia: "Jueves",
    persona: "Andrea",
    detalle: "7 de 10 lugares",
    estado: "3 lugares disponibles",
    nota: "Al empezar la clase, pasa lista: llegó, retardo o no vino.",
    color: "azul",
    columna: 5,
    fila: 6,
  },
  {
    titulo: "Entrenamiento funcional",
    hora: "10:30",
    dia: "Viernes",
    persona: "Elena",
    detalle: "10 de 12 lugares",
    estado: "2 lugares disponibles",
    nota: "Cada reserva usa un crédito de la membresía o del paquete del alumno.",
    color: "verde",
    columna: 6,
    fila: 5,
  },
];

const eventos = computed(() =>
  semana.value ? semanaClases : ejemplos[modo.value],
);
const actual = computed(() => eventos.value[seleccion.value]!);
/** Encabezados de columna: días en la semana de clases, profesionales en el resto. */
const columnas = computed<readonly string[]>(() => {
  if (semana.value) return DIAS_SEMANA;
  return modo.value === "clases"
    ? ["Andrea", "Sofía", "Elena"]
    : ["Marco", "Luis", "Alex"];
});
const lineaDetalle = computed(() =>
  actual.value.dia
    ? `${actual.value.dia} ${actual.value.hora} · con ${actual.value.persona}`
    : `${actual.value.hora} · ${actual.value.persona}`,
);
const cabecera = computed(() => {
  if (!fijo.value) {
    return {
      etiqueta: "TODO TU DÍA, A LA VISTA",
      titulo: "Tu agenda, en orden.",
    };
  }
  return modo.value === "clases"
    ? { etiqueta: "TU SEMANA DE CLASES", titulo: "Cada clase con su cupo." }
    : {
        etiqueta: "CADA PROFESIONAL, SU AGENDA",
        titulo: "Tu agenda, en orden.",
      };
});
const unidad = computed(() => (modo.value === "clases" ? "clase" : "cita"));
const regionEtiqueta = computed(() => {
  if (!fijo.value) {
    return "Calendario de ejemplo. Selecciona una clase o cita para ver su detalle.";
  }
  return semana.value
    ? "Semana de clases de ejemplo. Selecciona una clase para ver su cupo."
    : "Agenda de citas de ejemplo. Selecciona una cita para ver su detalle.";
});

function cambiar(valor: Modo): void {
  modelo.value = valor;
  seleccion.value = 0;
}
</script>
<template>
  <div class="producto-demo" :data-modo="fijo ? modo : undefined">
    <div class="demo-contexto">
      <span class="demo-marca">AgendaUno / Agenda</span>
      <span class="demo-ejemplo">Demo interactiva · Datos de ejemplo</span>
    </div>
    <div class="demo-cabecera">
      <div>
        <p>{{ cabecera.etiqueta }}</p>
        <h3>{{ cabecera.titulo }}</h3>
      </div>
      <div
        v-if="!fijo"
        class="demo-modos"
        role="group"
        aria-label="Tipo de agenda de ejemplo"
      >
        <button
          type="button"
          :aria-pressed="modo === 'clases'"
          @click="cambiar('clases')"
        >
          Clases y cupos
        </button>
        <button
          type="button"
          :aria-pressed="modo === 'citas'"
          @click="cambiar('citas')"
        >
          Citas por profesional
        </button>
      </div>
    </div>
    <div class="demo-cuerpo">
      <div class="demo-calendario">
        <div class="demo-dia">
          <template v-if="semana">
            <strong>Esta semana <span>· Vista de semana</span></strong
            ><span>Cupo por clase</span>
          </template>
          <template v-else>
            <strong>Lunes <span>· Vista de día</span></strong
            ><span>Tu equipo</span>
          </template>
        </div>
        <div
          class="demo-scroll"
          tabindex="0"
          role="region"
          :aria-label="regionEtiqueta"
        >
          <div
            class="demo-grid"
            :class="{ 'demo-grid-semana': semana }"
            :style="{
              gridTemplateColumns: `3.2rem repeat(${columnas.length}, minmax(0, 1fr))`,
            }"
          >
            <template v-if="semana">
              <div
                v-for="(dia, i) in columnas"
                :key="dia"
                class="demo-columna"
                :style="{ gridColumn: i + 2, gridRow: 1 }"
              >
                {{ dia }}
              </div>
            </template>
            <template v-else>
              <div
                v-for="(persona, i) in columnas"
                :key="persona"
                class="demo-persona"
                :style="{ gridColumn: i + 2, gridRow: 1 }"
              >
                <span>{{ persona.charAt(0) }}</span
                >{{ persona }}
              </div>
            </template>
            <span
              v-for="(hora, i) in ['09:00', '10:00', '11:00', '12:00']"
              :key="hora"
              class="demo-hora"
              :style="{ gridRow: i * 2 + 2 }"
              >{{ hora }}</span
            >
            <div
              v-for="n in 7"
              :key="n"
              class="demo-linea"
              :style="{ gridRow: n + 1 }"
              aria-hidden="true"
            ></div>
            <button
              v-for="(evento, i) in eventos"
              :key="modo + i"
              type="button"
              class="demo-evento"
              :class="evento.color"
              :style="{
                gridColumn: evento.columna,
                gridRow: evento.fila + ' / span 2',
              }"
              :aria-pressed="seleccion === i"
              @click="seleccion = i"
            >
              <small>{{ evento.hora }}</small
              ><strong>{{ evento.titulo }}</strong
              ><span>{{ evento.detalle }}</span>
            </button>
          </div>
        </div>
        <p class="demo-pista">
          <span class="demo-pista-movil"
            >{{
              semana
                ? "Desliza la agenda para ver toda la semana"
                : "Desliza la agenda para ver a todo tu equipo"
            }}
            <IconoNav nombre="flecha" :tam="14" class="inline align-[-0.15em]"
          /></span>
          <template v-if="semana">
            Selecciona una clase para ver su cupo
          </template>
          <template v-else>
            Selecciona una {{ unidad }} para ver sus detalles
          </template>
          <span aria-hidden="true"
            ><IconoNav nombre="flecha" :tam="14" class="inline align-[-0.15em]"
          /></span>
        </p>
      </div>
      <aside class="demo-detalle" aria-live="polite" aria-atomic="true">
        <span v-if="!fijo" class="demo-detalle-icono" aria-hidden="true"
          >✓</span
        >
        <p class="demo-detalle-label">MENOS BÚSQUEDAS. MÁS CONTROL.</p>
        <h4>{{ actual.titulo }}</h4>
        <p class="demo-profesional">{{ lineaDetalle }}</p>
        <span
          class="demo-estado"
          :class="{ 'demo-estado-punto': fijo, atencion: actual.atencion }"
          >{{ actual.estado }}</span
        >
        <p class="demo-nota">{{ actual.nota }}</p>
        <div class="demo-detalle-pie">
          <span v-if="!fijo" aria-hidden="true">◷ </span>Todo empieza con una
          agenda clara.
        </div>
      </aside>
    </div>
  </div>
</template>
<style scoped>
.producto-demo {
  overflow: hidden;
  border: 1px solid var(--borde);
  border-radius: var(--radio-panel, 28px);
  background: var(--superficie);
  box-shadow: 0 30px 90px -45px rgb(0 70 160 / 32%);
}
.demo-contexto {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid var(--borde);
}
.demo-marca {
  font-size: 1.05rem;
  letter-spacing: -0.05em;
}
.demo-marca strong {
  color: var(--primario-fuerte);
}
.demo-ejemplo {
  font-size: 0.65rem;
  color: var(--texto-suave);
}
.demo-cabecera {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1.5rem;
  flex-wrap: wrap;
  padding: 1.5rem;
}
.demo-cabecera p {
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.14em;
  color: var(--primario-fuerte);
}
.demo-cabecera h3 {
  font-size: 1.65rem;
  font-weight: 300;
  letter-spacing: -0.04em;
  margin-top: 0.25rem;
}
.demo-modos {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  padding: 0.3rem;
  border-radius: 0.8rem;
  background: var(--fondo);
}
.demo-modos button {
  padding: 0.65rem 0.8rem;
  border: 0;
  border-radius: 0.6rem;
  color: var(--texto-suave);
  background: transparent;
  font-size: 0.72rem;
  font-weight: 600;
  cursor: pointer;
}
.demo-modos button[aria-pressed="true"] {
  background: var(--superficie);
  color: var(--primario-fuerte);
  box-shadow: 0 2px 8px rgb(0 0 0 / 6%);
}
.demo-cuerpo {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 15rem;
  border-top: 1px solid var(--borde);
}
.demo-calendario {
  min-width: 0;
}
.demo-dia {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  font-size: 0.78rem;
  border-bottom: 1px solid var(--borde);
}
.demo-dia span {
  color: var(--texto-suave);
  font-weight: 400;
}
.demo-scroll {
  overflow-x: auto;
}
.demo-grid {
  display: grid;
  grid-template-columns: 3.2rem repeat(3, minmax(0, 1fr));
  grid-template-rows: 3.5rem repeat(7, 2.1rem);
  min-width: 440px;
  padding: 0 0.8rem 0.75rem;
  position: relative;
}
.demo-persona {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  font-weight: 600;
  font-size: 0.7rem;
}
.demo-persona > span {
  display: grid;
  place-items: center;
  width: 1.6rem;
  height: 1.6rem;
  border-radius: 50%;
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
.demo-hora {
  grid-column: 1;
  font-size: 0.62rem;
  color: var(--texto-suave);
  transform: translateY(-0.45rem);
}
.demo-linea {
  grid-column: 2 / -1;
  border-top: 1px solid var(--borde);
  pointer-events: none;
}
.demo-evento {
  align-self: stretch;
  min-width: 0;
  margin: 2px 4px;
  padding: 0.45rem 0.55rem;
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
  border: 0;
  border-left: 3px solid var(--evento-color);
  border-radius: 0.55rem;
  background: color-mix(in srgb, var(--evento-color) 11%, var(--superficie));
  color: var(--texto);
  cursor: pointer;
  text-align: left;
  position: relative;
  transition: box-shadow 0.2s;
}
.demo-evento.azul {
  --evento-color: var(--enlace);
}
.demo-evento.violeta {
  --evento-color: #9673de;
}
.demo-evento.verde {
  --evento-color: #12a68b;
}
.demo-evento[aria-pressed="true"] {
  box-shadow: 0 0 0 2px var(--evento-color);
}
.demo-evento small {
  color: var(--texto-suave);
  font-size: 0.6rem;
}
.demo-evento strong {
  font-size: 0.71rem;
  line-height: 1.15;
}
.demo-evento > span {
  color: var(--texto-suave);
  font-size: 0.58rem;
  line-height: 1.25;
}
.demo-pista-movil {
  display: none;
}
.demo-pista {
  padding: 0.75rem 1.25rem;
  border-top: 1px solid var(--borde);
  color: var(--texto-suave);
  font-size: 0.67rem;
}
.demo-pista span {
  color: var(--primario-fuerte);
}
.demo-detalle {
  padding: 1.5rem 1.25rem;
  border-left: 1px solid var(--borde);
  background: var(--fondo);
}
.demo-detalle-icono {
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.9rem;
  background: var(--primario-suave);
  color: var(--primario-fuerte);
  font-size: 1.3rem;
}
.demo-detalle-label {
  font-size: 0.56rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  color: var(--texto-suave);
  margin-top: 1.2rem;
}
.demo-detalle h4 {
  font-size: 1.2rem;
  font-weight: 300;
  margin-top: 0.4rem;
  line-height: 1.2;
}
.demo-profesional {
  font-size: 0.75rem;
  color: var(--texto-suave);
  margin-top: 0.5rem;
}
.demo-estado {
  display: inline-block;
  border: 1px solid var(--borde);
  border-radius: 8px;
  padding: 0.4rem 0.65rem;
  margin-top: 1rem;
  font-size: 0.66rem;
  color: var(--primario-fuerte);
  background: var(--superficie);
}
.demo-columna {
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 0.7rem;
}
.demo-grid-semana {
  min-width: 560px;
}
/* Con modo fijo, el estado es punto + texto (sin caja); ámbar solo si pide atención. */
.demo-estado.demo-estado-punto {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--texto);
  font-size: 0.75rem;
}
.demo-estado-punto::before {
  content: "";
  flex-shrink: 0;
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--texto-suave);
}
.demo-estado-punto.atencion::before {
  background: var(--aviso);
}
.demo-nota {
  font-size: 0.78rem;
  color: var(--texto-suave);
  line-height: 1.6;
  margin-top: 1rem;
}
.demo-detalle-pie {
  font-size: 0.66rem;
  color: var(--texto-suave);
  margin-top: 1.5rem;
  padding-top: 1rem;
  border-top: 1px solid var(--borde);
}
button:focus-visible,
.demo-scroll:focus-visible {
  outline: 3px solid var(--primario);
  outline-offset: 3px;
}
@media (max-width: 760px) {
  .demo-grid {
    grid-template-rows: 3.5rem repeat(7, 2.75rem);
  }
  .demo-pista-movil {
    display: block;
    margin-bottom: 0.3rem;
  }
  .demo-cuerpo {
    grid-template-columns: 1fr;
  }
  .demo-detalle {
    border-left: 0;
    border-top: 1px solid var(--borde);
  }
  .demo-detalle-icono {
    display: none;
  }
  .demo-detalle-label {
    margin-top: 0;
  }
  .demo-cabecera,
  .demo-contexto {
    padding: 1rem;
  }
  .demo-detalle-pie {
    display: none;
  }
}
@media (prefers-reduced-motion: reduce) {
  .demo-evento {
    transition: none;
  }
}
</style>
