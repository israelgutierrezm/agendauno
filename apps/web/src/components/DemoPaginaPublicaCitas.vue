<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

/*
| Ejemplo de la página para agendar de un negocio de citas (/citas): servicio →
| profesional (con «Cualquier profesional», ADR 0062) → hora. Datos ficticios y
| estado local: sin API ni stores, se prerenderiza y funciona igual sin cuenta.
| «Cualquier profesional» muestra las horas libres de todo el equipo; un profesional
| en particular, solo las suyas. Los rótulos van en `landing.citas.pagina.demo.*`;
| los datos ficticios (negocio, servicios, equipo y horas), aquí.
*/
const { t } = useI18n();
const SERVICIOS = [
  {
    clave: "corte",
    nombre: "Corte de cabello",
    duracion: "45 min",
    precio: 250,
  },
  {
    clave: "corte-barba",
    nombre: "Corte y barba",
    duracion: "60 min",
    precio: 350,
  },
  {
    clave: "barba",
    nombre: "Arreglo de barba",
    duracion: "30 min",
    precio: 180,
  },
] as const;

const CUALQUIERA = "cualquiera";
const PROFESIONALES = [
  { clave: "marco", nombre: "Marco", horas: ["10:00", "13:00", "17:30"] },
  { clave: "luis", nombre: "Luis", horas: ["11:30", "16:00"] },
  { clave: "alex", nombre: "Alex", horas: ["10:00", "11:30", "18:30"] },
] as const;

const servicio = ref<string>("corte-barba");
const profesional = ref<string>(CUALQUIERA);
const hora = ref<string>("17:30");

const horas = computed<string[]>(() => {
  const elegidos =
    profesional.value === CUALQUIERA
      ? PROFESIONALES
      : PROFESIONALES.filter((p) => p.clave === profesional.value);
  return [...new Set(elegidos.flatMap((p) => p.horas))].sort();
});

const resumen = computed(() => {
  const s = SERVICIOS.find((x) => x.clave === servicio.value)!;
  const con =
    PROFESIONALES.find((p) => p.clave === profesional.value)?.nombre ??
    t("landing.citas.pagina.demo.cualquiera");
  return t("landing.citas.pagina.demo.resumen", {
    servicio: s.nombre,
    hora: hora.value,
    con,
  });
});

function elegirProfesional(clave: string): void {
  profesional.value = clave;
  // La hora elegida ya no está libre con esa persona: la primera que sí.
  if (!horas.value.includes(hora.value)) {
    hora.value = horas.value[0] ?? "";
  }
}

function precio(minor: number): string {
  return `$${minor}`;
}
</script>

<template>
  <div
    class="demo-citas"
    role="group"
    :aria-label="t('landing.citas.pagina.demo.aria')"
  >
    <div class="demo-citas-cabecera">
      <span class="demo-citas-logo" aria-hidden="true">BN</span>
      <div class="min-w-0">
        <p class="demo-citas-nombre">Barbería Norte</p>
        <p class="demo-citas-suave">Roma · Ciudad de México</p>
      </div>
      <span class="demo-citas-ejemplo">{{
        t("landing.citas.pagina.demo.ejemplo")
      }}</span>
    </div>

    <div class="demo-citas-contenido">
      <div role="group" aria-labelledby="demo-citas-paso-servicio">
        <p id="demo-citas-paso-servicio" class="demo-citas-paso">
          <span aria-hidden="true">1</span>
          {{ t("landing.citas.pagina.demo.servicio") }}
        </p>
        <div class="demo-citas-servicios">
          <button
            v-for="s in SERVICIOS"
            :key="s.clave"
            type="button"
            class="demo-citas-servicio"
            :aria-pressed="servicio === s.clave"
            @click="servicio = s.clave"
          >
            <span class="demo-citas-servicio-nombre">{{ s.nombre }}</span>
            <span class="demo-citas-suave"
              >{{ s.duracion }} · {{ precio(s.precio) }}</span
            >
          </button>
        </div>
      </div>

      <div role="group" aria-labelledby="demo-citas-paso-profesional">
        <p id="demo-citas-paso-profesional" class="demo-citas-paso">
          <span aria-hidden="true">2</span>
          {{ t("landing.citas.pagina.demo.conQuien") }}
        </p>
        <div class="demo-citas-opciones">
          <button
            type="button"
            class="demo-citas-opcion"
            data-profesional="cualquiera"
            :aria-pressed="profesional === CUALQUIERA"
            @click="elegirProfesional(CUALQUIERA)"
          >
            {{ t("landing.citas.pagina.demo.cualquiera") }}
          </button>
          <button
            v-for="p in PROFESIONALES"
            :key="p.clave"
            type="button"
            class="demo-citas-opcion"
            :data-profesional="p.clave"
            :aria-pressed="profesional === p.clave"
            @click="elegirProfesional(p.clave)"
          >
            {{ p.nombre }}
          </button>
        </div>
      </div>

      <div role="group" aria-labelledby="demo-citas-paso-hora">
        <p id="demo-citas-paso-hora" class="demo-citas-paso">
          <span aria-hidden="true">3</span>
          {{ t("landing.citas.pagina.demo.hora") }}
        </p>
        <div class="demo-citas-opciones">
          <button
            v-for="h in horas"
            :key="h"
            type="button"
            class="demo-citas-opcion demo-citas-hora"
            :aria-pressed="hora === h"
            @click="hora = h"
          >
            {{ h }}
          </button>
        </div>
      </div>

      <p class="demo-citas-resumen" aria-live="polite">{{ resumen }}</p>
      <span class="demo-citas-boton" aria-hidden="true">{{
        t("landing.citas.pagina.demo.confirmar")
      }}</span>
    </div>
  </div>
