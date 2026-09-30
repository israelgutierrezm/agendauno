<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import { useRetornoPago } from "@/lib/retornoPago";
import { recordarNegocio } from "@/lib/negociosRecientes";
import { esMiembro } from "@/lib/roles";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import CalendarioDias from "@/components/CalendarioDias.vue";
import ElegirProfesional from "@/components/ElegirProfesional.vue";
import FotoAmpliable from "@/components/FotoAmpliable.vue";
import IconoNav from "@/components/IconoNav.vue";
import IconoRed from "@/components/IconoRed.vue";
import ServicioIncluye from "@/components/ServicioIncluye.vue";

interface Servicio {
  id: string;
  nombre: string;
  // Qué incluye y en qué grupo va (catálogo del negocio).
  descripcion?: string | null;
  categoria?: string | null;
  // Paquete: qué incluye y cuánto costaría por separado.
  incluye?: string[];
  precio_por_separado_minor?: number | null;
  // Su foto, si el negocio la subió.
  foto_url?: string | null;
  precio_minor: number | null;
  moneda: string;
  duracion_minutos: number | null;
}
interface Sucursal {
  id: string;
  nombre: string;
  zona_horaria: string | null;
  region?: string | null;
  // Para reconocerla (foto, dirección) y llegar a la correcta.
  direccion?: string | null;
  foto_url?: string | null;
  mapa_url?: string | null;
  // Instagram y Facebook de la sede, si los tiene.
  redes?: { red: "instagram" | "facebook"; url: string }[];
}
interface Persona {
  id: string;
  nombre: string;
  foto_url?: string | null;
}
interface Opciones {
  estudio: {
    slug: string;
    nombre: string;
    logo_url: string | null;
    // Cómo llama el negocio a quien atiende (p. ej. «Barbero»).
    profesional?: string;
  };
  servicios: Servicio[];
  sucursales: Sucursal[];
  instructores: Persona[];
  // Si se paga en línea para confirmar o se puede pagar en la sucursal.
  cobro?: { pago_obligatorio: boolean; pago_en_linea: boolean };
  // Si el negocio manda los avisos de la cita por WhatsApp (ADR 0069).
  whatsapp?: boolean;
}
// La cita por pagar del enlace del correo de apartado (?pagar=<orden>).
interface PorPagar {
  orden_id: string;
  estado_orden: string;
  estado_reserva: string;
  servicio: string | null;
  inicia_en: string | null;
  zona_horaria: string | null;
  sucursal: {
    nombre: string;
    direccion: string | null;
    mapa_url: string | null;
  } | null;
  total_minor: number | null;
  moneda: string | null;
  vence_en: string | null;
  pago_en_linea: boolean;
}
interface Slot {
  inicia: string;
  termina: string;
  // Con los horarios de todo el equipo: quiénes están libres en ese hueco.
  profesionales?: string[];
}
// Sin preferencia: el negocio asigna a quien esté libre a esa hora.
const CUALQUIERA = "cualquiera";
// Pasos del asistente; la sucursal solo se pregunta si hay más de una.
type Paso = "sucursal" | "servicio" | "horario" | "confirmar";

const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();
// Al volver de la página de pago: avisa cómo quedó.
const retornoPago = useRetornoPago();
const slug = computed(() => String(route.params.slug));

const opciones = ref<Opciones | null>(null);
const cargando = ref(true);
const noDisponible = ref(false);
const error = ref<string | null>(null);

// Selección del asistente.
const paso = ref<Paso>("sucursal");
const servicioId = ref("");
// Servicios agrupados por su categoría (la actividad del catálogo).
// Con alguna foto, cada servicio lleva su miniatura (o su inicial) para alinearlos;
// sin ninguna, no se reserva ese espacio.
const serviciosConFoto = computed(() =>
  (opciones.value?.servicios ?? []).some((s) => s.foto_url),
);

const gruposServicios = computed(() => {
  const grupos = new Map<string, Servicio[]>();
  for (const s of opciones.value?.servicios ?? []) {
    const clave = s.categoria ?? "";
    grupos.set(clave, [...(grupos.get(clave) ?? []), s]);
  }
  return [...grupos.entries()].map(([nombre, lista]) => ({ nombre, lista }));
});
const sucursalId = ref("");
// Primero la hora: se ven los horarios de todo el equipo ("") o, si el cliente ya
// tiene a alguien de preferencia, solo los de esa persona.
const filtro = ref("");
// Con quién: CUALQUIERA (lo asigna el negocio) o un profesional libre a esa hora.
const barberoId = ref("");
const fecha = ref("");
const slotSel = ref<string>("");
// Datos del invitado (ADR 0067): apellidos, lada del celular y cómo nos conoció; y si
// acepta los avisos por WhatsApp (ADR 0069).
function datosVacios() {
  return {
    nombre: "",
    apellidos: "",
    lada: "+52",
    celular: "",
    email: "",
    origen: "",
    whatsapp: false,
  };
}
// Solo si el negocio los usa y dejó su celular.
const ofrecerWhatsApp = computed(
  () => opciones.value?.whatsapp === true && datos.value.celular.trim() !== "",
);
const datos = ref(datosVacios());
// Nota para el negocio (también con cuenta): llega al detalle de la cita.
const nota = ref("");
// Para otra persona (ADR 0068): la cita es de quien agenda; se guarda quién asiste.
const paraOtra = ref(false);
const asiste = ref("");
const LADAS = ["+52", "+1", "+57", "+34", "+54", "+56", "+51", "+593", "+502"];
const ORIGENES = [
  "instagram",
  "facebook",
  "tiktok",
  "google",
  "recomendacion",
  "paso_por_aqui",
  "otro",
];

const slots = ref<Slot[]>([]);
const buscandoSlots = ref(false);
const slotsCargados = ref(false);

const agendando = ref(false);
const pagando = ref(false);
// Resultado de agendar (cita creada, pendiente de pago).
const resultado = ref<{
  // Confirmada (se paga en línea o en la sucursal) o apartada hasta pagar.
  estado?: string;
  orden_id: string | null;
  total_minor: number | null;
  moneda: string | null;
  // Quién atenderá (el elegido o el que asignó el negocio).
  profesional?: { id: string; nombre: string } | null;
} | null>(null);
const pendientePago = ref(false);

