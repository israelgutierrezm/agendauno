<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";

/**
 * El horario de atención de una persona en una semana, de un vistazo: una columna por
 * día con sus franjas de atención y, entre una y otra, el descanso. Un día sin
 * franjas es día de descanso. Al tocar un día (o su franja) se edita ese día.
 */
export interface FranjaHorario {
  hora_inicio: string;
  hora_fin: string;
}

const props = defineProps<{
  semana: Record<number, FranjaHorario[]>;
  puedeGestionar: boolean;
}>();
const emit = defineEmits<{ editar: [dia: number] }>();

const { t } = useI18n();
const DIAS = [1, 2, 3, 4, 5, 6, 7] as const;
const PX_HORA = 30;

function minutos(hhmm: string): number {
  const [h, m] = hhmm.split(":").map(Number);
  return (h ?? 0) * 60 + (m ?? 0);
}
function valida(f: FranjaHorario): boolean {
  return (
    f.hora_inicio !== "" && f.hora_fin !== "" && f.hora_fin > f.hora_inicio
  );
}

// De 7 a 21 h, o más si alguna franja empieza antes o acaba después.
const rango = computed(() => {
  const todas = DIAS.flatMap((d) => props.semana[d] ?? []).filter(valida);
  const ini = Math.min(7 * 60, ...todas.map((f) => minutos(f.hora_inicio)));
  const fin = Math.max(21 * 60, ...todas.map((f) => minutos(f.hora_fin)));
  return { ini: Math.floor(ini / 60) * 60, fin: Math.ceil(fin / 60) * 60 };
});
const horas = computed(() => {
  const lista: number[] = [];
  for (let m = rango.value.ini; m < rango.value.fin; m += 60) {
    lista.push(m);
  }
  return lista;
});
const alto = computed(
  () => ((rango.value.fin - rango.value.ini) / 60) * PX_HORA,
);
const y = (min: number): number => ((min - rango.value.ini) / 60) * PX_HORA;
const hora = (min: number): string =>
  `${String(Math.floor(min / 60)).padStart(2, "0")}:${String(min % 60).padStart(2, "0")}`;

const columnas = computed(() =>
  DIAS.map((d) => {
    const franjas = (props.semana[d] ?? [])
      .filter(valida)
      .map((f) => ({ ini: minutos(f.hora_inicio), fin: minutos(f.hora_fin) }))
      .sort((a, b) => a.ini - b.ini);
    const descansos = franjas.slice(1).flatMap((f, i) => {
      const anterior = franjas[i];
      return anterior && f.ini > anterior.fin
        ? [{ ini: anterior.fin, fin: f.ini }]
        : [];
    });
    return { dia: d, franjas, descansos };
  }),
);
</script>

