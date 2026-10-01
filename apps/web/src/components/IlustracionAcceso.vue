<script setup lang="ts">
/**
 * Ilustraciones vectoriales de las tarjetas de acceso (Inicio del negocio y portales
 * del alumno y del instructor). Usan el color de la tarjeta (`--tono`) y los del
 * tema, así se leen igual en claro y en oscuro. Se anclan abajo a la derecha.
 */
export type Ilustracion =
  | "agenda"
  | "miembros"
  | "recepcion"
  | "ventas"
  | "horarios"
  | "reservas"
  | "creditos"
  | "pagos"
  | "pase"
  | "expediente"
  | "configuracion"
  | "alumnos"
  | "semana"
  | "calendario"
  | "perfil";

defineProps<{ nombre: Ilustracion }>();
</script>

<template>
  <svg
    class="ia"
    viewBox="0 0 220 230"
    preserveAspectRatio="xMaxYMax meet"
    aria-hidden="true"
  >
    <!-- Agenda: horario con bloques de color -->
    <g v-if="nombre === 'agenda'" transform="rotate(-7 140 140)">
      <rect x="52" y="36" width="190" height="210" rx="16" class="ia-panel" />
      <text x="66" y="82" class="ia-hora">9:00</text>
      <text x="66" y="128" class="ia-hora">10:00</text>
      <text x="66" y="174" class="ia-hora">11:00</text>
      <text x="66" y="220" class="ia-hora">12:00</text>
      <line x1="100" y1="86" x2="235" y2="86" class="ia-linea" />
      <line x1="100" y1="132" x2="235" y2="132" class="ia-linea" />
      <line x1="100" y1="178" x2="235" y2="178" class="ia-linea" />
      <rect x="112" y="58" width="92" height="38" rx="8" class="ia-b-morado" />
      <rect x="122" y="70" width="40" height="6" rx="3" class="ia-b-texto" />
      <rect x="140" y="106" width="92" height="38" rx="8" class="ia-b-verde" />
      <rect x="150" y="118" width="46" height="6" rx="3" class="ia-b-texto" />
      <rect x="104" y="152" width="96" height="38" rx="8" class="ia-b-azul" />
      <rect x="114" y="164" width="52" height="6" rx="3" class="ia-b-texto" />
    </g>

    <!-- Miembros: persona y su ficha -->
    <g v-else-if="nombre === 'miembros'">
      <circle cx="160" cy="74" r="30" class="ia-fuerte" />
      <path d="M112 150a48 48 0 0 1 96 0z" class="ia-fuerte" />
      <circle cx="160" cy="74" r="24" class="ia-medio" />
      <g transform="rotate(-4 140 170)">
        <rect x="62" y="132" width="150" height="76" rx="14" class="ia-panel" />
        <circle cx="94" cy="170" r="17" class="ia-suave" />
        <circle cx="94" cy="164" r="5.5" class="ia-trazo" />
        <path d="M84 180a10 10 0 0 1 20 0" class="ia-trazo" />
        <rect x="122" y="154" width="70" height="8" rx="4" class="ia-renglon" />
        <rect x="122" y="168" width="52" height="8" rx="4" class="ia-renglon" />
        <rect x="122" y="182" width="60" height="8" rx="4" class="ia-renglon" />
      </g>
    </g>

    <!-- Recepción: lector y celular con QR -->
    <g v-else-if="nombre === 'recepcion'">
      <rect x="58" y="96" width="70" height="120" rx="14" class="ia-oscuro" />
      <rect x="70" y="110" width="46" height="30" rx="6" class="ia-medio" />
      <g transform="rotate(8 160 130)">
        <rect
          x="112"
          y="40"
          width="88"
          height="172"
          rx="18"
          class="ia-telefono"
        />
        <rect x="119" y="52" width="74" height="148" rx="12" class="ia-panel" />
        <g class="ia-qr">
          <rect x="130" y="78" width="16" height="16" rx="2" />
          <rect x="166" y="78" width="16" height="16" rx="2" />
          <rect x="130" y="114" width="16" height="16" rx="2" />
          <rect x="152" y="80" width="6" height="6" />
          <rect x="152" y="92" width="6" height="6" />
          <rect x="152" y="104" width="6" height="6" />
          <rect x="164" y="102" width="6" height="6" />
          <rect x="176" y="104" width="6" height="6" />
          <rect x="152" y="116" width="6" height="6" />
          <rect x="166" y="114" width="6" height="6" />
          <rect x="176" y="122" width="6" height="6" />
          <rect x="158" y="124" width="6" height="6" />
        </g>
        <circle cx="156" cy="166" r="15" class="ia-exito" />
        <path d="M149 166l5 5 9-10" class="ia-palomita" />
      </g>
    </g>

    <!-- Vender: terminal y tarjeta -->
    <g v-else-if="nombre === 'ventas'">
      <g transform="rotate(-14 160 80)">
        <rect
          x="108"
          y="44"
          width="104"
          height="66"
          rx="10"
          class="ia-fuerte"
        />
        <rect x="108" y="60" width="104" height="12" class="ia-banda" />
        <rect x="122" y="84" width="20" height="14" rx="3" class="ia-chip" />
      </g>
      <rect x="64" y="104" width="118" height="122" rx="18" class="ia-oscuro" />
      <rect x="78" y="118" width="90" height="34" rx="7" class="ia-medio" />
      <g class="ia-teclas">
        <rect x="80" y="162" width="24" height="12" rx="4" />
        <rect x="111" y="162" width="24" height="12" rx="4" />
        <rect x="142" y="162" width="24" height="12" rx="4" />
        <rect x="80" y="180" width="24" height="12" rx="4" />
        <rect x="111" y="180" width="24" height="12" rx="4" />
        <rect x="142" y="180" width="24" height="12" rx="4" />
        <rect x="80" y="198" width="24" height="12" rx="4" />
        <rect x="111" y="198" width="24" height="12" rx="4" />
        <rect x="142" y="198" width="24" height="12" rx="4" />
      </g>
    </g>

    <!-- Horarios: reloj y la semana en bloques -->
    <g v-else-if="nombre === 'horarios'">
      <circle cx="150" cy="104" r="62" class="ia-panel" />
      <circle cx="150" cy="104" r="62" class="ia-aro" />
      <path d="M150 70v34l22 14" class="ia-manecillas" />
      <rect x="70" y="186" width="44" height="26" rx="7" class="ia-medio" />
      <rect x="122" y="186" width="44" height="26" rx="7" class="ia-fuerte" />
      <rect x="174" y="186" width="44" height="26" rx="7" class="ia-suave" />
    </g>

    <!-- Reservas: boletos con palomita -->
    <g v-else-if="nombre === 'reservas'">
      <g transform="rotate(-10 150 100)">
        <rect x="70" y="62" width="150" height="66" rx="12" class="ia-medio" />
      </g>
      <g transform="rotate(4 140 160)">
        <rect x="56" y="120" width="160" height="72" rx="12" class="ia-panel" />
        <line x1="108" y1="128" x2="108" y2="184" class="ia-punteada" />
        <circle cx="82" cy="156" r="13" class="ia-suave" />
        <rect x="122" y="140" width="72" height="8" rx="4" class="ia-renglon" />
        <rect x="122" y="156" width="50" height="8" rx="4" class="ia-renglon" />
        <rect x="122" y="170" width="60" height="6" rx="3" class="ia-medio" />
      </g>
      <circle cx="196" cy="198" r="16" class="ia-exito" />
      <path d="M189 198l5 5 9-10" class="ia-palomita" />
    </g>

    <!-- Créditos: tarjeta de sellos -->
    <g v-else-if="nombre === 'creditos'" transform="rotate(-6 140 140)">
      <rect x="48" y="78" width="172" height="112" rx="16" class="ia-panel" />
      <rect x="64" y="94" width="80" height="10" rx="5" class="ia-medio" />
      <g class="ia-sello-lleno">
        <circle cx="76" cy="132" r="10" />
        <circle cx="106" cy="132" r="10" />
        <circle cx="136" cy="132" r="10" />
        <circle cx="166" cy="132" r="10" />
        <circle cx="196" cy="132" r="10" />
        <circle cx="76" cy="164" r="10" />
        <circle cx="106" cy="164" r="10" />
      </g>
      <g class="ia-sello-vacio">
        <circle cx="136" cy="164" r="10" />
        <circle cx="166" cy="164" r="10" />
        <circle cx="196" cy="164" r="10" />
      </g>
    </g>

    <!-- Pagos: recibo con su total -->
    <g v-else-if="nombre === 'pagos'">
      <g transform="rotate(5 150 130)">
        <path
          d="M82 36h112v170l-14-10-14 10-14-10-14 10-14-10-14 10-14-10-14 10z"
          class="ia-panel"
        />
        <rect x="98" y="58" width="60" height="10" rx="5" class="ia-medio" />
        <rect x="98" y="86" width="80" height="7" rx="3.5" class="ia-renglon" />
        <rect
          x="98"
          y="102"
          width="64"
          height="7"
          rx="3.5"
          class="ia-renglon"
        />
        <rect
          x="98"
          y="118"
          width="72"
          height="7"
          rx="3.5"
          class="ia-renglon"
        />
        <line x1="98" y1="142" x2="178" y2="142" class="ia-linea" />
        <rect x="98" y="154" width="36" height="10" rx="5" class="ia-renglon" />
        <rect x="146" y="152" width="32" height="14" rx="5" class="ia-fuerte" />
      </g>
      <circle cx="80" cy="190" r="18" class="ia-exito" />
      <path d="M72 190l6 6 10-11" class="ia-palomita" />
    </g>

    <!-- Pase: celular con QR -->
    <g v-else-if="nombre === 'pase'" transform="rotate(8 150 130)">
      <rect x="92" y="34" width="96" height="186" rx="20" class="ia-telefono" />
      <rect x="100" y="48" width="80" height="158" rx="13" class="ia-panel" />
      <rect x="112" y="62" width="56" height="8" rx="4" class="ia-medio" />
      <g class="ia-qr">
        <rect x="114" y="84" width="18" height="18" rx="2" />
        <rect x="148" y="84" width="18" height="18" rx="2" />
        <rect x="114" y="118" width="18" height="18" rx="2" />
        <rect x="138" y="86" width="6" height="6" />
        <rect x="138" y="98" width="6" height="6" />
        <rect x="138" y="110" width="6" height="6" />
        <rect x="150" y="108" width="6" height="6" />
        <rect x="160" y="112" width="6" height="6" />
        <rect x="138" y="122" width="6" height="6" />
        <rect x="150" y="124" width="6" height="6" />
        <rect x="160" y="130" width="6" height="6" />
      </g>
      <rect x="112" y="154" width="56" height="12" rx="6" class="ia-fuerte" />
      <rect x="120" y="176" width="40" height="6" rx="3" class="ia-renglon" />
    </g>

    <!-- Expediente: documento firmado -->
    <g v-else-if="nombre === 'expediente'">
      <rect x="74" y="60" width="140" height="150" rx="14" class="ia-medio" />
      <g transform="rotate(-5 140 130)">
        <rect x="64" y="44" width="130" height="164" rx="12" class="ia-panel" />
        <rect x="80" y="64" width="64" height="10" rx="5" class="ia-fuerte" />
        <rect x="80" y="90" width="96" height="7" rx="3.5" class="ia-renglon" />
        <rect
          x="80"
          y="106"
          width="84"
          height="7"
          rx="3.5"
          class="ia-renglon"
        />
        <rect
          x="80"
          y="122"
          width="92"
          height="7"
          rx="3.5"
          class="ia-renglon"
        />
        <path
          d="M82 172c10-16 18-16 20-4s8 10 14-2 12-8 16 2 10 6 18-4"
          class="ia-firma"
        />
        <line x1="80" y1="184" x2="176" y2="184" class="ia-linea" />
      </g>
      <circle cx="194" cy="196" r="16" class="ia-exito" />
      <path d="M187 196l5 5 9-10" class="ia-palomita" />
    </g>

    <!-- Configuración: interruptores y engrane -->
    <g v-else-if="nombre === 'configuracion'">
      <rect x="56" y="70" width="160" height="140" rx="16" class="ia-panel" />
      <rect x="74" y="94" width="64" height="8" rx="4" class="ia-renglon" />
      <rect x="160" y="88" width="38" height="20" rx="10" class="ia-fuerte" />
      <circle cx="188" cy="98" r="7" class="ia-blanco" />
      <rect x="74" y="134" width="56" height="8" rx="4" class="ia-renglon" />
      <rect x="160" y="128" width="38" height="20" rx="10" class="ia-apagado" />
      <circle cx="170" cy="138" r="7" class="ia-blanco" />
      <rect x="74" y="174" width="70" height="8" rx="4" class="ia-renglon" />
      <rect x="160" y="168" width="38" height="20" rx="10" class="ia-fuerte" />
      <circle cx="188" cy="178" r="7" class="ia-blanco" />
      <circle cx="176" cy="54" r="26" class="ia-medio" />
      <circle cx="176" cy="54" r="10" class="ia-panel" />
      <path
        d="M176 22v10M176 76v10M144 54h10M198 54h10M153 31l7 7M192 70l7 7M153 77l7-7M192 38l7-7"
        class="ia-trazo-grueso"
      />
    </g>

    <!-- Alumnos: el grupo de la clase -->
    <g v-else-if="nombre === 'alumnos'">
      <circle cx="104" cy="98" r="22" class="ia-medio" />
      <path d="M68 168a36 36 0 0 1 72 0z" class="ia-medio" />
      <circle cx="184" cy="98" r="22" class="ia-suave" />
      <path d="M148 168a36 36 0 0 1 72 0z" class="ia-suave" />
      <circle cx="144" cy="84" r="26" class="ia-fuerte" />
      <path d="M100 170a44 44 0 0 1 88 0z" class="ia-fuerte" />
      <rect x="70" y="178" width="148" height="40" rx="12" class="ia-panel" />
      <rect x="86" y="194" width="58" height="8" rx="4" class="ia-renglon" />
      <rect x="168" y="190" width="34" height="16" rx="8" class="ia-medio" />
    </g>

    <!-- Semana: renglones por día -->
    <g v-else-if="nombre === 'semana'">
      <rect x="52" y="44" width="168" height="176" rx="16" class="ia-panel" />
      <g v-for="(largo, i) in [86, 60, 104, 74, 92]" :key="i">
        <rect
          x="66"
          :y="64 + i * 30"
          width="24"
          height="18"
          rx="6"
          class="ia-suave"
        />
        <rect
          x="100"
          :y="68 + i * 30"
          :width="largo"
          height="10"
          rx="5"
          :class="i % 2 === 0 ? 'ia-fuerte' : 'ia-medio'"
        />
      </g>
    </g>

    <!-- Calendario: el mes con días marcados -->
    <g v-else-if="nombre === 'calendario'" transform="rotate(-5 140 140)">
      <rect x="50" y="44" width="176" height="176" rx="16" class="ia-panel" />
      <rect x="50" y="44" width="176" height="36" rx="16" class="ia-fuerte" />
      <rect x="50" y="64" width="176" height="16" class="ia-fuerte" />
      <g v-for="fila in 4" :key="fila">
        <rect
          v-for="col in 6"
          :key="col"
          :x="62 + (col - 1) * 26"
          :y="92 + (fila - 1) * 30"
          width="18"
          height="18"
          rx="5"
          :class="(fila * 6 + col) % 5 === 0 ? 'ia-fuerte' : 'ia-renglon'"
        />
      </g>
    </g>

    <!-- Perfil: la ficha de quien entra -->
    <g v-else transform="rotate(-4 140 140)">
      <rect x="62" y="56" width="150" height="160" rx="18" class="ia-panel" />
      <rect x="62" y="56" width="150" height="46" rx="18" class="ia-medio" />
      <rect x="62" y="84" width="150" height="18" class="ia-medio" />
      <circle cx="137" cy="104" r="26" class="ia-fuerte" />
      <circle cx="137" cy="98" r="8" class="ia-blanco" />
      <path d="M123 118a14 14 0 0 1 28 0" class="ia-trazo-blanco" />
      <rect x="102" y="144" width="70" height="9" rx="4.5" class="ia-renglon" />
      <rect x="92" y="162" width="90" height="7" rx="3.5" class="ia-renglon" />
      <rect x="110" y="186" width="54" height="14" rx="7" class="ia-medio" />
    </g>
  </svg>