const servicioSel = computed(
  () =>
    opciones.value?.servicios.find((s) => s.id === servicioId.value) ?? null,
);
const sucursalSel = computed(
  () =>
    opciones.value?.sucursales.find((s) => s.id === sucursalId.value) ?? null,
);
const barberoSel = computed(
  () =>
    opciones.value?.instructores.find((b) => b.id === barberoId.value) ?? null,
);
const variasSedes = computed(
  () => (opciones.value?.sucursales.length ?? 0) > 1,
);
// Si ninguna sede tiene foto, las tarjetas van compactas (sin hueco de imagen).
const sedesConFoto = computed(() =>
  (opciones.value?.sucursales ?? []).some((s) => s.foto_url),
);
const variosProfesionales = computed(
  () => (opciones.value?.instructores.length ?? 0) > 1,
);
// Tras elegir la hora se elige con quién, salvo que ya se filtró por alguien.
const eligeConQuien = computed(
  () => variosProfesionales.value && filtro.value === "",
);
const slotActual = computed(
  () => slots.value.find((s) => s.inicia === slotSel.value) ?? null,
);
// Quienes están libres a la hora elegida.
const libresEnHora = computed(() => {
  const ids = slotActual.value?.profesionales ?? [];
  return (opciones.value?.instructores ?? []).filter((b) => ids.includes(b.id));
});
const zona = computed(
  () => sucursalSel.value?.zona_horaria ?? "America/Mexico_City",
);
// Duración del servicio; respaldo de 60 min si el servicio no la definió.
const duracion = computed(() => servicioSel.value?.duracion_minutos ?? 60);
// Cómo se cobra (ADR 0065): sin el dato (API anterior), se paga para confirmar.
const pagoObligatorio = computed(
  () => opciones.value?.cobro?.pago_obligatorio ?? true,
);
const pagoEnLinea = computed(
  () => opciones.value?.cobro?.pago_en_linea ?? true,
);
// Cliente con cuenta en este negocio: se agenda a su nombre, sin pedir datos.
const comoInvitado = ref(false);
const clienteConCuenta = computed(
  () =>
    !comoInvitado.value &&
    sesion.autenticado &&
    sesion.slug === slug.value &&
    esMiembro(sesion.usuario),
);
const CORREO = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Mapa de pasos: los que ya pasaron se pueden volver a abrir.
const pasos = computed<Paso[]>(() =>
  variasSedes.value
    ? ["sucursal", "servicio", "horario", "confirmar"]
    : ["servicio", "horario", "confirmar"],
);
const indicePaso = computed(() => pasos.value.indexOf(paso.value));
const pasoTitulo = ref<HTMLElement | null>(null);
function ir(p: Paso): void {
  paso.value = p;
  error.value = null;
  // Al cambiar de paso, arriba (el título del paso recibe el foco).
  pasoTitulo.value?.scrollIntoView?.({ block: "start", behavior: "smooth" });
}
function volverA(p: Paso): void {
  if (pasos.value.indexOf(p) < indicePaso.value) ir(p);
}
const puedeContinuar = computed(
  () => slotSel.value !== "" && barberoId.value !== "",
);

const listoParaAgendar = computed(
  () =>
    servicioId.value !== "" &&
    sucursalId.value !== "" &&
    barberoId.value !== "" &&
    slotSel.value !== "" &&
    (clienteConCuenta.value ||
      (datos.value.nombre.trim() !== "" &&
        CORREO.test(datos.value.email.trim()))),
);

