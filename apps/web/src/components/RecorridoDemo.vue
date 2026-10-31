<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";

import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import type { Modo } from "@/marketing/modalidades";

/*
| De la reserva al resultado (landing): recorrido interactivo con reproducción
| automática (estilo video de producto en vivo) y control manual paso a paso.
*/
const props = defineProps<{ modo: Modo }>();
const marca = computed(() => PRODUCTOS[productoDeModalidad(props.modo)].nombre);

interface Fila {
  etiqueta: string;
  valor: string;
  /** Resalta el dato que cambió en este paso. */
  clave?: boolean;
}
interface Paso {
  clave: string;
  quien: string;
  titulo: string;
  texto: string;
  /** La pantalla de ejemplo: dónde ocurre y lo que se ve. */
  pantalla: string;
  filas: Fila[];
  estado: string;
}

const PASOS: Record<Modo, Paso[]> = {
  clases: [
    {
      clave: "reserva",
      quien: "Tu alumna",
      titulo: "Sofía reserva su lugar",
      texto:
        "Elige su clase desde tu página o la app y aparta lugar con sus créditos.",
      pantalla: "Tu página · Reservar",
      filas: [
        { etiqueta: "Clase", valor: "Pilates Reformer · lunes 18:00" },
        { etiqueta: "Instructora", valor: "Andrea" },
        { etiqueta: "Lugares", valor: "3 libres de 8" },
        { etiqueta: "Usa", valor: "1 crédito · le quedan 7", clave: true },
      ],
      estado: "Reserva confirmada",
    },
    {
      clave: "recibe",
      quien: "Tu estudio",
      titulo: "La reserva llega a tu agenda",
      texto:
        "Se descuenta un lugar del cupo y Sofía aparece lista para pasar asistencia.",
      pantalla: "Agenda · Lista de la clase",
      filas: [
        { etiqueta: "Pilates Reformer", valor: "lunes 18:00 · Andrea" },
        { etiqueta: "Ocupación", valor: "6 de 8", clave: true },
        { etiqueta: "Sofía Ruiz", valor: "Confirmada · Paquete 8 clases" },
        { etiqueta: "Al llegar", valor: "Llegó · Retardo · No vino" },
      ],
      estado: "Lista lista para pasar asistencia",
    },
    {
      clave: "cobro",
      quien: "Recepción",
      titulo: "Se registra el pago",
      texto:
        "Cobras su renovación en recepción o ella paga en línea desde su cuenta*.",
      pantalla: "Cobros · Ficha de Sofía",
      filas: [
        { etiqueta: "Concepto", valor: "Paquete 8 clases" },
        { etiqueta: "Importe", valor: "$1,200.00 MXN" },
        { etiqueta: "Forma de pago", valor: "Tarjeta en línea*", clave: true },
        { etiqueta: "Créditos", valor: "+8 · vigencia 30 días" },
      ],
      estado: "Pagado · En su historial",
    },
    {
      clave: "resultado",
      quien: "Tú",
      titulo: "Consultas el resultado",
      texto:
        "Ves asistencia, renovaciones pendientes y el corte de caja cuadrado por forma de pago.",
      pantalla: "Inicio y corte de caja",
      filas: [
        { etiqueta: "Asistencia", valor: "7 de 8 en Pilates Reformer" },
        { etiqueta: "Por renovar", valor: "2 paquetes esta semana" },
        { etiqueta: "Cobrado hoy", valor: "$3,600.00 MXN", clave: true },
        {
          etiqueta: "Por forma de pago",
          valor: "Efectivo $2,400 · Tarjeta $1,200",
        },
      ],
      estado: "Cuadrado al cierre del día",
    },
  ],
  citas: [
    {
      clave: "reserva",
      quien: "Tu cliente",
      titulo: "Diego agenda su cita",
      texto:
        "Desde tu página, sin crear una cuenta: elige servicio, profesional y hora.",
      pantalla: "Tu página · Agendar",
      filas: [
        { etiqueta: "Servicio", valor: "Corte y barba · 60 min" },
        { etiqueta: "Profesional", valor: "Cualquiera → Marco" },
        { etiqueta: "Hora", valor: "jueves 17:30", clave: true },
        { etiqueta: "Precio", valor: "$350.00 MXN" },
      ],
      estado: "Cita confirmada",
    },
    {
      clave: "recibe",
      quien: "Tu negocio",
      titulo: "Llega a la agenda de Marco",
      texto:
        "Se aparta el horario de Marco y Diego recibe su recordatorio por correo.",
      pantalla: "Agenda · Marco",
      filas: [
        { etiqueta: "17:30", valor: "Corte y barba · Diego", clave: true },
        { etiqueta: "Estado", valor: "Confirmada" },
        { etiqueta: "Recordatorio", valor: "Por correo, un día antes" },
        { etiqueta: "Al llegar", valor: "Llegó · No llegó" },
      ],
      estado: "Horario apartado",
    },
    {
      clave: "cobro",
      quien: "Recepción",
      titulo: "Se registra el pago",
      texto:
        "Cobras al terminar en recepción o Diego paga en línea al agendar*.",
      pantalla: "Por cobrar · Diego",
      filas: [
        { etiqueta: "Servicio", valor: "Corte y barba" },
        { etiqueta: "Importe", valor: "$350.00 MXN" },
        { etiqueta: "Forma de pago", valor: "Efectivo", clave: true },
        { etiqueta: "Atendió", valor: "Marco" },
      ],
      estado: "Pagado · En su historial",
    },
    {
      clave: "resultado",
      quien: "Tú",
      titulo: "Consultas el resultado",
      texto:
        "Al cerrar el día ves las citas atendidas y el corte de caja por forma de pago.",
      pantalla: "Inicio y corte de caja",
      filas: [
        { etiqueta: "Citas", valor: "9 · 8 atendidas · 1 no llegó" },
        { etiqueta: "Por cobrar", valor: "$350.00 MXN · 1 cita" },
        { etiqueta: "Cobrado hoy", valor: "$3,150.00 MXN", clave: true },
        {
          etiqueta: "Por forma de pago",
          valor: "Efectivo $1,750 · Tarjeta $1,400",
        },
      ],
      estado: "Cuadrado al cierre del día",
    },
  ],
};