</template>

<style scoped>
.demo-citas {
  overflow: hidden;
  border-radius: var(--radio-panel, 28px);
  background: var(--fondo);
  text-align: left;
}
.demo-citas-cabecera {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 1.5rem;
  background: color-mix(in srgb, var(--acento) 8%, var(--superficie));
}
.demo-citas-logo {
  display: inline-flex;
  height: 3.25rem;
  width: 3.25rem;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  border-radius: 1rem;
  background: #031b4e;
  color: #fff;
  font-size: 0.85rem;
  font-weight: 700;
}
.demo-citas-nombre {
  font-size: 1.1rem;
  font-weight: 500;
}
.demo-citas-suave {
  color: var(--texto-suave);
  font-size: 0.82rem;
}
.demo-citas-ejemplo {
  margin-left: auto;
  color: var(--texto-suave);
  font-size: 0.72rem;
  white-space: nowrap;
}
.demo-citas-contenido {
  display: grid;
  gap: 1.25rem;
  padding: 1.5rem;
}
.demo-citas-paso {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.6rem;
  font-size: 0.875rem;
  font-weight: 500;
}
.demo-citas-paso > span {
  display: inline-grid;
  width: 1.4rem;
  height: 1.4rem;
  place-items: center;
  border-radius: 999px;
  background: var(--texto);
  color: var(--superficie);
  font-size: 0.7rem;
  font-weight: 600;
}
.demo-citas-servicios {
  display: grid;
  gap: 0.5rem;
}
.demo-citas-servicio,
.demo-citas-opcion {
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton, 11px);
  background: var(--superficie);
  color: var(--texto);
  font: inherit;
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease;
}
.demo-citas-servicio {
  display: flex;
  min-height: 2.75rem;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.6rem 0.9rem;
  text-align: left;
}
.demo-citas-servicio-nombre {
  font-size: 0.9rem;
  font-weight: 500;
}
.demo-citas-opciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}
.demo-citas-opcion {
  min-height: 2.75rem;
  padding: 0.45rem 0.85rem;
  font-size: 0.85rem;
  font-weight: 500;
}
.demo-citas-hora {
  min-width: 4.25rem;
  font-variant-numeric: tabular-nums;
}
.demo-citas-servicio[aria-pressed="true"],
.demo-citas-opcion[aria-pressed="true"] {
  border-color: var(--primario);
  background: color-mix(in srgb, var(--primario) 8%, var(--superficie));
}
.demo-citas-servicio:hover,
.demo-citas-opcion:hover {
  border-color: color-mix(in srgb, var(--primario) 55%, var(--borde));
}
.demo-citas-servicio:focus-visible,
.demo-citas-opcion:focus-visible {
  outline: 2px solid var(--enlace);
  outline-offset: 2px;
}
.demo-citas-resumen {
  color: var(--texto-suave);
  font-size: 0.85rem;
}
.demo-citas-boton {
  display: flex;
  min-height: 2.75rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radio-boton, 11px);
  background: var(--primario);
  color: var(--primario-contraste, #fff);
  font-size: 0.9rem;
  font-weight: 600;
}
@media (prefers-reduced-motion: reduce) {
  .demo-citas-servicio,
  .demo-citas-opcion {
    transition: none;
  }
}
@media (max-width: 639px) {
  .demo-citas-cabecera,
  .demo-citas-contenido {
    padding: 1.1rem;
  }
  .demo-citas-ejemplo {
    display: none;
  }
}
</style>