function dinero(minor: number | null, moneda: string | null): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda ?? "MXN",
  }).format((minor ?? 0) / 100);
}
function horaLocal(iso: string, tz = zona.value): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: tz,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}
// "Jueves, 1 de octubre" en la zona de la sede.
function diaLocal(iso: string, tz = zona.value): string {
  const texto = new Intl.DateTimeFormat("es-MX", {
    timeZone: tz,
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(iso));
  return texto.charAt(0).toUpperCase() + texto.slice(1);
}
// Reloj de pared local de la sucursal ("YYYY-MM-DD HH:MM:SS"), como lo espera el backend.
function relojLocal(iso: string): string {
  const d = new Date(iso);
  const f = new Intl.DateTimeFormat("en-CA", {
    timeZone: zona.value,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(d);
  const h = new Intl.DateTimeFormat("en-GB", {
    timeZone: zona.value,
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  }).format(d);
  return `${f} ${h}`;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  noDisponible.value = false;
  try {
    const { data } = await api.get<{ data: Opciones }>(
      `/api/v1/app/${slug.value}/citas/opciones`,
    );
    opciones.value = data.data;
    recordarNegocio({
      slug: data.data.estudio.slug,
      nombre: data.data.estudio.nombre,
      logo_url: data.data.estudio.logo_url,
      ciudad: null,
      pais: null,
    });
    // Sede pre-seleccionada desde el selector de sucursal (?sucursal=<ulid>), o
    // la única si solo hay una: entonces se empieza por el servicio.
    const preSuc = String(route.query.sucursal ?? "");
    if (preSuc !== "" && data.data.sucursales.some((s) => s.id === preSuc)) {
      sucursalId.value = preSuc;
    } else if (data.data.sucursales.length === 1) {
      sucursalId.value = data.data.sucursales[0].id;
    }
    paso.value = sucursalId.value !== "" ? "servicio" : "sucursal";
    await Promise.all([retomar(), cargarPorPagar()]);
  } catch {
    noDisponible.value = true;
  } finally {
    cargando.value = false;
  }
}

// Lo elegido se guarda mientras el cliente entra a su cuenta y se retoma al volver.
const claveAsistente = computed(() => `agendar:${slug.value}`);
function entrarParaAgendar(): void {
  try {
    sessionStorage.setItem(
      claveAsistente.value,
      JSON.stringify({
        sucursalId: sucursalId.value,
        servicioId: servicioId.value,
        filtro: filtro.value,
        fecha: fecha.value,
        slotSel: slotSel.value,
        barberoId: barberoId.value,
        guardado: Date.now(),
      }),
    );
  } catch {
    // Sin almacenamiento: al volver, elige de nuevo.
  }
  void router.push({
    name: "entrar",
    query: { estudio: slug.value, volver: route.fullPath },
  });
}
async function retomar(): Promise<void> {
  let guardado: Record<string, string | number> | null = null;
  try {
    guardado = JSON.parse(
      sessionStorage.getItem(claveAsistente.value) ?? "null",
    );
    sessionStorage.removeItem(claveAsistente.value);
  } catch {
    return;
  }
  const o = opciones.value;
  if (
    guardado === null ||
    o === null ||
    Date.now() - Number(guardado.guardado) > 30 * 60 * 1000 ||
    !o.sucursales.some((x) => x.id === guardado?.sucursalId) ||
    !o.servicios.some((x) => x.id === guardado?.servicioId)
  ) {
    return;
  }
  sucursalId.value = String(guardado.sucursalId);
  await nextTick();
  servicioId.value = String(guardado.servicioId);
  filtro.value = String(guardado.filtro ?? "");
  await nextTick();
  fecha.value = String(guardado.fecha ?? "");
  await nextTick();
  await busquedaEnCurso;
  if (slots.value.some((x) => x.inicia === guardado?.slotSel)) {
    slotSel.value = String(guardado.slotSel);
    await nextTick();
    const quien = String(guardado.barberoId ?? "");
    if (
      quien === CUALQUIERA ||
      libresEnHora.value.some((b) => b.id === quien)
    ) {
      barberoId.value = quien;
    }
    ir("confirmar");
  } else {
    ir("horario");
  }
}

let busquedaActual = 0;
async function buscarSlots(): Promise<void> {
  const consulta = ++busquedaActual;
  slotSel.value = "";
  slots.value = [];
  slotsCargados.value = false;
  buscandoSlots.value = false;
  if (sucursalId.value === "" || fecha.value === "") {
    return;
  }
  buscandoSlots.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: { slots: Slot[] } }>(
      `/api/v1/app/${slug.value}/citas/disponibilidad`,
      {
        params: {
          // Sin filtro, los horarios de todo el equipo.
          ...(filtro.value !== "" ? { instructor_id: filtro.value } : {}),
          sucursal_id: sucursalId.value,
          fecha: fecha.value,
          duracion_minutos: duracion.value,
          // Con el servicio cuentan sus márgenes y la sala o equipo que requiere.
          ...(servicioId.value !== "" ? { oferta_id: servicioId.value } : {}),
        },
      },
    );
    if (consulta !== busquedaActual) return;
    slots.value = data.data.slots;
    slotsCargados.value = true;
  } catch (e) {
    if (consulta === busquedaActual) error.value = mensajeDeError(e);
  } finally {
    if (consulta === busquedaActual) buscandoSlots.value = false;
  }
}

// Con quién de partida: el del filtro, el único profesional o «cualquiera».
function profesionalDePartida(): string {
  const lista = opciones.value?.instructores ?? [];
  if (filtro.value !== "") return filtro.value;
  return lista.length === 1 ? lista[0].id : CUALQUIERA;
}
watch(sucursalId, () => {
  filtro.value = "";
  barberoId.value = profesionalDePartida();
  fecha.value = "";
});
watch(filtro, () => {
  barberoId.value = profesionalDePartida();
});
// Al cambiar de hora, quien se eligió sigue solo si está libre a la nueva hora.
watch(slotSel, () => {
  if (
    slotSel.value !== "" &&
    eligeConQuien.value &&
    barberoId.value !== CUALQUIERA &&
    !libresEnHora.value.some((b) => b.id === barberoId.value)
  ) {
    barberoId.value = CUALQUIERA;
  }
});
// Recalcula huecos al cambiar filtro, sucursal, fecha o servicio (por su duración).
let busquedaEnCurso: Promise<void> = Promise.resolve();
watch([filtro, sucursalId, fecha, servicioId], () => {
  busquedaEnCurso = buscarSlots();
});

// Elegir sede o servicio lleva al paso siguiente (también si se vuelve a tocar la
// que ya estaba elegida).
function elegirSede(id: string): void {
  sucursalId.value = id;
  ir("servicio");
}
function elegirServicio(id: string): void {
  servicioId.value = id;
  ir("horario");
}

async function agendar(): Promise<void> {
  if (!listoParaAgendar.value) {
    error.value = null;
    return;
  }
  agendando.value = true;
  error.value = null;
  const cita = {
    oferta_id: servicioId.value,
    sucursal_id: sucursalId.value,
    ...(barberoId.value !== CUALQUIERA
      ? { instructor_id: barberoId.value }
      : {}),
    inicia_en_local: relojLocal(slotSel.value),
    duracion_minutos: duracion.value,
    ...(nota.value.trim() !== "" ? { nota: nota.value.trim() } : {}),
    ...(paraOtra.value && asiste.value.trim() !== ""
      ? { asiste: asiste.value.trim() }
      : {}),
  };
  try {
    if (clienteConCuenta.value) {
      // Con su cuenta: queda en su historial y no se le piden datos.
      const { data } = await api.post<{
        data: {
          estado: string;
          orden_id: string | null;
          profesional?: { id: string; nombre: string } | null;
        };
      }>(`/api/v1/app/${slug.value}/mi/citas`, cita);
      resultado.value = {
        estado: data.data.estado,
        orden_id: data.data.orden_id,
        total_minor: servicioSel.value?.precio_minor ?? null,
        moneda: servicioSel.value?.moneda ?? null,
        profesional: data.data.profesional ?? null,
      };
    } else {
      const { data } = await api.post<{
        data: {
          estado?: string;
          orden_id: string | null;
          total_minor: number | null;
          moneda: string | null;
          profesional?: { id: string; nombre: string } | null;
        };
      }>(`/api/v1/app/${slug.value}/citas`, {
        nombre: datos.value.nombre.trim(),
        apellidos: datos.value.apellidos.trim() || null,
        celular:
          datos.value.celular.trim() !== "" ? datos.value.celular.trim() : null,
        lada: datos.value.celular.trim() !== "" ? datos.value.lada : null,
        email: datos.value.email.trim(),
        como_nos_conocio: datos.value.origen || null,
        ...(ofrecerWhatsApp.value && datos.value.whatsapp
          ? { acepta_whatsapp: true }
          : {}),
        ...cita,
      });
      resultado.value = data.data;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    agendando.value = false;
  }
}

// Cita por pagar del enlace del correo: qué es, dónde, cuánto y hasta cuándo.
const porPagar = ref<PorPagar | null>(null);
const enlaceInvalido = ref(false);
async function cargarPorPagar(): Promise<void> {
  const orden = String(route.query.pagar ?? "");
  if (orden === "") return;
  try {
    const { data } = await api.get<{ data: PorPagar }>(
      `/api/v1/app/${slug.value}/citas/orden/${encodeURIComponent(orden)}`,
    );
    porPagar.value = data.data;
  } catch {
    enlaceInvalido.value = true;
  }
}
// Se puede pagar si la orden sigue pendiente y la cita no se liberó.
const sePuedePagar = computed(
  () =>
    porPagar.value !== null &&
    porPagar.value.pago_en_linea &&
    porPagar.value.estado_orden === "pendiente" &&
    ["pendiente_pago", "confirmada"].includes(porPagar.value.estado_reserva),
);
function agendarDeNuevo(): void {
  porPagar.value = null;
  enlaceInvalido.value = false;
  void router.replace({ path: route.path, query: {} });
}

async function pagar(ordenId: string | null | undefined): Promise<void> {
  if (ordenId == null) {
    return;
  }
  pagando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{
      data: { checkout?: { tipo?: string; url?: string } | null };
    }>(`/api/v1/app/${slug.value}/citas/pagar`, {
      // Sin proveedor: el API usa la pasarela en línea con la que cobra el negocio.
      orden_id: ordenId,
      metodo: "tarjeta",
    });
    const checkout = data.data.checkout ?? {};
    // Pasarela de redirección (p. ej. Mercado Pago): al checkout externo.
    if (
      checkout.tipo === "redirect" &&
      typeof checkout.url === "string" &&
      checkout.url !== ""
    ) {
      window.location.href = checkout.url;
      return;
    }
    // Otras pasarelas (p. ej. Stripe con tarjeta): el lugar queda apartado.
    pendientePago.value = true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    pagando.value = false;
  }
}

// Otra cita: misma sede y servicio, se vuelve a elegir el horario.
function otra(): void {
  resultado.value = null;
  pendientePago.value = false;
  slotSel.value = "";
  slots.value = [];
  slotsCargados.value = false;
  datos.value = datosVacios();
  nota.value = "";
  paraOtra.value = false;
  asiste.value = "";
  fecha.value = "";
  ir("horario");
}
// Apartada: se paga en línea para confirmar. Confirmada: ya está agendada.
const apartada = computed(
  () => (resultado.value?.estado ?? "pendiente_pago") === "pendiente_pago",
);

function iniciales(nombre: string): string {
  return nombre
    .split(" ")
    .slice(0, 2)
    .map((p) => p.charAt(0))
    .join("")
    .toUpperCase();
}

onMounted(cargar);
</script>

<template>
  <div class="min-h-screen" :style="{ background: 'var(--fondo)' }">
    <p
      v-if="cargando"
      class="text-center py-20"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("reservar.cargando") }}
    </p>

    <section
      v-else-if="noDisponible"
      class="mx-auto max-w-md px-4 py-20 text-center"
    >
      <p class="text-lg font-semibold">{{ $t("reservar.noDisponible") }}</p>
      <RouterLink
        :to="{ name: 'directorio' }"
        class="tu-enlace mt-3 inline-block"
        >{{ $t("reservar.volverDirectorio") }}</RouterLink
      >
    </section>

    <section
      v-else-if="opciones"
      class="rc-contenedor mx-auto px-4 py-8 sm:py-10"
    >
      <p
        v-if="retornoPago"
        class="mb-4 rounded-xl p-3 text-sm"
        role="status"
        :style="{
          background:
            retornoPago === 'exito'
              ? 'var(--exito-suave)'
              : 'var(--superficie-2)',
          color:
            retornoPago === 'exito' ? 'var(--exito-texto)' : 'var(--texto)',
        }"
      >
        {{
          retornoPago === "exito"
            ? $t("pagoEnLinea.citaExito")
            : $t("pagoEnLinea.cancelado")
        }}
      </p>
      <!-- Encabezado con marca del estudio -->
      <header class="flex items-center gap-3">
        <img
          v-if="opciones.estudio.logo_url"
          :src="opciones.estudio.logo_url"
          :alt="opciones.estudio.nombre"
          class="h-12 w-12 rounded-xl object-cover"
        />
        <span
          v-else
          class="h-12 w-12 rounded-xl inline-flex items-center justify-center font-bold"
          :style="{
            background: 'var(--primario)',
            color: 'var(--primario-contraste)',
          }"
          >{{ iniciales(opciones.estudio.nombre) }}</span
        >
        <div>
          <h1 class="text-xl font-light">{{ opciones.estudio.nombre }}</h1>
          <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("reservar.titulo") }}
          </p>
        </div>
      </header>

      <!-- Sin servicios de cita -->
      <p
        v-if="opciones.servicios.length === 0"
        class="mt-10 tu-card p-6 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("reservar.sinServicios") }}
      </p>

      <!-- ===== Pagar una cita apartada (enlace del correo) ===== -->
      <div
        v-else-if="porPagar || enlaceInvalido"
        class="mt-8 tu-card p-6"
        data-prueba="por-pagar"
      >
        <template v-if="porPagar">
          <h2 class="text-xl font-semibold">
            {{ $t("perfilPublico.agendar.pagaTuCita") }}
          </h2>
          <p class="mt-2 font-medium">{{ porPagar.servicio }}</p>
          <p v-if="porPagar.inicia_en" class="text-sm">
            {{ diaLocal(porPagar.inicia_en, porPagar.zona_horaria ?? zona) }} ·
            {{ horaLocal(porPagar.inicia_en, porPagar.zona_horaria ?? zona) }}
          </p>
          <p
            v-if="porPagar.sucursal"
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ porPagar.sucursal.nombre
            }}<template v-if="porPagar.sucursal.direccion">
              · {{ porPagar.sucursal.direccion }}</template
            >
            <a
              v-if="porPagar.sucursal.mapa_url"
              :href="porPagar.sucursal.mapa_url"
              target="_blank"
              rel="noopener"
              class="tu-enlace ml-1"
              >{{ $t("perfilPublico.agendar.comoLlegar") }}</a
            >
          </p>

          <p
            v-if="porPagar.estado_orden === 'pagada'"
            class="mt-4 text-sm"
            data-prueba="ya-pagada"
          >
            {{ $t("perfilPublico.agendar.yaPagada") }}
          </p>
          <template v-else-if="sePuedePagar">
            <p
              v-if="porPagar.vence_en"
              class="mt-4 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                $t("perfilPublico.agendar.pagaAntesDe", {
                  hora: horaLocal(
                    porPagar.vence_en,
                    porPagar.zona_horaria ?? zona,
                  ),
                })
              }}
            </p>
            <p
              v-if="pendientePago"
              class="mt-4 text-sm rounded-lg p-3"
              :style="{
                background: 'var(--primario-suave)',
                color: 'var(--primario-fuerte)',
              }"
            >
              {{ $t("reservar.pendientePago") }}
            </p>
            <button
              v-else
              class="tu-btn tu-btn-primario mt-3 w-full"
              type="button"
              :disabled="pagando"
              @click="pagar(porPagar.orden_id)"
            >
              {{
                pagando
                  ? $t("reservar.pagando")
                  : `${$t("reservar.pagar")} · ${dinero(porPagar.total_minor, porPagar.moneda)}`
              }}
            </button>
          </template>
          <template v-else>
            <p class="mt-4 text-sm" data-prueba="vencida">
              {{ $t("perfilPublico.agendar.vencida") }}
            </p>
            <button
              class="tu-btn tu-btn-primario mt-3 w-full"
              type="button"
              @click="agendarDeNuevo"
            >
              {{ $t("perfilPublico.agendar.agendarDeNuevo") }}
            </button>
          </template>
        </template>
        <template v-else>
          <p class="text-sm" data-prueba="enlace-invalido">
            {{ $t("perfilPublico.agendar.enlaceInvalido") }}
          </p>
          <button
            class="tu-btn tu-btn-primario mt-3 w-full"
            type="button"
            @click="agendarDeNuevo"
          >
            {{ $t("perfilPublico.agendar.agendarDeNuevo") }}
          </button>
        </template>
        <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
          {{ error }}
        </p>
      </div>

      <!-- ===== Confirmación ===== -->
      <div
        v-else-if="resultado"
        class="mt-8 tu-card p-6 text-center"
        role="status"
        aria-live="polite"
      >
        <div
          class="mx-auto h-12 w-12 rounded-full inline-flex items-center justify-center text-white"
          :style="{ background: 'var(--exito)' }"
        >
          <IconoNav nombre="hecho" :tam="24" />
        </div>
        <h2 class="mt-3 text-2xl font-semibold text-success">
          {{ $t("reservar.listoTitulo") }}
        </h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            $t("reservar.listoResumen", {
              servicio: servicioSel?.nombre ?? "",
              barbero:
                resultado.profesional?.nombre ?? barberoSel?.nombre ?? "",
            })
          }}
        </p>
        <p class="mt-1 font-medium">
          {{ diaLocal(slotSel) }} · {{ horaLocal(slotSel) }} ·
          {{ dinero(resultado.total_minor, resultado.moneda) }}
        </p>
        <!-- Dónde: para no llegar a otra sede. -->
        <p
          v-if="sucursalSel"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="donde-listo"
        >
          {{ sucursalSel.nombre
          }}<template v-if="sucursalSel.direccion">
            · {{ sucursalSel.direccion }}</template
          >
          <a
            v-if="sucursalSel.mapa_url"
            :href="sucursalSel.mapa_url"
            target="_blank"
            rel="noopener"
            class="tu-enlace ml-1"
            >{{ $t("perfilPublico.agendar.comoLlegar") }}</a
          >
        </p>

        <template v-if="pendientePago">
          <p
            class="mt-4 text-sm rounded-lg p-3"
            :style="{
              background: 'var(--primario-suave)',
              color: 'var(--primario-fuerte)',
            }"
          >
            {{ $t("reservar.pendientePago") }}
          </p>
        </template>
        <!-- Confirmada: se paga en la sucursal o, si quiere, en línea. -->
        <template v-else-if="!apartada">
          <p
            class="mt-4 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            data-prueba="confirmada"
          >
            {{
              resultado.orden_id && pagoEnLinea
                ? $t("perfilPublico.agendar.pagaAhoraOEnSucursal")
                : $t("perfilPublico.agendar.pagaEnSucursal")
            }}
          </p>
          <button
            v-if="resultado.orden_id && pagoEnLinea"
            class="tu-btn tu-btn-fantasma mt-3 w-full"
            type="button"
            :disabled="pagando"
            @click="pagar(resultado.orden_id)"
          >
            {{
              pagando
                ? $t("reservar.pagando")
                : `${$t("reservar.pagar")} · ${dinero(resultado.total_minor, resultado.moneda)}`
            }}
          </button>
        </template>
        <template v-else>
          <p class="mt-4 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("reservar.apartado") }}
          </p>
          <button
            class="tu-btn tu-btn-primario mt-3 w-full"
            type="button"
            :disabled="pagando"
            @click="pagar(resultado.orden_id)"
          >
            {{
              pagando
                ? $t("reservar.pagando")
                : `${$t("reservar.pagar")} · ${dinero(resultado.total_minor, resultado.moneda)}`
            }}
          </button>
        </template>

        <button class="tu-enlace mt-4 text-sm" type="button" @click="otra">
          {{ $t("reservar.otra") }}
        </button>
        <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
          {{ error }}
        </p>
      </div>

      <!-- ===== Asistente por pasos ===== -->
      <div v-else class="mt-6">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reservar.intro") }}
        </p>

        <!-- Mapa de pasos: hechos (se pueden reabrir), actual y pendientes. -->
        <ol
          class="rc-pasos mt-5"
          :aria-label="$t('perfilPublico.agendar.pasosEtiqueta')"
          data-prueba="pasos"
        >
          <li
            v-for="(p, i) in pasos"
            :key="p"
            class="rc-paso"
            :class="{
              'rc-paso--hecho': i < indicePaso,
              'rc-paso--actual': i === indicePaso,
            }"
            :aria-current="i === indicePaso ? 'step' : undefined"
          >
            <button
              v-if="i < indicePaso"
              type="button"
              class="rc-paso-marca"
              :data-paso="p"
              :aria-label="$t(`perfilPublico.agendar.pasos.${p}`)"
              @click="volverA(p)"
            >
              <span class="rc-paso-num"
                ><IconoNav nombre="hecho" :tam="14"
              /></span>
              <span class="rc-paso-texto">{{
                $t(`perfilPublico.agendar.pasos.${p}`)
              }}</span>
            </button>
            <span v-else class="rc-paso-marca" :data-paso="p">
              <span class="rc-paso-num">{{ i + 1 }}</span>
              <span class="rc-paso-texto">{{
                $t(`perfilPublico.agendar.pasos.${p}`)
              }}</span>
            </span>
          </li>
        </ol>

        <div ref="pasoTitulo" class="mt-5 space-y-4 scroll-mt-4">
          <!-- Paso: sucursal (con foto si la tiene) -->
          <fieldset
            v-if="paso === 'sucursal'"
            class="tu-card p-5 reserva-opciones"
          >
            <legend class="tu-label">{{ $t("sucursalesPub.titulo") }}</legend>
            <p class="reserva-ayuda">{{ $t("sucursalesPub.subtitulo") }}</p>
            <div class="reserva-tarjetas">
              <label
                v-for="s in opciones.sucursales"
                :key="s.id"
                class="rc-sede"
                :class="{
                  'rc-sede--activa': sucursalId === s.id,
                  'rc-sede--compacta': !sedesConFoto,
                }"
              >
                <input
                  :checked="sucursalId === s.id"
                  type="radio"
                  name="sucursal"
                  :value="s.id"
                  class="sr-only"
                  @change="elegirSede(s.id)"
                  @click="sucursalId === s.id && elegirSede(s.id)"
                />
                <img
                  v-if="s.foto_url"
                  :src="s.foto_url"
                  alt=""
                  class="rc-sede-foto"
                />
                <span v-else class="rc-sede-foto rc-sede-sinfoto">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    aria-hidden="true"
                  >
                    <path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z" />
                    <circle cx="12" cy="10" r="2.5" />
                  </svg>
                </span>
                <span class="rc-sede-texto">
                  <strong>{{ s.nombre }}</strong>
                  <small v-if="s.direccion">{{ s.direccion }}</small>
                  <small v-else-if="s.region">{{ s.region }}</small>
                  <!-- Sus redes: abren aparte, sin elegir la sede. -->
                  <span
                    v-if="(s.redes ?? []).length > 0"
                    class="rc-sede-redes"
                    data-prueba="redes-sede"
                  >
                    <a
                      v-for="r in s.redes"
                      :key="r.red"
                      :href="r.url"
                      target="_blank"
                      rel="noopener"
                      :aria-label="
                        $t('perfilPublico.agendar.redDe', {
                          red: $t(`perfilPublico.redes.${r.red}`),
                          sede: s.nombre,
                        })
                      "
                      @click.stop
                    >
                      <IconoRed :red="r.red" :tamano="18" />
                    </a>
                  </span>
                </span>
              </label>
            </div>
          </fieldset>

          <!-- Paso: servicio -->
          <div v-else-if="paso === 'servicio'" class="tu-card p-5">
            <p
              v-if="variasSedes && sucursalSel"
              class="rc-contexto"
              data-prueba="contexto"
            >
              {{ sucursalSel.nombre }} ·
              <button
                type="button"
                class="tu-enlace"
                @click="volverA('sucursal')"
              >
                {{ $t("perfilPublico.agendar.cambiar") }}
              </button>
            </p>
            <label class="tu-label">{{ $t("reservar.servicio") }}</label>
            <div
              v-for="g in gruposServicios"
              :key="g.nombre"
              class="mt-1 space-y-2"
            >
              <p
                v-if="g.nombre && gruposServicios.length > 1"
                class="pt-2 text-xs font-semibold"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ g.nombre }}
              </p>
              <label
                v-for="s in g.lista"
                :key="s.id"
                class="flex items-center justify-between gap-3 rounded-lg p-3 cursor-pointer border"
                :style="{
                  borderColor:
                    servicioId === s.id ? 'var(--primario)' : 'var(--borde)',
                  background:
                    servicioId === s.id
                      ? 'var(--primario-suave)'
                      : 'transparent',
                }"
              >
                <span class="flex items-center gap-2 min-w-0">
                  <input
                    :checked="servicioId === s.id"
                    type="radio"
                    name="servicio"
                    :value="s.id"
                    class="shrink-0"
                    @change="elegirServicio(s.id)"
                    @click="servicioId === s.id && elegirServicio(s.id)"
                  />
                  <!-- Miniatura pequeña: aprovecha el ancho sin hacer más alta la fila. -->
                  <span
                    v-if="serviciosConFoto"
                    class="rc-servicio-foto"
                    aria-hidden="true"
                  >
                    <img
                      v-if="s.foto_url"
                      :src="s.foto_url"
                      alt=""
                      data-prueba="foto-servicio"
                    />
                    <template v-else>{{
                      s.nombre.trim().charAt(0).toUpperCase()
                    }}</template>
                  </span>
                  <span class="min-w-0">
                    <span class="font-medium block truncate">{{
                      s.nombre
                    }}</span>
                    <span v-if="s.duracion_minutos" class="rc-duracion"
                      ><IconoNav nombre="reloj" :tam="13" />{{
                        $t("reservar.duracionMin", { n: s.duracion_minutos })
                      }}</span
                    >
                    <span
                      v-if="s.descripcion"
                      class="block text-sm"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{ s.descripcion }}</span
                    >
                    <ServicioIncluye
                      :incluye="s.incluye"
                      :precio-minor="s.precio_minor"
                      :por-separado-minor="s.precio_por_separado_minor"
                      :moneda="s.moneda"
                    />
                  </span>
                </span>
                <span class="font-semibold shrink-0">{{
                  dinero(s.precio_minor, s.moneda)
                }}</span>
              </label>
            </div>
          </div>

          <!-- Paso: fecha y hora (de todo el equipo o de quien se prefiera) -->
          <template v-else-if="paso === 'horario'">
            <p
              v-if="opciones.instructores.length === 0"
              class="tu-card p-5 reserva-ayuda"
            >
              {{ $t("reservar.sinProfesionales") }}
            </p>
            <div v-else class="tu-card rc-panel-horario">
              <p class="rc-contexto" data-prueba="contexto">
                {{ servicioSel?.nombre
                }}<template v-if="variasSedes && sucursalSel">
                  · {{ sucursalSel.nombre }}</template
                >
                ·
                <button
                  type="button"
                  class="tu-enlace"
                  @click="volverA('servicio')"
                >
                  {{ $t("perfilPublico.agendar.cambiar") }}
                </button>
              </p>
              <!-- Ver horarios de: todo el equipo o alguien, por su foto. -->
              <ElegirProfesional
                v-if="variosProfesionales"
                v-model="filtro"
                class="mb-4"
                :profesionales="opciones.instructores"
              />
              <span class="tu-label">{{ $t("reservar.cuando") }}</span>
              <!-- Días desde hoy; los que no tienen atención no se eligen. -->
              <CalendarioDias
                v-model="fecha"
                :ruta="`/api/v1/app/${slug}/citas/dias`"
                :sucursal-id="sucursalId"
                :instructor-id="filtro || null"
                :zona="zona"
              />

              <p
                v-if="error && !slotSel"
                class="mt-3 text-sm"
                role="alert"
                style="color: var(--error)"
              >
                {{ error }}
              </p>

              <p
                v-if="fecha !== '' && buscandoSlots"
                class="mt-3 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("reservar.calculando") }}
              </p>
              <template v-else-if="fecha !== '' && slotsCargados">
                <p
                  v-if="slots.length === 0"
                  class="mt-3 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("reservar.sinHuecos") }}
                </p>
                <div v-else class="mt-3">
                  <label class="tu-label">{{ $t("reservar.hora") }}</label>
                  <div class="rc-horas">
                    <button
                      v-for="s in slots"
                      :key="s.inicia"
                      type="button"
                      class="rc-hora border"
                      :aria-pressed="slotSel === s.inicia"
                      :style="
                        slotSel === s.inicia
                          ? {
                              background: 'var(--primario)',
                              color: 'var(--primario-contraste)',
                              borderColor: 'var(--primario)',
                            }
                          : { borderColor: 'var(--borde)' }
                      "
                      @click="slotSel = s.inicia"
                    >
                      {{ horaLocal(s.inicia) }}
                    </button>
                  </div>
                </div>
              </template>
            </div>

            <!-- Con quién: tras la hora, solo quienes están libres entonces. -->
            <fieldset
              v-if="slotSel !== '' && eligeConQuien"
              class="tu-card p-5 reserva-opciones"
            >
              <legend class="tu-label">
                {{ $t("perfilPublico.agendar.quienTeAtiende") }}
              </legend>
              <p class="reserva-ayuda">
                {{
                  $t("perfilPublico.agendar.libresALas", {
                    hora: horaLocal(slotSel),
                  })
                }}
              </p>
              <div class="reserva-tarjetas">
                <label
                  class="reserva-eleccion"
                  :class="{
                    'reserva-eleccion--activa': barberoId === CUALQUIERA,
                  }"
                  data-prueba="cualquiera"
                >
                  <input
                    v-model="barberoId"
                    type="radio"
                    name="profesional"
                    :value="CUALQUIERA"
                  />
                  <span class="rc-equipo" aria-hidden="true">
                    <IconoNav nombre="personas" :tam="22" />
                  </span>
                  <span class="reserva-eleccion-texto"
                    ><strong>{{
                      $t("perfilPublico.agendar.cualquiera")
                    }}</strong>
                    <span class="block text-xs font-normal">{{
                      $t("perfilPublico.agendar.cualquieraDesc")
                    }}</span></span
                  >
                </label>
                <label
                  v-for="b in libresEnHora"
                  :key="b.id"
                  class="reserva-eleccion"
                  :class="{ 'reserva-eleccion--activa': barberoId === b.id }"
                >
                  <input
                    v-model="barberoId"
                    type="radio"
                    name="profesional"
                    :value="b.id"
                  />
                  <FotoAmpliable :nombre="b.nombre" :foto="b.foto_url" />
                  <span class="reserva-eleccion-texto"
                    ><strong>{{ b.nombre }}</strong></span
                  >
                </label>
              </div>
            </fieldset>

            <div class="flex items-center justify-between gap-3">
              <button
                type="button"
                class="tu-enlace text-sm"
                @click="volverA('servicio')"
              >
                {{ $t("perfilPublico.agendar.atras") }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-primario"
                :disabled="!puedeContinuar"
                data-prueba="continuar"
                @click="ir('confirmar')"
              >
                {{ $t("perfilPublico.agendar.continuar") }}
              </button>
            </div>
          </template>

          <!-- Paso: confirmación (lo elegido, dónde es y tus datos) -->
          <template v-else-if="paso === 'confirmar'">
            <div class="tu-card p-5" data-prueba="resumen">
              <h2 class="font-semibold">
                {{ $t("perfilPublico.agendar.revisa") }}
              </h2>
              <dl class="rc-resumen mt-3">
                <div>
                  <dt>{{ $t("perfilPublico.agendar.servicio") }}</dt>
                  <dd>
                    <span class="font-medium">{{ servicioSel?.nombre }}</span>
                    ·
                    {{
                      dinero(
                        servicioSel?.precio_minor ?? null,
                        servicioSel?.moneda ?? null,
                      )
                    }}
                    <ServicioIncluye
                      :incluye="servicioSel?.incluye"
                      :precio-minor="servicioSel?.precio_minor"
                      :por-separado-minor="
                        servicioSel?.precio_por_separado_minor
                      "
                      :moneda="servicioSel?.moneda"
                    />
                  </dd>
                  <button
                    type="button"
                    class="tu-enlace text-sm"
                    @click="volverA('servicio')"
                  >
                    {{ $t("perfilPublico.agendar.cambiar") }}
                  </button>
                </div>
                <div>
                  <dt>{{ $t("perfilPublico.agendar.cuando") }}</dt>
                  <dd>
                    <span class="font-medium">{{ diaLocal(slotSel) }}</span>
                    · {{ horaLocal(slotSel) }}
                    <span
                      class="block text-sm"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{
                        barberoId === CUALQUIERA
                          ? $t("perfilPublico.agendar.cualquiera")
                          : barberoSel?.nombre
                      }}</span
                    >
                  </dd>
                  <button
                    type="button"
                    class="tu-enlace text-sm"
                    @click="volverA('horario')"
                  >
                    {{ $t("perfilPublico.agendar.cambiar") }}
                  </button>
                </div>
                <div v-if="sucursalSel" data-prueba="donde">
                  <dt>{{ $t("perfilPublico.agendar.donde") }}</dt>
                  <dd>
                    <span class="flex items-start gap-3">
                      <img
                        v-if="sucursalSel.foto_url"
                        :src="sucursalSel.foto_url"
                        alt=""
                        class="h-14 w-20 shrink-0 rounded-lg object-cover"
                      />
                      <span class="min-w-0">
                        <span class="font-medium block">{{
                          sucursalSel.nombre
                        }}</span>
                        <span
                          v-if="sucursalSel.direccion"
                          class="block text-sm"
                          :style="{ color: 'var(--texto-suave)' }"
                          >{{ sucursalSel.direccion }}</span
                        >
                        <a
                          v-if="sucursalSel.mapa_url"
                          :href="sucursalSel.mapa_url"
                          target="_blank"
                          rel="noopener"
                          class="tu-enlace text-sm"
                          data-prueba="como-llegar"
                          >{{ $t("perfilPublico.agendar.comoLlegar") }}</a
                        >
                      </span>
                    </span>
                  </dd>
                  <button
                    v-if="variasSedes"
                    type="button"
                    class="tu-enlace text-sm"
                    @click="volverA('sucursal')"
                  >
                    {{ $t("perfilPublico.agendar.cambiar") }}
                  </button>
                </div>
              </dl>
            </div>

            <div class="tu-card p-5">
              <!-- Con su cuenta: no se piden datos, solo confirmar que es él. -->
              <div v-if="clienteConCuenta" data-prueba="con-cuenta">
                <label class="tu-label">{{
                  $t("perfilPublico.agendar.agendarasComo")
                }}</label>
                <p class="font-medium">{{ sesion.usuario?.nombre }}</p>
                <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                  {{ sesion.usuario?.email }}
                </p>
                <button
                  type="button"
                  class="tu-enlace mt-1 text-sm"
                  @click="comoInvitado = true"
                >
                  {{ $t("perfilPublico.agendar.usarOtrosDatos") }}
                </button>
              </div>
              <template v-else>
                <label class="tu-label">{{ $t("reservar.datos") }}</label>
                <p
                  class="-mt-1 mb-3 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("perfilPublico.agendar.tienesCuenta") }}
                  <button
                    type="button"
                    class="tu-enlace"
                    data-prueba="entrar"
                    @click="entrarParaAgendar"
                  >
                    {{ $t("perfilPublico.agendar.entrar") }}
                  </button>
                </p>
                <div class="space-y-3">
                  <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                      <label class="tu-label" for="rc-nom">{{
                        $t("reservar.nombre")
                      }}</label>
                      <input
                        id="rc-nom"
                        v-model="datos.nombre"
                        class="tu-input"
                        autocomplete="given-name"
                        required
                      />
                    </div>
                    <div>
                      <label class="tu-label" for="rc-ape">{{
                        $t("perfilPublico.agendar.apellidos")
                      }}</label>
                      <input
                        id="rc-ape"
                        v-model="datos.apellidos"
                        class="tu-input"
                        autocomplete="family-name"
                      />
                    </div>
                  </div>
                  <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                      <label class="tu-label" for="rc-cel">{{
                        $t("reservar.celular")
                      }}</label>
                      <div class="flex gap-2">
                        <select
                          id="rc-lada"
                          v-model="datos.lada"
                          class="tu-input w-auto"
                          :aria-label="$t('perfilPublico.agendar.lada')"
                        >
                          <option v-for="l in LADAS" :key="l" :value="l">
                            {{ l }}
                          </option>
                        </select>
                        <input
                          id="rc-cel"
                          v-model="datos.celular"
                          class="tu-input"
                          inputmode="tel"
                          autocomplete="tel-national"
                        />
                      </div>
                    </div>
                    <div>
                      <label class="tu-label" for="rc-email">{{
                        $t("perfilPublico.agendar.correo")
                      }}</label>
                      <input
                        id="rc-email"
                        v-model="datos.email"
                        type="email"
                        class="tu-input"
                        autocomplete="email"
                        required
                      />
                      <span class="tu-hint">{{
                        $t("perfilPublico.agendar.correoAyuda")
                      }}</span>
                    </div>
                  </div>
                  <div>
                    <label class="tu-label" for="rc-origen">{{
                      $t("perfilPublico.agendar.comoNosConociste")
                    }}</label>
                    <select
                      id="rc-origen"
                      v-model="datos.origen"
                      class="tu-input"
                    >
                      <option value="">
                        {{ $t("perfilPublico.agendar.prefieroNoDecir") }}
                      </option>
                      <option v-for="o in ORIGENES" :key="o" :value="o">
                        {{ $t(`perfilPublico.origenes.${o}`) }}
                      </option>
                    </select>
                  </div>
                  <label
                    v-if="ofrecerWhatsApp"
                    class="flex items-center gap-2 text-sm"
                  >
                    <input
                      v-model="datos.whatsapp"
                      type="checkbox"
                      data-prueba="acepta-whatsapp"
                    />
                    {{ $t("perfilPublico.agendar.aceptaWhatsApp") }}
                  </label>
                </div>
              </template>

              <!-- Para otra persona: la cita es de quien agenda (ADR 0068). -->
              <div class="mt-3">
                <label class="flex items-center gap-2 text-sm">
                  <input
                    v-model="paraOtra"
                    type="checkbox"
                    data-prueba="para-otra"
                  />
                  {{ $t("perfilPublico.agendar.paraOtra") }}
                </label>
                <div v-if="paraOtra" class="mt-2">
                  <label class="tu-label" for="rc-asiste">{{
                    $t("perfilPublico.agendar.quienAsiste")
                  }}</label>
                  <input
                    id="rc-asiste"
                    v-model="asiste"
                    class="tu-input"
                    maxlength="120"
                  />
                  <span class="tu-hint">{{
                    $t("perfilPublico.agendar.paraOtraAyuda")
                  }}</span>
                </div>
              </div>

              <!-- Nota para el negocio (opcional), con o sin cuenta. -->
              <div class="mt-3">
                <label class="tu-label" for="rc-nota">{{
                  $t("perfilPublico.agendar.nota")
                }}</label>
                <textarea
                  id="rc-nota"
                  v-model="nota"
                  class="tu-input"
                  rows="2"
                  maxlength="500"
                  :placeholder="$t('perfilPublico.agendar.notaPh')"
                />
              </div>

              <button
                class="tu-btn tu-btn-primario mt-4 w-full"
                type="button"
                :disabled="agendando || !listoParaAgendar"
                @click="agendar"
              >
                {{
                  agendando
                    ? $t("reservar.agendando")
                    : pagoObligatorio
                      ? $t("reservar.agendarYPagar")
                      : $t("perfilPublico.agendar.agendar")
                }}
              </button>
              <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
                {{ error }}
              </p>
            </div>

            <button
              type="button"
              class="tu-enlace text-sm"
              @click="volverA('horario')"
            >
              {{ $t("perfilPublico.agendar.atras") }}
            </button>
          </template>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.rc-contenedor {
  max-width: 66rem;
}
.rc-panel-horario {
  padding: clamp(1rem, 3vw, 1.75rem);
}
.rc-panel-horario .tu-label {
  margin-bottom: 0.65rem;
  font-size: 0.95rem;
}
.rc-panel-horario .rc-contexto {
  margin-bottom: 1.35rem;
  font-size: 0.95rem;
}
.reserva-opciones legend {
  float: left;
  width: 100%;
}
.reserva-opciones legend + * {
  clear: both;
}
.reserva-ayuda {
  color: var(--texto-suave);
  font-size: 0.875rem;
  line-height: 1.6;
  margin-bottom: 1rem;
}
.reserva-tarjetas {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}
.reserva-eleccion {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-height: 76px;
  padding: 1rem;
  border: 1px solid var(--borde);
  border-radius: 12px;
  cursor: pointer;
  background: var(--superficie);
}
.reserva-eleccion:hover {
  border-color: var(--primario);
}
.reserva-eleccion--activa {
  border-color: var(--primario);
  background: var(--primario-suave);
  box-shadow: inset 0 0 0 1px var(--primario);
}
.reserva-eleccion:has(:focus-visible) {
  outline: 2px solid var(--primario);
  outline-offset: 3px;
}
.reserva-eleccion input {
  accent-color: var(--primario);
  flex-shrink: 0;
}
.reserva-eleccion-texto {
  min-width: 0;
  overflow-wrap: anywhere;
}
.reserva-eleccion-texto strong {
  display: block;
  font-weight: 500;
  font-size: 0.9rem;
}