const pasos = computed(() => PASOS[props.modo]);
const actual = ref(0);
const reproduciendo = ref(false);
let temporizador: ReturnType<typeof setInterval> | undefined;

function detenerAuto(): void {
  reproduciendo.value = false;
  if (temporizador) {
    clearInterval(temporizador);
    temporizador = undefined;
  }
}

function iniciarAuto(): void {
  detenerAuto();
  reproduciendo.value = true;
  temporizador = setInterval(() => {
    actual.value = (actual.value + 1) % pasos.value.length;
  }, 4200);
}

function alternarAuto(): void {
  if (reproduciendo.value) detenerAuto();
  else iniciarAuto();
}

function elegirPaso(i: number): void {
  detenerAuto();
  actual.value = i;
}

onMounted(() => {
  const reducido =
    window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
  if (!reducido) iniciarAuto();
});

onBeforeUnmount(() => detenerAuto());

watch(
  () => props.modo,
  () => {
    actual.value = 0;
  },
);
const paso = computed(() => pasos.value[actual.value]!);

// Flechas izquierda y derecha entre los pasos (patrón de pestañas).
function mover(delta: number): void {
  detenerAuto();
  const total = pasos.value.length;
  actual.value = (actual.value + delta + total) % total;
  document
    .getElementById(`recorrido-paso-${pasos.value[actual.value]!.clave}`)
    ?.focus();
}
</script>

