<script setup lang="ts">
import { computed } from "vue";

import {
  canalesDeBandeja,
  hayPlataformas,
  listaNatural,
} from "@/marketing/integraciones";
import { nombresDePlataformas, type Modo } from "@/marketing/modalidades";

/**
 * «Reemplaza estas herramientas» y lo que incluye la suscripción (comparativo del
 * 2026-10-20): lo que un negocio paga hoy por separado contra un solo pago. Sin cifras
 * inventadas, sin nombres de competidores y sin decir que nadie más lo da (no se puede
 * sostener). Cada modalidad dice solo lo que su plan incluye, y solo lo comprobado
 * (ADR 0234): sin bandeja ni plataformas, esas filas no salen.
 */
const props = defineProps<{ modo: Modo }>();

const HERRAMIENTAS: { hoy: string; con: string; requiere?: () => boolean }[] = [
  {
    hoy: "Hoja de cálculo para la agenda y los saldos",
    con: "AGENDA_SALDOS",
  },
  {
    hoy: "WhatsApp a mano para confirmar y recordar",
    con: "Recordatorios y avisos automáticos",
  },
  {
    hoy: "Terminal y libreta para los cobros",
    con: "Caja con arqueo, cobro mixto y pago en línea*",
  },
  {
    hoy: "CALENDARIOS",
    con: "AGENDA_SEDES",
  },
  {
    hoy: "Correo masivo aparte",
    con: "CAMPANAS",
  },
  { hoy: "Facturación por fuera", con: "CFDI" },
  {
    hoy: "BANDEJA_HOY",
    con: "BANDEJA",
    requiere: () => canalesDeBandeja().length > 0,
  },
  {
    hoy: "EXTRA_HOY",
    con: "EXTRA_CON",
    requiere: () => props.modo !== "clases" || hayPlataformas(),
  },
];

// Las palabras de cada modalidad (la página de clases no habla de profesionales; la
// de citas, de cupos).
const POR_MODO: Record<Modo, Record<string, string>> = {
  clases: {
    AGENDA_SALDOS: "Agenda, cupos y créditos con su historial",
    CALENDARIOS: "Un calendario por instructor o por sala",
    AGENDA_SEDES: "Una agenda con sedes, salas y equipo",
    // Wellhub, TotalPass y Fitpass (ADR 0202); Fitpass, solo en México.
    EXTRA_HOY: "El portal de cada app de bienestar, por separado",
    EXTRA_CON: `${listaNatural(nombresDePlataformas().map((n) => (n === "Fitpass" ? "Fitpass*" : n)))} en tu misma agenda`,
    CAMPANAS: "Campañas y segmentos con tus propios clientes",
    CFDI: "CFDI a tus clientes desde la venta*",
    BANDEJA: "Una bandeja de mensajes para todo tu equipo",
  },
  citas: {
    AGENDA_SALDOS: "Agenda, bonos y saldos con su historial",
    CALENDARIOS: "Un calendario por profesional o por cabina",
    AGENDA_SEDES: "Una agenda con sedes, cabinas y equipo",
    // Presupuestos en línea (ADR 0127).
    EXTRA_HOY: "Cotizaciones en un documento aparte",
    EXTRA_CON: "Presupuestos que se aceptan y pagan en línea*",
    // En citas, del plan que las incluye (FuncionesPlan, ADR 0107).
    CAMPANAS: "Campañas y segmentos con tus propios clientes (Pro)",
    CFDI: "CFDI a tus clientes desde la venta* (Pro)",
    BANDEJA: "Una bandeja de mensajes para todo tu equipo (Pro)",
  },
};
const herramientas = computed(() =>
  HERRAMIENTAS.filter((h) => h.requiere?.() ?? true).map((h) => ({
    hoy:
      h.hoy === "BANDEJA_HOY"
        ? `${listaNatural(canalesDeBandeja())} en el celular de alguien`
        : (POR_MODO[props.modo][h.hoy] ?? h.hoy),
    con: POR_MODO[props.modo][h.con] ?? h.con,
  })),
);