/* Servicio: miniatura redonda y duración en una etiqueta. */
.rc-servicio-foto {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: 999px;
  background: var(--fondo);
  border: 1px solid var(--borde);
  color: var(--texto-suave);
  font-weight: 600;
}
.rc-servicio-foto img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.rc-duracion {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  margin-top: 0.15rem;
  padding: 0.05rem 0.5rem;
  border-radius: 999px;
  background: var(--fondo);
  color: var(--texto-suave);
  font-size: 0.75rem;
}

/* Horas en cuadrícula pareja. */
.rc-horas {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(5.5rem, 1fr));
  gap: 0.6rem;
}
.rc-hora {
  min-height: 44px;
  padding: 0.6rem 0.5rem;
  border-radius: 10px;
  font-size: 0.9rem;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  background: var(--superficie);
  cursor: pointer;
  transition:
    border-color 150ms ease,
    background-color 150ms ease;
}
.rc-hora:hover {
  border-color: var(--primario) !important;
  background: var(--primario-suave);
}
.rc-hora:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 3px;
}
.rc-equipo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3.5rem;
  height: 3.5rem;
  flex-shrink: 0;
  border-radius: 999px;
  background: var(--fondo);
  border: 1px solid var(--borde);
  color: var(--texto-suave);
}

/* Tarjeta de sede: foto arriba (o el pin), nombre y dirección. */
.rc-sede {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid var(--borde);
  border-radius: 12px;
  cursor: pointer;
  background: var(--superficie);
}
.rc-sede:hover {
  border-color: var(--primario);
}
.rc-sede--activa {
  border-color: var(--primario);
  box-shadow: inset 0 0 0 1px var(--primario);
}
.rc-sede:focus-within {
  outline: 2px solid var(--primario);
  outline-offset: 3px;
}
.rc-sede-foto {
  display: block;
  width: 100%;
  aspect-ratio: 16 / 9;
  object-fit: cover;
  background: var(--fondo);
}
.rc-sede-sinfoto {
  display: grid;
  place-items: center;
  color: var(--texto-suave);
}
.rc-sede-sinfoto svg {
  width: 28px;
  height: 28px;
}
.rc-sede-texto {
  padding: 0.75rem 1rem 1rem;
  min-width: 0;
  overflow-wrap: anywhere;
  text-align: center;
}
.rc-sede-redes {
  display: flex;
  justify-content: center;
  gap: 0.75rem;
  margin-top: 0.6rem;
  color: var(--texto-suave);
}
.rc-sede-redes a:hover {
  color: var(--texto);
}

