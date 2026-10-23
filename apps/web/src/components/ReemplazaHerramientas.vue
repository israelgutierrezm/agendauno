<script setup lang="ts">
import { computed } from "vue";

import type { Modo } from "@/marketing/modalidades";

/**
 * «Reemplaza estas herramientas» y lo que nadie más te da (comparativo del
 * 2026-10-20): lo que un negocio paga hoy por separado contra un solo pago, y lo que ya
 * hacemos y la competencia no. Sin cifras inventadas ni nombres de competidores. El
 * título de la sección (`landing.porQue.titulo`) dice cuántas filas hay.
 */
const props = defineProps<{ modo: Modo }>();

const HERRAMIENTAS = [
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
    con: "Una agenda con sedes, salas y equipo",
  },
  {
    hoy: "Correo masivo aparte",
    con: "Campañas y segmentos con tus propios clientes",
  },
  { hoy: "Facturación por fuera", con: "CFDI a tus clientes desde la venta*" },
  {
    hoy: "WhatsApp e Instagram en el celular de alguien",
    con: "Una bandeja de mensajes para todo tu equipo",
  },
  { hoy: "EXTRA_HOY", con: "EXTRA_CON" },
];

// Las palabras de cada modalidad (la página de clases no habla de profesionales; la
// de citas, de cupos).
const POR_MODO: Record<Modo, Record<string, string>> = {
  clases: {
    AGENDA_SALDOS: "Agenda, cupos y créditos con su historial",
    CALENDARIOS: "Un calendario por instructor o por sala",
    // Wellhub, TotalPass y Fitpass (ADR 0202); Fitpass, solo en México.
    EXTRA_HOY: "El portal de cada app de bienestar, por separado",
    EXTRA_CON: "Wellhub, TotalPass y Fitpass* en tu misma agenda",
  },
  citas: {
    AGENDA_SALDOS: "Agenda, bonos y saldos con su historial",
    CALENDARIOS: "Un calendario por profesional o por cabina",
    // Presupuestos en línea (ADR 0127).
    EXTRA_HOY: "Cotizaciones en un documento aparte",
    EXTRA_CON: "Presupuestos que se aceptan y pagan en línea*",
  },
};
const herramientas = computed(() =>
  HERRAMIENTAS.map((h) => ({
    hoy: POR_MODO[props.modo][h.hoy] ?? h.hoy,
    con: POR_MODO[props.modo][h.con] ?? h.con,
  })),
);

const SOLO_AQUI = [
  "0% de comisión por tus ventas y pagos",
  "Todas tus sedes sin costo extra",
  "CFDI a tus clientes, con cancelación y notas de crédito*",
  "Caja con arqueo a ciegas y cierre revisado",
  "Tu política visible antes de reservar",
  "App en tiendas con tu marca, si la quieres",
  "Tus datos son tuyos: los descargas cuando quieras",
];
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
        <span role="cell">{{ h.con }}</span>
      </div>
    </div>
    <div class="solo-aqui">
      <h3>Lo que nadie más te da</h3>
      <ul>
        <li v-for="x in SOLO_AQUI" :key="x">{{ x }}</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.reemplaza {
  display: grid;
  gap: 1.5rem;
  grid-template-columns: minmax(0, 1fr);
}
@media (min-width: 900px) {
  .reemplaza {
    grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
  }
}
.reemplaza-tabla {
  border: 1px solid var(--borde);
  border-radius: 16px;
  overflow: hidden;
  background: var(--superficie);
}
.reemplaza-fila {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 1rem;
  padding: 0.8rem 1rem;
  border-top: 1px solid var(--borde);
  font-size: 0.95rem;
}
.reemplaza-cabeza {
  border-top: 0;
  font-weight: 600;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--texto-suave);
}
.reemplaza-hoy {
  color: var(--texto-suave);
  text-decoration: line-through;
  text-decoration-color: color-mix(
    in srgb,
    var(--texto-suave) 50%,
    transparent
  );
}
.solo-aqui {
  padding: 1.25rem;
  border: 1px solid var(--borde);
  border-radius: 16px;
  background: var(--superficie);
}
.solo-aqui h3 {
  font-weight: 600;
  font-size: 1.05rem;
}
.solo-aqui ul {
  margin-top: 0.75rem;
  display: grid;
  gap: 0.45rem;
  padding-left: 1.1rem;
  list-style: disc;
}
</style>
