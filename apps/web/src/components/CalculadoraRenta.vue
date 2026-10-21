<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";

import type { Modo } from "@/marketing/modalidades";
import {
  type NivelCitas,
  type PreciosPublicos,
  dolares,
  fechaDelTipo,
  maxAlumnos,
  pesosConIva,
  pesosRedondos,
  tablaCitas,
} from "@/marketing/precios";

/*
| «Calcula tu mensualidad» (landing, sección Precios): con los mismos precios que la
| tabla de al lado (`GET /api/v1/precios` o su respaldo), para que la cifra que alguien
| calcula sea la que verá al contratar.
| - Clases: los alumnos activos del mes eligen una sola banda para todo el mes.
| - Citas: plan y profesionales, al mes o al año (por adelantado).
| Muestra el IVA de México aparte y, con el último FIX publicado, el equivalente en
| pesos con IVA (sin él, cómo se convierte, sin inventar un tipo de cambio).
*/
const props = defineProps<{
  modo: Modo;
  precios: PreciosPublicos;
  /** Al año (citas); lo comparte con la tabla. */
  anual?: boolean;
  /** Con el registro cerrado no se ofrece empezar (prelanzamiento, ADR 0108). */
  registroAbierto?: boolean;
}>();

const alumnos = ref(45);
const nivel = ref<NivelCitas>("premium");
const profesionales = ref(3);

const NIVELES: { nivel: NivelCitas; nombre: string }[] = [
  { nivel: "individual", nombre: "Individual" },
  { nivel: "premium", nombre: "Premium" },
  { nivel: "pro", nombre: "Pro" },
];

const tope = computed(() => maxAlumnos(props.precios.clases.bandas));
const tabla = computed(() => tablaCitas(props.precios.citas.niveles));
const minimoProfesionales = computed(() => tabla.value[0]?.profesionales ?? 2);
const maximoProfesionales = computed(
  () => tabla.value[tabla.value.length - 1]?.profesionales ?? 20,
);
const mesesAnual = computed(() => props.precios.citas.meses_anual);
const iva = computed(() => props.precios[props.modo].iva_porcentaje ?? 16);
const moneda = computed(() => props.precios[props.modo].moneda);

/** El resultado: importe (centavos), qué lo explica y si toca cotizar. */
const resultado = computed<{
  importe: number | null;
  detalle: string;
  periodo: string;
}>(() => {
  if (props.modo === "clases") {
    const n = Math.max(0, Math.floor(Number(alumnos.value) || 0));
    if (tope.value !== null && n > tope.value) {
      return {
        importe: null,
        detalle: `Más de ${tope.value.toLocaleString("es-MX")} alumnos activos: te hacemos una propuesta.`,
        periodo: "",
      };
    }
    const bandas = props.precios.clases.bandas;
    const banda = bandas.find((b) => b.hasta === null || n <= b.hasta);
    const anterior = bandas[bandas.indexOf(banda!) - 1];
    const desde = anterior?.hasta != null ? anterior.hasta + 1 : 1;
    return {
      // Sin alumnos activos, ese mes no se cobra.
      importe: n === 0 ? 0 : (banda?.monto_minor ?? 0),
      detalle:
        n === 0
          ? "Sin alumnos activos ese mes."
          : `Banda de ${desde.toLocaleString("es-MX")} a ${(banda?.hasta ?? n).toLocaleString("es-MX")} alumnos activos: un solo precio para todo el mes, que se cobra al cerrarlo.`,
      periodo: "al mes",
    };
  }
  const niveles = props.precios.citas.niveles;
  if (nivel.value === "individual") {
    const mensual = niveles?.individual?.["1"] ?? 0;
    return {
      importe: props.anual ? mensual * mesesAnual.value : mensual,
      detalle: "Individual: un profesional.",
      periodo: props.anual
        ? "al año, por adelantado"
        : "al mes, por adelantado",
    };
  }
  const n = Math.floor(Number(profesionales.value) || 0);
  if (n > maximoProfesionales.value) {
    return {
      importe: null,
      detalle: `Más de ${maximoProfesionales.value} profesionales: te hacemos una propuesta.`,
      periodo: "",
    };
  }
  const contratados = Math.max(minimoProfesionales.value, n);
  const mensual = niveles?.[nivel.value]?.[String(contratados)] ?? 0;
  const nombre = NIVELES.find((x) => x.nivel === nivel.value)?.nombre ?? "";
  return {
    importe: props.anual ? mensual * mesesAnual.value : mensual,
    detalle: `${nombre} con ${contratados} profesionales${props.anual ? `: ${mesesAnual.value} meses por 12 de servicio` : ""}.`,
    periodo: props.anual ? "al año, por adelantado" : "al mes, por adelantado",
  };
});