// Lo que incluye la suscripción, por modalidad: en citas, varias sedes y el CFDI van
// en los planes que los incluyen. La app con tu marca se cobra aparte (ArranqueYReferidos).
const INCLUIDO: Record<Modo, string[]> = {
  clases: [
    "0% de comisión por tus ventas y pagos",
    "Todas tus sedes sin costo extra",
    "CFDI a tus clientes, con cancelación y notas de crédito*",
    "Caja con arqueo a ciegas y cierre revisado",
    "Tu política visible antes de reservar",
    "Tus datos son tuyos: los descargas cuando quieras",
  ],
  citas: [
    "0% de comisión por tus ventas y pagos",
    "Caja con arqueo a ciegas y cierre revisado",
    "Tu política visible antes de agendar",
    "Anticipo al agendar, si lo pides",
    "Tus datos son tuyos: los descargas cuando quieras",
  ],
};
const incluido = computed(() => INCLUIDO[props.modo]);
</script>

<template>
  <div class="reemplaza" data-prueba="reemplaza-herramientas">
    <div class="reemplaza-tabla" role="table" aria-label="Lo que reemplazas">
      <div class="reemplaza-fila reemplaza-cabeza" role="row">
        <span role="columnheader">Hoy, por separado</span>
        <span role="columnheader">Con un solo pago</span>
      </div>
      <div
        v-for="h in herramientas"
        :key="h.hoy"
        class="reemplaza-fila"
        role="row"
      >
        <span role="cell" class="reemplaza-hoy">{{ h.hoy }}</span>
        <span role="cell" class="reemplaza-con">
          <svg
            class="reemplaza-flecha"
            viewBox="0 0 18 18"
            width="15"
            height="15"
            fill="none"
            aria-hidden="true"
          >
            <circle cx="9" cy="9" r="8" fill="#3a8fd4" fill-opacity="0.16" />
            <path
              d="M5.5 9h7m-3-3 3 3-3 3"
              stroke="#0d3b6e"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          <span>{{ h.con }}</span>
        </span>
      </div>
    </div>
    <div class="solo-aqui">
      <h3>Incluido en tu suscripción</h3>
      <ul>
        <li v-for="x in incluido" :key="x">{{ x }}</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.reemplaza {
  display: grid;
  gap: 1.25rem;
  grid-template-columns: minmax(0, 1fr);
}
@media (min-width: 900px) {
  .reemplaza {
    grid-template-columns: minmax(0, 3.1fr) minmax(0, 1.9fr);
    align-items: start;
  }
}
.reemplaza-tabla {
  border: 1px solid color-mix(in srgb, var(--acento) 24%, var(--borde));
  border-radius: 18px;
  overflow: hidden;
  background: var(--superficie);
  box-shadow: 0 12px 30px -24px rgb(13 59 110 / 22%);
}
.reemplaza-fila {
  display: grid;
  grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.08fr);
  gap: 0.85rem;
  align-items: center;
  padding: 0.62rem 1rem;
  border-top: 1px solid var(--borde);
  font-size: 0.86rem;
  transition: background-color 0.2s ease;
}
.reemplaza-fila:not(.reemplaza-cabeza):hover {
  background: color-mix(in srgb, #3a8fd4 6%, var(--superficie));
}
.reemplaza-cabeza {
  border-top: 0;
  background: color-mix(in srgb, var(--acento) 10%, var(--superficie));
  font-weight: 600;
  font-size: 0.76rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--primario);
}
.reemplaza-hoy {
  color: var(--texto-suave);
  font-size: 0.82rem;
  text-decoration: line-through;
  text-decoration-color: color-mix(
    in srgb,
    var(--texto-suave) 50%,
    transparent
  );
}
.reemplaza-con {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  font-weight: 500;
  color: var(--texto);
}
.reemplaza-flecha {
  flex-shrink: 0;
}
.solo-aqui {
  padding: 1.35rem;
  border: 1px solid color-mix(in srgb, var(--acento) 26%, var(--borde));
  border-top: 3px solid var(--primario);
  border-radius: 18px;
  background:
    radial-gradient(
      ellipse 70% 60% at 85% 15%,
      color-mix(in srgb, #3a8fd4 14%, transparent) 0%,
      transparent 70%
    ),
    var(--superficie);
  box-shadow: 0 14px 34px -24px rgb(13 59 110 / 24%);
}
.solo-aqui h3 {
  font-weight: 600;
  font-size: 1.02rem;
  color: var(--primario);
}
.solo-aqui ul {
  margin-top: 0.65rem;
  display: grid;
  gap: 0.4rem;
  padding-left: 1.1rem;
  list-style: disc;
  font-size: 0.85rem;
  line-height: 1.45;
}
</style>
