<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AgregarCalendario from "@/components/AgregarCalendario.vue";
import IconoClima from "@/components/IconoClima.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaseEntrada from "@/components/PaseEntrada.vue";
import TarjetaAcceso from "@/components/TarjetaAcceso.vue";
import { PALETA_SERVICIO } from "@/lib/agenda";
import { api } from "@/lib/api";
import { fotoNegocio } from "@/lib/fotoNegocio";
import { cuandoCorto, useMiCuenta } from "@/lib/miCuenta";
import { useRetornoPago } from "@/lib/retornoPago";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Inicio del portal del alumno o cliente: un saludo, la tarjeta grande con su próxima
 * clase o cita (siempre visible: sin reserva, invita a reservar) y el clima, lo que
 * pide su atención (firmar, aceptar un lugar) y accesos directos a cada parte de su
 * cuenta. El detalle vive en cada sección, con la barra lateral siempre.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const cuenta = useMiCuenta();
const retornoPago = useRetornoPago();
const verPase = ref(false);

const nombre = computed(() => {
  const u = sesion.usuario;
  return (u?.nombre_pila ?? u?.nombre ?? "").trim().split(/\s+/)[0] ?? "";
});
const foto = computed(() => fotoNegocio(sesion.estudio?.perfil));

const proxima = computed(() =>
  cuenta.proximas.value.find(
    (r) => r.estado === "confirmada" || r.estado === "pendiente_pago",
  ),
);
// Por lo que es: "Tu próxima cita" o "Tu próxima clase"; sin reserva, "reserva".
const etiquetaProxima = computed(() => {
  if (!proxima.value) {
    return t("portal.inicio.proximaReserva");
  }
  return proxima.value.tipo === "cita"
    ? t("portal.inicio.proximaCita")
    : t("portal.inicio.proxima");
});
const ofrecida = computed(() =>
  cuenta.reservas.value.find((r) => r.estado === "ofrecida"),
);
const disponibles = computed(
  () =>
    cuenta.clases.value.filter((c) => !cuenta.reservadas.value.has(c.id))
      .length,
);

/**
 * El clima de la tarjeta (GET /mi/clima): el pronóstico para su próxima clase en su
 * sucursal o, sin clase, el de ahora donde está. Se pide aparte y sin esperar: si no
 * llega, la tarjeta simplemente no lo muestra.
 */
interface Clima {
  tipo: "pronostico" | "ahora";
  lugar: string;
  aproximado: boolean;
  temperatura: number;
  condicion: string;
  icono: string;
  es_de_dia: boolean;
  lluvia: number | null;
}
const clima = ref<Clima | null>(null);
async function cargarClima(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Clima | null }>(
      `/api/v1/app/${sesion.slug}/mi/clima`,
    );
    clima.value =
      data.data && typeof data.data.temperatura === "number" ? data.data : null;
  } catch {
    clima.value = null;
  }
}
const climaLugar = computed(() => {
  const c = clima.value;
  if (!c) {
    return "";
  }
  if (c.tipo === "pronostico") {
    // El pronóstico es para su próxima reserva: se nombra igual que en la tarjeta.
    return proxima.value?.tipo === "cita"
      ? t("portal.inicio.clima.pronosticoCita", { lugar: c.lugar })
      : t("portal.inicio.clima.pronostico", { lugar: c.lugar });
  }
  if (c.lugar === "") {
    return "";
  }
  return c.aproximado
    ? t("portal.inicio.clima.ahoraCerca", { lugar: c.lugar })
    : t("portal.inicio.clima.ahora", { lugar: c.lugar });
});

// Créditos de lo vigente: ilimitado o la suma disponible (1000 unidades = 1).
const creditos = computed<{ ilimitado: boolean; n: number } | null>(() => {
  const d = cuenta.derechos.value;
  if (d.length === 0) {
    return null;
  }
  if (d.some((x) => x.ilimitado && !x.pausa_hasta)) {
    return { ilimitado: true, n: 0 };
  }
  return {
    ilimitado: false,
    n: d.reduce((a, x) => a + Math.max(0, x.disponible ?? 0), 0) / 1000,
  };
});

// Un color por acceso, de la misma paleta de la agenda.
const TONO = {
  reservar: PALETA_SERVICIO[0].tinta,
  reservas: PALETA_SERVICIO[2].tinta,
  creditos: PALETA_SERVICIO[1].tinta,
  pagos: PALETA_SERVICIO[4].tinta,
  pase: PALETA_SERVICIO[5].tinta,
  expediente: PALETA_SERVICIO[3].tinta,
  configuracion: PALETA_SERVICIO[7].tinta,
};