<template>
  <div class="recorrido" data-prueba="recorrido">
    <div
      class="recorrido-pasos"
      role="tablist"
      :aria-label="`El recorrido en ${marca}`"
      @keydown.right.prevent="mover(1)"
      @keydown.left.prevent="mover(-1)"
    >
      <button
        v-for="(p, i) in pasos"
        :id="`recorrido-paso-${p.clave}`"
        :key="p.clave"
        type="button"
        role="tab"
        class="recorrido-paso"
        :aria-selected="actual === i"
        :aria-controls="`recorrido-panel-${p.clave}`"
        :tabindex="actual === i ? 0 : -1"
        :data-paso="p.clave"
        :class="{ 'recorrido-paso--auto': actual === i && reproduciendo }"
        @click="elegirPaso(i)"
      >
        <span class="recorrido-num" aria-hidden="true">{{ i + 1 }}</span>
        <span class="recorrido-paso-texto">
          <span class="recorrido-quien">{{ p.quien }}</span>
          <span class="recorrido-titulo">{{ p.titulo }}</span>
        </span>
      </button>
    </div>

    <div
      :id="`recorrido-panel-${paso.clave}`"
      class="recorrido-panel"
      role="tabpanel"
      :aria-labelledby="`recorrido-paso-${paso.clave}`"
    >
      <div class="recorrido-explica">
        <p class="recorrido-quien">
          Paso {{ actual + 1 }} de {{ pasos.length }} · {{ paso.quien }}
        </p>
        <h3>{{ paso.titulo }}</h3>
        <p>{{ paso.texto }}</p>
        <div class="recorrido-navegar">
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="actual === 0"
            @click="elegirPaso(actual - 1)"
          >
            Anterior
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="actual === pasos.length - 1"
            data-prueba="recorrido-siguiente"
            @click="elegirPaso(actual + 1)"
          >
            Siguiente
          </button>
          <button
            type="button"
            class="recorrido-play"
            :aria-label="
              reproduciendo
                ? 'Pausar recorrido automático'
                : 'Reproducir recorrido automático'
            "
            @click="alternarAuto"
          >
            <span aria-hidden="true">{{ reproduciendo ? "❚❚" : "▶" }}</span>
          </button>
        </div>
      </div>
      <figure class="recorrido-pantalla" aria-label="Pantalla de ejemplo">
        <figcaption class="recorrido-ventana-barra">
          <span class="recorrido-ventana-punto" aria-hidden="true" />
          <span class="recorrido-ventana-punto" aria-hidden="true" />
          <span class="recorrido-ventana-punto" aria-hidden="true" />
          <span class="recorrido-ventana-titulo">{{ paso.pantalla }}</span>
        </figcaption>

        <dl :key="paso.clave" class="recorrido-dl-anim">
          <div
            v-for="f in paso.filas"
            :key="f.etiqueta"
            :class="{ 'recorrido-clave': f.clave }"
          >
            <dt>{{ f.etiqueta }}</dt>
            <dd>{{ f.valor }}</dd>
          </div>
        </dl>
        <p class="recorrido-estado">
          <span class="recorrido-punto" aria-hidden="true" />{{ paso.estado }}
        </p>
      </figure>
    </div>
    <p class="recorrido-nota">
      Datos de ejemplo. * Los pagos en línea son solo para negocios en México.
    </p>
  </div>
</template>

