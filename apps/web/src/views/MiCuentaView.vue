<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AgregarCalendario from "@/components/AgregarCalendario.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaseEntrada from "@/components/PaseEntrada.vue";
import TarjetaOperacion, {
  type Ilustracion,
} from "@/components/TarjetaOperacion.vue";
import TarjetaPrincipal from "@/components/TarjetaPrincipal.vue";
import { lugarDelClima, useClima } from "@/lib/clima";
import { fotoNegocio } from "@/lib/fotoNegocio";
import { cuandoCorto, useMiCuenta } from "@/lib/miCuenta";
import { useRetornoPago } from "@/lib/retornoPago";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Inicio del portal del alumno o cliente: un saludo, la tarjeta grande con su próxima
 * clase o cita (siempre visible: sin reserva, invita a reservar) y el clima, lo que
 * pide su atención (firmar, aceptar un lugar) y accesos directos a cada parte de su
 * cuenta. El detalle vive en cada sección, con la barra lateral siempre.
 *
 * El orden sigue lo que necesita cada quien (ADR 0091). En citas: próxima cita →
 * cómo llegar → cambiar o cancelar → volver a agendar → pagos. En clases: próxima
 * clase → reservar → clases de su plan → vencimiento → asistencia. Créditos,
 * expediente y pase aparecen solo si el negocio los usa (`portal` de /mi/perfil).
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

// El clima de la tarjeta (GET /mi/clima): el de su próxima reserva o el de ahora.
const { clima, cargar: cargarClima } = useClima(
  () => `/api/v1/app/${sesion.slug}/mi/clima`,
);
const climaLugar = computed(() =>
  lugarDelClima(clima.value, t, proxima.value?.tipo),
);

