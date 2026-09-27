<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, type RouteLocationRaw } from "vue-router";

import AgregarCalendario from "@/components/AgregarCalendario.vue";
import PanelCita from "@/components/PanelCita.vue";
import PanelClase from "@/components/PanelClase.vue";
import TarjetaAcceso from "@/components/TarjetaAcceso.vue";
import { cuandoCorto } from "@/lib/miCuenta";
import {
  fechaEnZona,
  isoLocal,
  useMisClases,
  type ClaseMia,
} from "@/lib/misClases";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Inicio de quien imparte: su próxima clase o cita (con pase de lista y "agregar a
 * mi calendario") y accesos directos con su dato: hoy, alumnos esperados, los
 * próximos 7 días, su calendario, la agenda y su perfil.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const { base, clases, cargando, error, cargar } = useMisClases();
const abierta = ref<ClaseMia | null>(null);

// La hora avanza: la próxima pasa a "en curso" y luego a la siguiente.
const ahora = ref(Date.now());
let reloj: ReturnType<typeof setInterval> | undefined;

const hoy = isoLocal(new Date());
const en7 = (() => {
  const d = new Date();
  d.setDate(d.getDate() + 6);
  return isoLocal(d);
})();

const pendientes = computed(() =>
  clases.value.filter((c) => new Date(c.termina_en).getTime() > ahora.value),
);
const proxima = computed(() => pendientes.value[0] ?? null);
const enCurso = computed(
  () =>
    proxima.value !== null &&
    new Date(proxima.value.inicia_en).getTime() <= ahora.value,
);
const deHoy = computed(() =>
  clases.value.filter((c) => fechaEnZona(c.inicia_en, c.zona_horaria) === hoy),
);
const alumnosHoy = computed(() =>
  deHoy.value.reduce((n, c) => n + c.ocupados, 0),
);

interface Acceso {
  clave: string;
  titulo: string;
  valor: string;
  icono: string;
  to: RouteLocationRaw;
}
const accesos = computed<Acceso[]>(() => {
  const n = (clave: string, cuantos: number) =>
    t(`portal.instructor.inicio.tarjetas.${clave}`, { n: cuantos }, cuantos);
  return [
    {
      clave: "hoy",
      titulo: t("portal.instructor.inicio.tarjetas.hoy"),
      valor: n("hoyValor", deHoy.value.length),
      icono: "agenda",
      to: { name: "mis-clases", query: { vista: "dia" } },
    },
    {
      clave: "alumnos",
      titulo: t("portal.instructor.inicio.tarjetas.alumnos"),
      valor: n("alumnosValor", alumnosHoy.value),
      icono: "miembros",
      to: { name: "mis-clases", query: { vista: "dia" } },
    },
    {
      clave: "semana",
      titulo: t("portal.instructor.inicio.tarjetas.semana"),
      valor: n("semanaValor", clases.value.length),
      icono: "lista",
      to: { name: "mis-clases", query: { vista: "lista" } },
    },
    {
      clave: "calendario",
      titulo: t("portal.instructor.inicio.tarjetas.calendario"),
      valor: t("portal.instructor.inicio.tarjetas.calendarioValor"),
      icono: "cuadricula",
      to: { name: "mis-clases", query: { vista: "mes" } },
    },
    ...(sesion.puede("agenda.ver")
      ? [
          {
            clave: "agenda",
            titulo: t("portal.instructor.inicio.tarjetas.agenda"),
            valor: t("portal.instructor.inicio.tarjetas.agendaValor"),
            icono: "reloj",
            to: { name: "agenda" },
          },
        ]
      : []),
    {
      clave: "perfil",
      titulo: t("portal.instructor.inicio.tarjetas.perfil"),
      valor: t("portal.instructor.inicio.tarjetas.perfilValor"),
      icono: "mi-cuenta",
      to: { name: "mi-perfil" },
    },
  ];
});

function detalle(c: ClaseMia): string {
  if (c.tipo === "cita") {
    return c.cita?.cliente
      ? t("portal.instructor.inicio.con", { nombre: c.cita.cliente })
      : "";
  }
  const partes = [
    c.capacidad !== null
      ? t("portal.instructor.inicio.cupo", {
          ocupados: c.ocupados,
          capacidad: c.capacidad,
        })
      : t("portal.instructor.inicio.inscritos", { n: c.ocupados }, c.ocupados),
  ];
  if (c.en_espera > 0) {
    partes.push(t("portal.instructor.inicio.espera", { n: c.en_espera }));
  }
  return partes.join(" · ");
}

async function recargar(): Promise<void> {
  await cargar(hoy, en7);
  // Si el panel sigue abierto, que muestre lo nuevo.
  if (abierta.value) {
    abierta.value =
      clases.value.find((c) => c.id === abierta.value?.id) ?? null;
  }
}

onMounted(() => {
  void recargar();
  reloj = setInterval(() => (ahora.value = Date.now()), 60_000);
});
onUnmounted(() => clearInterval(reloj));
</script>

<template>
  <section class="mx-auto max-w-5xl px-4 py-8">
    <h1 class="text-xl font-semibold">{{ sesion.estudio?.nombre }}</h1>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p v-if="cargando" class="mt-6" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <!-- Próxima clase o cita -->
      <div class="mt-5 tu-card p-6">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          <template v-if="enCurso">
            <span :style="{ color: 'var(--primario)' }">{{
              $t("portal.instructor.inicio.enCurso")
            }}</span>
          </template>
          <template v-else>{{
            proxima?.tipo === "cita"
              ? $t("portal.instructor.inicio.proximaCita")
              : proxima
                ? $t("portal.instructor.inicio.proxima")
                : $t("portal.instructor.inicio.proximaGeneral")
          }}</template>
        </p>
        <template v-if="proxima">
          <p class="mt-1 text-2xl font-semibold">{{ proxima.oferta ?? "—" }}</p>
          <p class="mt-1 first-letter:uppercase">
            {{ cuandoCorto(proxima.inicia_en, proxima.zona_horaria) }}
            <span
              v-if="proxima.sucursal || proxima.sala"
              :style="{ color: 'var(--texto-suave)' }"
            >
              ·
              {{ [proxima.sucursal, proxima.sala].filter(Boolean).join(" · ") }}
            </span>
          </p>
          <p
            v-if="detalle(proxima)"
            class="mt-1 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ detalle(proxima) }}
          </p>
          <div class="mt-4 flex flex-wrap items-center gap-3">
            <button
              type="button"
              class="tu-btn tu-btn-primario"
              @click="abierta = proxima"
            >
              {{
                proxima.tipo === "cita"
                  ? $t("portal.instructor.inicio.verCita")
                  : $t("portal.instructor.inicio.pasarLista")
              }}
            </button>
            <AgregarCalendario
              :evento="{
                uid: `sesion-${proxima.id}`,
                titulo: proxima.oferta ?? sesion.estudio?.nombre ?? '',
                inicio: proxima.inicia_en,
                fin: proxima.termina_en,
                lugar: [sesion.estudio?.nombre, proxima.sucursal]
                  .filter(Boolean)
                  .join(' · '),
              }"
            />
            <RouterLink :to="{ name: 'mis-clases' }" class="tu-enlace text-sm">
              {{ $t("portal.instructor.inicio.verCalendario") }}
            </RouterLink>
          </div>
        </template>
        <p v-else class="mt-1">
          {{ $t("portal.instructor.inicio.sinProxima") }}
        </p>
      </div>

      <!-- Accesos directos -->
      <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="a in accesos" :key="a.clave">
          <TarjetaAcceso
            :to="a.to"
            :icono="a.icono"
            :titulo="a.titulo"
            :valor="a.valor"
          />
        </li>
      </ul>
    </template>

    <!-- Pase de lista de la clase / detalle de la cita -->
    <PanelClase
      v-if="abierta && abierta.tipo !== 'cita'"
      :sesion="abierta"
      @cerrar="abierta = null"
      @cambio="recargar"
    />
    <PanelCita
      :abierto="abierta !== null && abierta.tipo === 'cita'"
      :base="base"
      :sesion="abierta && abierta.tipo === 'cita' ? abierta : null"
      :catalogo="[]"
      :puede-marcar="sesion.puede('asistencia.marcar')"
      :puede-cobrar="sesion.puede('ordenes.gestionar')"
      :puede-cancelar="sesion.puede('reservas.gestionar')"
      @cerrar="abierta = null"
      @cambiada="recargar"
    />
  </section>
</template>