<style scoped>
.recorrido-pasos {
  display: grid;
  gap: 0.6rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
@media (min-width: 900px) {
  .recorrido-pasos {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
.recorrido-paso {
  position: relative;
  overflow: hidden;
  display: flex;
  gap: 0.7rem;
  align-items: flex-start;
  text-align: left;
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 14px;
  background: var(--superficie);
  cursor: pointer;
  transition:
    border-color 0.2s,
    box-shadow 0.2s,
    transform 0.2s;
}
.recorrido-paso:hover {
  transform: translateY(-2px);
  border-color: color-mix(in srgb, var(--acento) 45%, var(--borde));
}
.recorrido-paso[aria-selected="true"] {
  border-color: var(--acento);
  box-shadow: 0 10px 26px -18px rgb(13 59 110 / 35%);
}
.recorrido-paso--auto::after {
  content: "";
  position: absolute;
  left: 0;
  bottom: 0;
  height: 3px;
  width: 100%;
  background: #3a8fd4;
  transform-origin: 0 50%;
  animation: recorrido-progreso 4.2s linear infinite;
}
@keyframes recorrido-progreso {
  from {
    transform: scaleX(0);
  }
  to {
    transform: scaleX(1);
  }
}
.recorrido-num {
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
  width: 1.6rem;
  height: 1.6rem;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 600;
  background: var(--superficie-2);
}
.recorrido-paso[aria-selected="true"] .recorrido-num {
  background: var(--primario);
  color: var(--sobre-primario, #fff);
}
.recorrido-paso-texto {
  display: grid;
  gap: 0.1rem;
}
.recorrido-quien {
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.recorrido-titulo {
  font-size: 0.9rem;
  font-weight: 600;
  line-height: 1.3;
}
.recorrido-panel {
  display: grid;
  gap: 1.5rem;
  margin-top: 1.25rem;
  padding: 1.5rem;
  border: 1px solid var(--borde);
  border-radius: 18px;
  background: var(--superficie);
}
@media (min-width: 900px) {
  .recorrido-panel {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr);
    align-items: center;
  }
}
.recorrido-explica h3 {
  margin-top: 0.3rem;
  font-size: 1.35rem;
  font-weight: 600;
  letter-spacing: -0.01em;
}
.recorrido-explica > p:not(.recorrido-quien) {
  margin-top: 0.6rem;
  color: var(--texto-suave);
}
.recorrido-navegar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 1.2rem;
}
.recorrido-play {
  display: inline-grid;
  place-items: center;
  width: 2.5rem;
  height: 2.5rem;
  border: 1px solid color-mix(in srgb, var(--acento) 30%, var(--borde));
  border-radius: 999px;
  background: color-mix(in srgb, var(--acento) 10%, var(--superficie));
  color: var(--primario);
  font-size: 0.75rem;
  cursor: pointer;
  transition:
    background-color 0.2s,
    transform 0.2s;
}
.recorrido-play:hover {
  background: color-mix(in srgb, var(--acento) 20%, var(--superficie));
  transform: scale(1.05);
}
.recorrido-pantalla {
  padding: 1.1rem 1.25rem;
  border: 1px solid color-mix(in srgb, var(--acento) 24%, var(--borde));
  border-radius: 16px;
  background: var(--fondo);
  box-shadow: 0 16px 36px -24px rgb(13 59 110 / 26%);
}
.recorrido-ventana-barra {
  display: flex;
  align-items: center;
  gap: 0.38rem;
  padding-bottom: 0.65rem;
  border-bottom: 1px solid color-mix(in srgb, var(--borde) 70%, transparent);
}
.recorrido-ventana-punto {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--acento) 42%, var(--borde));
}
.recorrido-ventana-titulo {
  margin-left: 0.35rem;
  font-size: 0.74rem;
  font-weight: 600;
  color: var(--primario);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.recorrido-dl-anim {
  animation: recorrido-fade 0.32s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes recorrido-fade {
  from {
    opacity: 0.35;
    transform: translateY(6px);
  }
  to {
    opacity: 1;
    transform: none;
  }
}
.recorrido-pantalla dl {
  display: grid;
  gap: 0.15rem;
  margin-top: 0.7rem;
}
.recorrido-pantalla dl > div {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.5rem 0.6rem;
  border-radius: 8px;
  font-size: 0.875rem;
}
.recorrido-pantalla dt {
  color: var(--texto-suave);
}
.recorrido-pantalla dd {
  font-weight: 500;
  text-align: right;
}
.recorrido-clave {
  background: color-mix(in srgb, var(--primario) 9%, transparent);
}
.recorrido-estado {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin-top: 0.8rem;
  font-size: 0.85rem;
  font-weight: 500;
}
.recorrido-punto {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
  background: var(--exito);
}
.recorrido-nota {
  margin-top: 0.8rem;
  font-size: 0.8rem;
  color: var(--texto-suave);
}
</style>