interface Acceso {
  clave: string;
  titulo: string;
  valor: string;
  icono: string;
  tono: string;
  ruta?: string;
  alTocar?: () => void;
  atencion?: boolean;
}
const accesos = computed<Acceso[]>(() => {
  const c = creditos.value;
  const pendientes = cuenta.porPagar.value.length;
  const proximas = cuenta.proximas.value.length;
  return [
    {
      clave: "reservar",
      titulo: t("portal.inicio.tarjetas.reservar"),
      valor: sesion.esCitas
        ? t("portal.inicio.tarjetas.agendar")
        : t("portal.inicio.tarjetas.disponibles", disponibles.value),
      icono: "agenda",
      tono: TONO.reservar,
      ruta: "mis-reservas",
    },
    {
      clave: "reservas",
      titulo: t("portal.inicio.tarjetas.reservas"),
      valor:
        proximas > 0
          ? t("portal.inicio.tarjetas.proximas", proximas)
          : t("portal.inicio.tarjetas.sinReservas"),
      icono: "lista",
      tono: TONO.reservas,
      ruta: "mis-reservas",
    },
    {
      clave: "creditos",
      titulo: t("portal.inicio.tarjetas.creditos"),
      valor:
        c === null
          ? t("portal.inicio.tarjetas.sinPaquete")
          : c.ilimitado
            ? t("portal.inicio.tarjetas.ilimitado")
            : t(
                "portal.inicio.tarjetas.creditosValor",
                { n: new Intl.NumberFormat("es-MX").format(c.n) },
                c.n === 1 ? 1 : 2,
              ),
      icono: "etiqueta",
      tono: TONO.creditos,
      ruta: "mis-pagos",
    },
    {
      clave: "pagos",
      titulo: t("portal.inicio.tarjetas.pagos"),
      valor:
        pendientes > 0
          ? t("portal.inicio.tarjetas.porPagar", pendientes)
          : t("portal.inicio.tarjetas.alCorriente"),
      icono: "ventas",
      tono: TONO.pagos,
      ruta: "mis-pagos",
      atencion: pendientes > 0,
    },
    ...(cuenta.personaId.value !== null
      ? [
          {
            clave: "pase",
            titulo: t("portal.inicio.tarjetas.pase"),
            valor: t("portal.inicio.tarjetas.paseValor"),
            icono: "cuadricula",
            tono: TONO.pase,
            alTocar: () => (verPase.value = true),
          },
        ]
      : []),
    {
      clave: "expediente",
      titulo: t("portal.inicio.tarjetas.expediente"),
      valor:
        cuenta.waivers.value.length > 0
          ? t("portal.inicio.atencion.firmar", cuenta.waivers.value.length)
          : t("portal.inicio.tarjetas.expedienteValor"),
      icono: "expediente",
      tono: TONO.expediente,
      ruta: "mi-expediente",
      atencion: cuenta.waivers.value.length > 0,
    },
    {
      clave: "configuracion",
      titulo: t("portal.inicio.tarjetas.configuracion"),
      valor: t("portal.inicio.tarjetas.configuracionValor"),
      icono: "configuracion",
      tono: TONO.configuracion,
      ruta: "mi-configuracion",
    },
  ];
});

onMounted(() => {
  void cuenta.asegurar();
  void cargarClima();
});
</script>