.rc-sede-texto strong {
  display: block;
  font-weight: 500;
}
.rc-sede--compacta {
  align-items: center;
  gap: 0.6rem;
  padding: 1rem;
}
.rc-sede--compacta .rc-sede-foto {
  width: 36px;
  height: 36px;
  aspect-ratio: auto;
  flex-shrink: 0;
  border-radius: 10px;
  background: var(--primario-suave);
  color: var(--enlace);
}
.rc-sede--compacta .rc-sede-sinfoto svg {
  width: 21px;
  height: 21px;
}
.rc-sede--compacta .rc-sede-texto {
  padding: 0;
}
.rc-sede-texto small {
  display: block;
  margin-top: 0.2rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
  line-height: 1.4;
}

/* Mapa de pasos */
.rc-pasos {
  display: flex;
  gap: 0.5rem;
}
.rc-paso {
  flex: 1;
  min-width: 0;
  padding-top: 0.6rem;
  border-top: 3px solid var(--borde);
}
.rc-paso--hecho,
.rc-paso--actual {
  border-top-color: var(--primario);
}
.rc-paso-marca {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  width: 100%;
  min-width: 0;
  font-size: 0.8rem;
  color: var(--texto-suave);
  text-align: center;
}
button.rc-paso-marca {
  cursor: pointer;
}
button.rc-paso-marca:hover .rc-paso-texto {
  text-decoration: underline;
}
.rc-paso--actual .rc-paso-marca {
  color: var(--texto);
  font-weight: 600;
}
.rc-paso-num {
  display: inline-grid;
  place-items: center;
  width: 1.4rem;
  height: 1.4rem;
  flex-shrink: 0;
  border-radius: 999px;
  border: 1px solid var(--borde);
  font-size: 0.72rem;
}
.rc-paso--actual .rc-paso-num {
  border-color: var(--primario);
  color: var(--primario);
}
.rc-paso--hecho .rc-paso-num {
  border-color: var(--primario);
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
.rc-paso-texto {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rc-contexto {
  margin-bottom: 0.75rem;
  font-size: 0.85rem;
  color: var(--texto-suave);
}

/* Resumen de la confirmación */
.rc-resumen > div {
  display: grid;
  grid-template-columns: 6.5rem minmax(0, 1fr) auto;
  gap: 0.75rem;
  align-items: start;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.rc-resumen dt {
  font-size: 0.85rem;
  color: var(--texto-suave);
}
.rc-resumen dd {
  min-width: 0;
  overflow-wrap: anywhere;
}

@media (max-width: 520px) {
  .reserva-tarjetas {
    grid-template-columns: 1fr;
  }
  /* En pantallas angostas solo se nombra el paso actual, que toma el espacio. */
  .rc-paso--actual {
    flex: 4;
  }
  .rc-paso--actual .rc-paso-texto {
    white-space: normal;
  }
  .rc-paso:not(.rc-paso--actual) .rc-paso-texto {
    display: none;
  }
  /* El título y «Cambiar» en una línea; lo elegido abajo, a todo lo ancho. */
  .rc-resumen > div {
    grid-template-columns: minmax(0, 1fr) auto;
    row-gap: 0.25rem;
  }
  .rc-resumen dt {
    grid-column: 1;
    grid-row: 1;
  }
  .rc-resumen > div > button {
    grid-column: 2;
    grid-row: 1;
  }
  .rc-resumen dd {
    grid-column: 1 / -1;
    grid-row: 2;
  }
}
</style>