</template>

<style scoped>
.ia-panel {
  fill: var(--superficie);
  stroke: var(--borde);
  stroke-width: 1.5;
}
.ia-hora {
  font-size: 10px;
  fill: var(--texto-suave);
}
.ia-linea {
  stroke: var(--borde);
  stroke-width: 1.2;
}
.ia-punteada {
  stroke: var(--borde);
  stroke-width: 2;
  stroke-dasharray: 4 4;
}
.ia-b-morado {
  fill: color-mix(in srgb, #8b5cf6 30%, var(--superficie));
}
.ia-b-verde {
  fill: color-mix(in srgb, #16a34a 26%, var(--superficie));
}
.ia-b-azul {
  fill: color-mix(in srgb, #3b82f6 28%, var(--superficie));
}
.ia-b-texto {
  fill: color-mix(in srgb, var(--texto) 45%, transparent);
}
.ia-suave {
  fill: color-mix(in srgb, var(--tono) 16%, var(--superficie));
}
.ia-medio {
  fill: color-mix(in srgb, var(--tono) 40%, var(--superficie));
}
.ia-fuerte {
  fill: color-mix(in srgb, var(--tono) 70%, var(--superficie));
}
.ia-apagado {
  fill: var(--superficie-2);
  stroke: var(--borde);
}
.ia-blanco {
  fill: #fff;
}
.ia-trazo {
  fill: none;
  stroke: var(--tono);
  stroke-width: 2.4;
  stroke-linecap: round;
}
.ia-trazo-grueso {
  fill: none;
  stroke: color-mix(in srgb, var(--tono) 40%, var(--superficie));
  stroke-width: 8;
  stroke-linecap: round;
}
.ia-trazo-blanco {
  fill: none;
  stroke: #fff;
  stroke-width: 3;
  stroke-linecap: round;
}
.ia-firma {
  fill: none;
  stroke: var(--tono);
  stroke-width: 3;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.ia-renglon {
  fill: var(--superficie-2);
}
.ia-oscuro {
  fill: #2b3140;
}
.ia-telefono {
  fill: #1e2a3b;
}
.ia-qr {
  fill: #1e2a3b;
}
.ia-exito {
  fill: #16a34a;
}
.ia-palomita {
  fill: none;
  stroke: #fff;
  stroke-width: 3;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.ia-banda {
  fill: rgb(0 0 0 / 22%);
}
.ia-chip {
  fill: #f5d46b;
}
.ia-teclas {
  fill: #4b5263;
}
.ia-aro {
  fill: none;
  stroke: var(--tono);
  stroke-width: 5;
}
.ia-manecillas {
  fill: none;
  stroke: var(--texto);
  stroke-width: 5;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.ia-sello-lleno {
  fill: color-mix(in srgb, var(--tono) 70%, var(--superficie));
}
.ia-sello-vacio {
  fill: var(--superficie);
  stroke: color-mix(in srgb, var(--tono) 45%, var(--superficie));
  stroke-width: 2;
  stroke-dasharray: 3 3;
}
</style>