<template>
  <div class="sh" role="group" :aria-label="t('horariosVisual.semanal')">
    <div class="sh-cabeza">
      <span class="sh-esquina">{{ t("horariosVisual.hora") }}</span>
      <button
        v-for="c in columnas"
        :key="c.dia"
        type="button"
        class="sh-dia"
        :disabled="!puedeGestionar"
        @click="emit('editar', c.dia)"
      >
        <span class="font-medium">{{
          t(`horariosVisual.diasCortos.${c.dia}`)
        }}</span>
        <span class="sh-estado">
          <span
            class="sh-punto"
            :class="c.franjas.length > 0 ? 'sh-punto-activo' : ''"
            aria-hidden="true"
          ></span>
          {{
            c.franjas.length > 0
              ? t("horariosVisual.activo")
              : t("horariosVisual.descanso")
          }}
        </span>
      </button>
    </div>

    <div class="sh-cuerpo" :style="{ height: `${alto}px` }">
      <div class="sh-horas">
        <span
          v-for="h in horas"
          :key="h"
          class="sh-hora"
          :style="{ top: `${y(h)}px` }"
          >{{ hora(h) }}</span
        >
      </div>
      <div v-for="c in columnas" :key="c.dia" class="sh-col">
        <span
          v-for="h in horas"
          :key="h"
          class="sh-linea"
          :style="{ top: `${y(h)}px` }"
          aria-hidden="true"
        ></span>

        <button
          v-for="(f, i) in c.franjas"
          :key="`a${i}`"
          type="button"
          class="sh-bloque sh-atencion"
          :style="{
            top: `${y(f.ini) + 1}px`,
            height: `${y(f.fin) - y(f.ini) - 2}px`,
          }"
          :disabled="!puedeGestionar"
          @click="emit('editar', c.dia)"
        >
          <span class="font-medium">{{ hora(f.ini) }} – {{ hora(f.fin) }}</span>
          <span class="sh-sub">{{ t("horariosVisual.atencion") }}</span>
        </button>
        <div
          v-for="(dsc, i) in c.descansos"
          :key="`d${i}`"
          class="sh-bloque sh-pausa"
          :style="{
            top: `${y(dsc.ini) + 1}px`,
            height: `${y(dsc.fin) - y(dsc.ini) - 2}px`,
          }"
        >
          <span>{{ hora(dsc.ini) }} – {{ hora(dsc.fin) }}</span>
          <span class="sh-sub">{{ t("horariosVisual.descanso") }}</span>
        </div>

        <button
          v-if="c.franjas.length === 0"
          type="button"
          class="sh-libre"
          :disabled="!puedeGestionar"
          @click="emit('editar', c.dia)"
        >
          <IconoNav v-if="puedeGestionar" nombre="mas" :tam="18" />
          {{ t("horariosVisual.diaDescanso") }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sh {
  min-width: 44rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  overflow: hidden;
}
.sh-cabeza,
.sh-cuerpo {
  display: grid;
  grid-template-columns: 3.5rem repeat(7, minmax(0, 1fr));
}
.sh-cabeza {
  border-bottom: 1px solid var(--borde);
  background: var(--superficie-2);
}
.sh-esquina {
  display: grid;
  place-items: center;
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-weight: 500;
}
.sh-dia {
  display: grid;
  justify-items: center;
  gap: 0.1rem;
  padding: 0.55rem 0.25rem;
  border-left: 1px solid var(--borde);
  font-size: 0.85rem;
}
.sh-dia:not(:disabled):hover {
  background: var(--superficie);
}
.sh-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--texto-suave);
  font-size: 0.74rem;
}
.sh-punto {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--texto-suave);
  opacity: 0.5;
}
.sh-punto-activo {
  background: var(--exito);
  opacity: 1;
}
.sh-cuerpo {
  position: relative;
}
.sh-horas {
  position: relative;
}
/* Cada hora, justo bajo su línea (todas igual, sin encimarse arriba). */
.sh-hora {
  position: absolute;
  right: 0.5rem;
  margin-top: 0.15rem;
  color: var(--texto-suave);
  font-size: 0.72rem;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}
.sh-col {
  position: relative;
  border-left: 1px solid var(--borde);
}
.sh-linea {
  position: absolute;
  left: 0;
  right: 0;
  border-top: 1px dashed color-mix(in srgb, var(--borde) 80%, transparent);
}
.sh-bloque {
  position: absolute;
  left: 4px;
  right: 4px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.05rem;
  overflow: hidden;
  padding: 0.3rem 0.45rem;
  border-radius: 0.45rem;
  font-size: 0.74rem;
  text-align: left;
}
.sh-atencion {
  border-left: 3px solid var(--primario);
  background: var(--primario-suave);
  color: var(--primario-fuerte, var(--primario));
}
.sh-atencion:not(:disabled):hover {
  filter: brightness(0.97);
}
.sh-pausa {
  background: var(--superficie-2);
  color: var(--texto-suave);
}
.sh-sub {
  opacity: 0.8;
}
.sh-libre {
  position: absolute;
  top: 50%;
  left: 6px;
  right: 6px;
  transform: translateY(-50%);
  display: grid;
  justify-items: center;
  gap: 0.25rem;
  padding: 1rem 0.25rem;
  border: 1.5px dashed var(--borde);
  border-radius: 0.6rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.sh-libre:not(:disabled):hover {
  border-color: var(--primario);
  color: var(--primario);
}
</style>