// El IVA de México sobre el importe, en la misma moneda.
const conIva = computed(() => {
  const importe = resultado.value.importe;
  if (importe === null || importe === 0) return null;
  const impuesto = Math.round((importe * iva.value) / 100);
  return { impuesto, total: importe + impuesto };
});

// En pesos con IVA al último FIX conocido (si la tarifa es en dólares y hay uno).
const enPesos = computed(() => {
  const importe = resultado.value.importe;
  const tipo = props.precios.tipo_cambio;
  if (importe === null || importe === 0 || moneda.value !== "USD" || !tipo) {
    return null;
  }
  return {
    total: pesosRedondos(pesosConIva(importe, iva.value, tipo)),
    fecha: fechaDelTipo(tipo.fecha),
    tipo: (tipo.diezmilesimas / 10000).toFixed(4),
  };
});
</script>

<template>
  <section
    class="calculadora"
    aria-labelledby="calculadora-titulo"
    data-prueba="calculadora-renta"
  >
    <h3 id="calculadora-titulo">Calcula tu mensualidad</h3>
    <div class="calculadora-campos">
      <label v-if="modo === 'clases'">
        <span>Alumnos activos en el mes</span>
        <input
          v-model.number="alumnos"
          class="tu-input"
          type="number"
          min="0"
          inputmode="numeric"
          data-prueba="calculadora-alumnos"
        />
      </label>
      <template v-else>
        <label>
          <span>Plan</span>
          <select
            v-model="nivel"
            class="tu-input"
            data-prueba="calculadora-plan"
          >
            <option v-for="n in NIVELES" :key="n.nivel" :value="n.nivel">
              {{ n.nombre }}
            </option>
          </select>
        </label>
        <label v-if="nivel !== 'individual'">
          <span>Profesionales que contratas</span>
          <input
            v-model.number="profesionales"
            class="tu-input"
            type="number"
            :min="minimoProfesionales"
            inputmode="numeric"
            data-prueba="calculadora-profesionales"
          />
        </label>
      </template>
    </div>
    <div class="calculadora-resultado" aria-live="polite" aria-atomic="true">
      <p v-if="resultado.importe !== null" class="calculadora-importe">
        <strong data-prueba="calculadora-importe">{{
          dolares(resultado.importe)
        }}</strong>
        {{ moneda }} {{ resultado.periodo }}
      </p>
      <p class="calculadora-detalle">{{ resultado.detalle }}</p>
      <p
        v-if="enPesos"
        class="calculadora-pesos"
        data-prueba="calculadora-pesos"
      >
        En México, unos <strong>{{ enPesos.total }}</strong> MXN
        {{ resultado.periodo }} con IVA, al tipo de cambio FIX del
        {{ enPesos.fecha }} ({{ enPesos.tipo }} pesos por dólar).
      </p>
      <p
        v-if="conIva"
        class="calculadora-detalle"
        data-prueba="calculadora-iva"
      >
        En México: {{ dolares(resultado.importe ?? 0) }} + IVA {{ iva }} % ({{
          dolares(conIva.impuesto)
        }}) = {{ dolares(conIva.total) }} {{ moneda
        }}<template v-if="moneda !== 'MXN'"
          >, que se cobran en pesos al tipo de cambio FIX del Banco de México
          vigente el día en que se emite el cargo</template
        >. Fuera de México, sin IVA.
      </p>
      <!-- El siguiente paso, desde la cifra (comparativo del 2026-10-20). -->
      <RouterLink
        v-if="registroAbierto !== false"
        class="tu-btn tu-btn-primario calculadora-cta"
        :to="{ name: 'registro', query: { modo } }"
        data-prueba="calculadora-cta"
        >Empezar con esta cifra</RouterLink
      >
    </div>
  </section>
</template>

<style scoped>
.calculadora {
  margin-top: 1.5rem;
  padding: 1.5rem;
  border: 1px solid var(--borde);
  border-radius: 16px;
  background: var(--superficie);
}
.calculadora-cta {
  margin-top: 0.85rem;
  display: inline-block;
}
.calculadora-pesos {
  margin-top: 0.5rem;
  font-size: 0.95rem;
}
.calculadora h3 {
  font-weight: 600;
  font-size: 1.05rem;
}
.calculadora-campos {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-top: 0.9rem;
}
.calculadora-campos label {
  display: grid;
  gap: 0.3rem;
  flex: 1 1 12rem;
  font-size: 0.875rem;
  color: var(--texto-suave);
}
.calculadora-resultado {
  margin-top: 1rem;
}
.calculadora-importe {
  font-size: 0.95rem;
}
.calculadora-importe strong {
  font-size: 1.6rem;
  font-weight: 650;
  margin-right: 0.25rem;
  color: var(--texto);
}
.calculadora-detalle {
  margin-top: 0.35rem;
  font-size: 0.875rem;
  color: var(--texto-suave);
}
</style>
