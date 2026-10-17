<script setup lang="ts">
import { computed, ref, watch } from "vue";

import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import type { Modo } from "@/marketing/modalidades";

/*
| De la reserva al resultado (landing): un mismo caso de principio a fin, en cuatro
| pasos: el cliente reserva → el negocio la recibe → se registra el pago → se consulta
| el resultado. Datos ficticios y sin API (se prerenderiza), con la misma persona y la
| misma clase o cita en cada paso. Solo lo que el producto hace hoy.
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
        "Desde tu página o la app elige la clase y aparta su lugar con los créditos de su paquete.",
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
        "La clase suma un lugar ocupado y Sofía aparece en la lista para pasar asistencia al llegar.",
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
        "Cuando renueva, cobras su paquete en recepción o ella lo paga en línea desde su cuenta*; queda en su ficha y en el corte de caja.",
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
        "Al cerrar el día ves en Inicio la asistencia y lo que falta por cobrar o renovar, y en el corte de caja lo cobrado por forma de pago, sin juntar listas a mano.",
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
        "Desde tu página, sin crear una cuenta: elige el servicio, «cualquier profesional» o uno en particular, y la hora.",
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
        "La cita ocupa el horario de Marco (y su espacio, si el servicio lo pide) y Diego recibe su recordatorio.",
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
        "Al terminar cobras en recepción, o Diego ya pagó en línea al agendar*; queda en su historial y en el corte de caja.",
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
        "Al cerrar el día ves en Inicio las citas atendidas y lo que quedó por cobrar, y en el corte de caja lo cobrado por forma de pago.",
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
watch(
  () => props.modo,
  () => {
    actual.value = 0;
  },
);
const paso = computed(() => pasos.value[actual.value]!);

// Flechas izquierda y derecha entre los pasos (patrón de pestañas).
function mover(delta: number): void {
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
        @click="actual = i"
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
            class="tu-btn tu-btn-fantasma"
            :disabled="actual === 0"
            @click="actual--"
          >
            Anterior
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="actual === pasos.length - 1"
            data-prueba="recorrido-siguiente"
            @click="actual++"
          >
            Siguiente
          </button>
        </div>
      </div>
      <figure class="recorrido-pantalla" aria-label="Pantalla de ejemplo">
        <figcaption>{{ paso.pantalla }}</figcaption>
        <dl>
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
  display: flex;
  gap: 0.7rem;
  align-items: flex-start;
  text-align: left;
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 14px;
  background: var(--superficie);
  transition:
    border-color 0.15s,
    box-shadow 0.15s;
}
.recorrido-paso[aria-selected="true"] {
  border-color: var(--primario);
  box-shadow: 0 0 0 1px var(--primario);
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
  gap: 0.5rem;
  margin-top: 1.2rem;
}
.recorrido-pantalla {
  padding: 1.1rem 1.2rem;
  border: 1px solid var(--borde);
  border-radius: 14px;
  background: var(--fondo);
}
.recorrido-pantalla figcaption {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--texto-suave);
  text-transform: uppercase;
  letter-spacing: 0.04em;
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