// Lo vigente: lo que no ha vencido. Lo vencido es historial (está en Pagos › Mis
// planes) y no suma al saldo.
function hoyLocal(): string {
  const d = new Date();
  const dos = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`;
}
const vigentes = computed(() =>
  cuenta.derechos.value.filter(
    (d) => !d.vence || d.vence.slice(0, 10) >= hoyLocal(),
  ),
);

// Créditos de lo vigente: ilimitado o la suma disponible (1000 unidades = 1).
const creditos = computed<{ ilimitado: boolean; n: number } | null>(() => {
  const d = vigentes.value;
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

// Vence lo que antes se acaba de su plan vigente (si vence).
const vencimiento = computed(() => {
  const fechas = vigentes.value
    .map((d) => d.vence)
    .filter((f): f is string => typeof f === "string")
    .sort();
  return fechas[0] ?? null;
});
function fechaCorta(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
  }).format(new Date(`${iso}T12:00:00`));
}

// Un color y una ilustración por acceso.
const TONO = {
  reservar: "azul",
  reservas: "morado",
  creditos: "verde",
  pagos: "naranja",
  pase: "cielo",
  expediente: "rosa",
  configuracion: "azul",
  asistencia: "morado",
};
// Su imagen, si se agregó.
const IMAGEN: Record<string, string> = {
  reservar: "acceso-reservar",
  reservas: "acceso-mis-reservas",
  creditos: "acceso-creditos",
  pagos: "acceso-pagos",
  pase: "acceso-pase",
  expediente: "acceso-expediente",
  configuracion: "acceso-configuracion",
};
const ILUSTRACION: Record<string, Ilustracion> = {
  reservar: "agenda",
  reservas: "reservas",
  creditos: "creditos",
  pagos: "pagos",
  pase: "pase",
  expediente: "expediente",
  configuracion: "configuracion",
  asistencia: "reservas",
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
  const usa = cuenta.portal.value;
  const citas = sesion.esCitas === true;

  const valorCreditos =
    c === null
      ? t("portal.inicio.tarjetas.sinPaquete")
      : c.ilimitado
        ? t("portal.inicio.tarjetas.ilimitado")
        : t(
            "portal.inicio.tarjetas.creditosValor",
            { n: new Intl.NumberFormat("es-MX").format(c.n) },
            c.n === 1 ? 1 : 2,
          );
  const reservar: Acceso = {
    clave: "reservar",
    titulo: citas
      ? t("portal.inicio.tarjetas.volverAgendar")
      : t("portal.inicio.tarjetas.reservar"),
    valor: citas
      ? t("portal.inicio.tarjetas.agendar")
      : t("portal.inicio.tarjetas.disponibles", disponibles.value),
    icono: "agenda",
    tono: TONO.reservar,
    ruta: "mis-reservas",
  };
  const reservas: Acceso = {
    clave: "reservas",
    titulo: citas
      ? t("portal.inicio.tarjetas.misCitas")
      : t("portal.inicio.tarjetas.reservas"),
    valor:
      proximas > 0
        ? citas
          ? t("portal.inicio.tarjetas.cambiarCancelar")
          : t("portal.inicio.tarjetas.proximas", proximas)
        : t("portal.inicio.tarjetas.sinReservas"),
    icono: "lista",
    tono: TONO.reservas,
    ruta: "mis-reservas",
  };
  // Su bono o su plan: solo si lo tiene, o si el negocio vende planes (clases).
  const plan: Acceso | null =
    (usa?.creditos ?? c !== null) && (c !== null || !citas)
      ? {
          clave: "creditos",
          titulo: citas
            ? t("portal.inicio.tarjetas.bono")
            : t("portal.inicio.tarjetas.creditos"),
          valor:
            vencimiento.value && c !== null
              ? t("portal.inicio.tarjetas.vence", {
                  valor: valorCreditos,
                  fecha: fechaCorta(vencimiento.value),
                })
              : valorCreditos,
          icono: "etiqueta",
          tono: TONO.creditos,
          ruta: "mis-pagos",
        }
      : null;
  const pagos: Acceso = {
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
  };
  const asistencia: Acceso | null = citas
    ? null
    : {
        clave: "asistencia",
        titulo: t("portal.inicio.tarjetas.asistencia"),
        valor: t(
          "portal.inicio.tarjetas.asistenciaValor",
          cuenta.asistencias.value,
        ),
        icono: "hecho",
        tono: TONO.asistencia,
        ruta: "mis-reservas",
      };
  // El pase y el expediente, solo si el negocio los usa (o hay algo por firmar).
  const pase: Acceso | null =
    cuenta.personaId.value !== null && (usa?.pase ?? true)
      ? {
          clave: "pase",
          titulo: t("portal.inicio.tarjetas.pase"),
          valor: t("portal.inicio.tarjetas.paseValor"),
          icono: "cuadricula",
          tono: TONO.pase,
          alTocar: () => (verPase.value = true),
        }
      : null;
  const expediente: Acceso | null =
    (usa?.expediente ?? true) || cuenta.waivers.value.length > 0
      ? {
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
        }
      : null;
  const configuracion: Acceso = {
    clave: "configuracion",
    titulo: t("portal.inicio.tarjetas.configuracion"),
    valor: t("portal.inicio.tarjetas.configuracionValor"),
    icono: "configuracion",
    tono: TONO.configuracion,
    ruta: "mi-configuracion",
  };

  const orden = citas
    ? [reservas, reservar, pagos, plan, expediente, pase, configuracion]
    : [
        reservar,
        reservas,
        plan,
        asistencia,
        pagos,
        pase,
        expediente,
        configuracion,
      ];
  return orden.filter((a): a is Acceso => a !== null);
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
    <TarjetaPrincipal
      class="mt-6"
      :etiqueta="etiquetaProxima"
      :foto="foto"
      :clima="clima"
      :clima-lugar="climaLugar"
    >
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
          <a
            v-if="proxima.tipo === 'cita' && proxima.mapa_url"
            :href="proxima.mapa_url"
            target="_blank"
            rel="noopener"
            class="tu-enlace text-sm"
            data-prueba="como-llegar"
            >{{ $t("portal.inicio.comoLlegar") }}</a
          >
          <RouterLink
            :to="{ name: 'mis-reservas' }"
            class="tu-enlace text-sm"
            data-prueba="ver-detalle"
          >
            {{
              proxima.tipo === "cita"
                ? $t("portal.inicio.cambiarCancelar")
                : $t("portal.inicio.verDetalle")
            }}
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
    </TarjetaPrincipal>

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
          <TarjetaOperacion
            :to="a.ruta ? { name: a.ruta } : undefined"
            :icono="a.icono"
            :titulo="a.titulo"
            :texto="a.valor"
            :atencion="a.atencion"
            :tono="a.tono"
            :ilustracion="ILUSTRACION[a.clave] ?? 'agenda'"
            :imagen="IMAGEN[a.clave]"
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
</style>