<template>
  <section class="mx-auto max-w-5xl px-4 py-8">
    <h1 class="text-2xl font-semibold">
      {{
        nombre
          ? $t("portal.inicio.saludo", { nombre })
          : $t("portal.inicio.saludoSinNombre")
      }}
    </h1>
    <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
      {{
        $t("portal.inicio.resumen", { estudio: sesion.estudio?.nombre ?? "" })
      }}
    </p>

    <p
      v-if="cuenta.error.value"
      class="mt-4 text-sm"
      style="color: var(--error)"
    >
      {{ cuenta.error.value }}
    </p>
    <p
      v-if="retornoPago"
      class="mt-4 text-sm"
      role="status"
      :style="{
        color: retornoPago === 'exito' ? 'var(--exito)' : 'var(--aviso)',
      }"
    >
      {{ $t(`pagoEnLinea.${retornoPago}`) }}
    </p>

    <!-- La tarjeta principal: siempre, con o sin reserva. -->
    <article class="mc-hero tu-card mt-6">
      <div class="mc-hero-texto">
        <p class="mc-etiqueta">{{ etiquetaProxima }}</p>
        <p
          v-if="cuenta.cargando.value"
          class="mt-2"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>
        <template v-else-if="proxima">
          <p class="mt-2 text-2xl font-semibold sm:text-3xl">
            {{ proxima.oferta ?? "—" }}
          </p>
          <p class="mt-2 first-letter:uppercase">
            {{ cuandoCorto(proxima.inicia_en, proxima.zona_horaria) }}
            <span
              v-if="proxima.sucursal"
              :style="{ color: 'var(--texto-suave)' }"
            >
              · {{ proxima.sucursal }}</span
            >
          </p>
          <p
            v-if="proxima.estado === 'pendiente_pago'"
            class="mt-1 text-sm"
            :style="{ color: 'var(--aviso)' }"
          >
            {{ $t("miCuenta.pendiente_pago") }}
          </p>
          <div class="mt-6 flex flex-wrap items-center gap-4">
            <AgregarCalendario
              v-if="proxima.inicia_en"
              primario
              :evento="{
                uid: `reserva-${proxima.id}`,
                titulo: proxima.oferta ?? sesion.estudio?.nombre ?? '',
                inicio: proxima.inicia_en,
                fin: proxima.termina_en,
                lugar: [sesion.estudio?.nombre, proxima.sucursal]
                  .filter(Boolean)
                  .join(' · '),
              }"
            />
            <RouterLink
              :to="{ name: 'mis-reservas' }"
              class="tu-enlace text-sm"
            >
              {{ $t("portal.inicio.verDetalle") }}
            </RouterLink>
          </div>
        </template>
        <template v-else>
          <p class="mt-2 text-2xl font-semibold sm:text-3xl">
            {{ $t("portal.inicio.sinReservas") }}
          </p>
          <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("portal.inicio.sinProximaAyuda") }}
          </p>
          <div class="mt-6">
            <RouterLink
              :to="{ name: 'mis-reservas' }"
              class="tu-btn tu-btn-primario inline-flex"
            >
              {{
                sesion.esCitas
                  ? $t("portal.inicio.tarjetas.agendar")
                  : $t("portal.inicio.reservar")
              }}
            </RouterLink>
          </div>
        </template>
      </div>

      <div class="mc-hero-foto">
        <img :src="foto" alt="" loading="lazy" />
        <div v-if="clima" class="mc-clima" role="status">
          <IconoClima
            :icono="clima.icono"
            :de-dia="clima.es_de_dia"
            :tam="30"
          />
          <div class="min-w-0">
            <p class="leading-tight">
              <span class="text-xl font-semibold"
                >{{ clima.temperatura }}°</span
              >
              <span class="ml-1.5 text-sm">{{ clima.condicion }}</span>
              <span
                v-if="clima.lluvia !== null && clima.lluvia >= 20"
                class="ml-1.5 text-sm opacity-85"
                >·
                {{
                  $t("portal.inicio.clima.lluvia", { n: clima.lluvia })
                }}</span
              >
            </p>
            <p v-if="climaLugar" class="mt-0.5 truncate text-xs opacity-85">
              {{ climaLugar }}
            </p>
          </div>
        </div>
      </div>
    </article>

    <template v-if="!cuenta.cargando.value">
      <!-- Lo que pide atención -->
      <div
        v-if="ofrecida || cuenta.waivers.value.length > 0"
        class="mt-5 space-y-2"
      >
        <RouterLink
          v-if="ofrecida"
          :to="{ name: 'mis-reservas' }"
          class="pi-aviso"
          >{{
            $t("portal.inicio.atencion.lugar", { clase: ofrecida.oferta ?? "" })
          }}</RouterLink
        >
        <RouterLink
          v-if="cuenta.waivers.value.length > 0"
          :to="{ name: 'mi-expediente' }"
          class="pi-aviso"
          >{{
            $t("portal.inicio.atencion.firmar", cuenta.waivers.value.length)
          }}</RouterLink
        >
      </div>

      <!-- Accesos directos -->
      <ul class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="a in accesos" :key="a.clave">
          <TarjetaAcceso
            :to="a.ruta ? { name: a.ruta } : undefined"
            :icono="a.icono"
            :titulo="a.titulo"
            :valor="a.valor"
            :atencion="a.atencion"
            :tono="a.tono"
            @tocar="a.alTocar?.()"
          />
        </li>
      </ul>
    </template>

    <ModalDialogo
      :abierto="verPase"
      :titulo="$t('portal.inicio.tarjetas.pase')"
      tam="md"
      @cerrar="verPase = false"
    >
      <PaseEntrada />
    </ModalDialogo>
  </section>
</template>

<style scoped>
.pi-aviso {
  display: block;
  padding: 0.75rem 1rem;
  border-radius: 0.75rem;
  border: 1px solid color-mix(in srgb, var(--aviso) 35%, var(--borde));
  background: color-mix(in srgb, var(--aviso) 8%, var(--superficie));
  color: var(--texto);
  font-size: 0.9rem;
}
.pi-aviso:hover {
  border-color: var(--aviso);
}

/* Tarjeta principal: texto a la izquierda, foto del giro a la derecha (arriba en
   móvil), con el clima sobre la foto. */
.mc-hero {
  display: grid;
  overflow: hidden;
  padding: 0;
}
.mc-hero-texto {
  order: 2;
  padding: 1.5rem;
}
.mc-hero-foto {
  position: relative;
  order: 1;
  min-height: 11rem;
  background: var(--superficie-2);
}
.mc-hero-foto img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}
@media (min-width: 768px) {
  .mc-hero {
    grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  }
  .mc-hero-texto {
    order: 1;
    padding: 2.25rem 2rem;
  }
  .mc-hero-foto {
    order: 2;
    min-height: 16rem;
  }
}
.mc-etiqueta {
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--primario);
}
/* Sobre una foto cualquiera: fondo oscuro translúcido y texto blanco. */
.mc-clima {
  position: absolute;
  left: 0.75rem;
  right: 0.75rem;
  bottom: 0.75rem;
  display: flex;
  max-width: max-content;
  align-items: center;
  gap: 0.65rem;
  padding: 0.55rem 0.85rem;
  border-radius: 0.8rem;
  color: #fff;
  background: rgb(15 23 42 / 0.58);
  backdrop-filter: blur(6px);
}
</style>
