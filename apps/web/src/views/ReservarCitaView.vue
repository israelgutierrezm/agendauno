<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { dinero as dineroDelPais } from "@/lib/formato";
import { api, mensajeDeError, noEncontrado } from "@/lib/api";
import { separarTelefono } from "@/lib/ladas";
import { esDeOtraModalidad } from "@/lib/modalidad";
import { useRetornoPago } from "@/lib/retornoPago";
import { recordarNegocio } from "@/lib/negociosRecientes";
import { updateSeo } from "@/lib/seo";
import { puedeEntrar } from "@/lib/acceso";
import { esMiembro, nombreDeRol } from "@/lib/roles";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import CalendarioDias from "@/components/CalendarioDias.vue";
import CampoCelular from "@/components/CampoCelular.vue";
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
  // Se toma con su bono o membresía (solo con su cuenta, ADR 0091): no se paga.
  con_plan?: boolean;
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
  // Sedes donde atiende (tiene horario). Sin el dato, en todas.
  sucursales?: string[];
}
interface Opciones {
  estudio: {
    slug: string;
    nombre: string;
    logo_url: string | null;
    // Cómo llama el negocio a quien atiende (p. ej. «Barbero»).
    profesional?: string;
    // Su país y su lada (ADR 0103): la que se propone para el celular.
    pais?: string | null;
    // ¿Publicó su aviso de privacidad para sus clientes?
    aviso_privacidad?: boolean;
    lada?: string | null;
  };
  servicios: Servicio[];
  sucursales: Sucursal[];
  instructores: Persona[];
  // Si se paga en línea para confirmar o se puede pagar en la sucursal.
  cobro?: { pago_obligatorio: boolean; pago_en_linea: boolean };
  // Si el negocio manda los avisos de la cita por WhatsApp (ADR 0069).
  whatsapp?: boolean;
  // Hay servicios que se toman con bono o membresía (se usan desde la cuenta).
  hay_con_plan?: boolean;
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
const { t, te } = useI18n();
// Al volver de la página de pago: avisa cómo quedó.
const retornoPago = useRetornoPago();
const slug = computed(() => String(route.params.slug));

const opciones = ref<Opciones | null>(null);
const cargando = ref(true);
const noDisponible = ref(false);
// Sin red o con el servidor caído: se dice y se deja reintentar (no es que el negocio
// no esté disponible).
const errorCarga = ref<string | null>(null);
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

const busquedaServicio = ref("");
const categoriaServicio = ref("");
const normalizar = (texto: string): string =>
  texto
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .trim();
const categoriasServicios = computed(() => [
  ...new Set(
    (opciones.value?.servicios ?? [])
      .map((s) => s.categoria)
      .filter((s): s is string => Boolean(s)),
  ),
]);
const serviciosVisibles = computed(() =>
  (opciones.value?.servicios ?? []).filter(
    (s) =>
      (!categoriaServicio.value || s.categoria === categoriaServicio.value) &&
      normalizar(
        `${s.nombre} ${s.descripcion ?? ""} ${s.categoria ?? ""}`,
      ).includes(normalizar(busquedaServicio.value)),
  ),
);
const gruposServicios = computed(() => {
  const grupos = new Map<string, Servicio[]>();
  for (const s of serviciosVisibles.value) {
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
// Datos del invitado (ADR 0067): apellidos, celular con lada («+57 3001234567», de
// la lista completa de países, ADR 0103) y cómo nos conoció; y si acepta los avisos
// por WhatsApp (ADR 0069).
function datosVacios() {
  return {
    nombre: "",
    apellidos: "",
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
// País y lada del negocio: la lada que se propone. Si las opciones no los traen, se
// piden al escaparate al llegar a los datos (una vez).
const paisNegocio = ref<string | null>(null);
const ladaNegocio = ref<string | null>(null);
let regionPedida = false;
async function cargarRegionNegocio(): Promise<void> {
  if (regionPedida || ladaNegocio.value !== null) {
    return;
  }
  regionPedida = true;
  try {
    const { data } = await api.get<{
      data: { estudio?: { pais?: string | null; lada?: string | null } };
    }>(`/api/v1/app/${slug.value}/escaparate`);
    paisNegocio.value = data?.data?.estudio?.pais ?? null;
    ladaNegocio.value = data?.data?.estudio?.lada ?? null;
  } catch {
    // Sin escaparate, el celular va sin lada y el API le pone la del negocio.
  }
}
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
const buscandoSiguiente = ref(false);
const mensajeSiguiente = ref("");
const gruposHorarios = computed(() => {
  const grupos = {
    manana: [] as Slot[],
    tarde: [] as Slot[],
    noche: [] as Slot[],
  };
  for (const slot of slots.value) {
    const hora = Number(horaLocal(slot.inicia).split(":")[0]);
    grupos[hora < 12 ? "manana" : hora < 19 ? "tarde" : "noche"].push(slot);
  }
  return Object.entries(grupos)
    .filter(([, lista]) => lista.length)
    .map(([nombre, lista]) => ({ nombre, lista }));
});

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
// Solo quienes atienden en la sede elegida (tienen horario ahí): ofrecer a alguien
// de otra sede deja al cliente buscando fechas sin disponibilidad.
const profesionalesSede = computed<Persona[]>(() =>
  (opciones.value?.instructores ?? []).filter(
    (b) =>
      b.sucursales === undefined ||
      sucursalId.value === "" ||
      b.sucursales.includes(sucursalId.value),
  ),
);
const variosProfesionales = computed(() => profesionalesSede.value.length > 1);
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
  return profesionalesSede.value.filter((b) => ids.includes(b.id));
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
// Con sesión del equipo de ESTE negocio (no como cliente): la cita va con los datos
// del cliente; «entrar» no aplica (solo lo llevaría a su panel).
const sesionDelEquipo = computed(
  () =>
    sesion.autenticado &&
    sesion.slug === slug.value &&
    !esMiembro(sesion.usuario),
);
const rolDeSesion = computed(() =>
  nombreDeRol(
    sesion.usuario?.rol ?? "",
    sesion.usuario?.roles_disponibles,
    (llave) => (te(llave) ? t(llave) : null),
  ),
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

// Con los números del país del negocio (su moneda viene con cada precio).
function dinero(minor: number | null, moneda: string | null): string {
  return dineroDelPais(minor ?? 0, moneda ?? "MXN", paisNegocio.value);
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
  errorCarga.value = null;
  try {
    const { data } = await api.get<{ data: Opciones }>(
      `/api/v1/app/${slug.value}/citas/opciones`,
    );
    opciones.value = data.data;
    paisNegocio.value = data.data.estudio.pais ?? null;
    ladaNegocio.value = data.data.estudio.lada ?? null;
    updateSeo({
      title: `Agenda en ${data.data.estudio.nombre} | AgendaUno`,
      description: `Elige servicio, profesional y horario, y agenda tu cita en ${data.data.estudio.nombre}.`,
      path: `/agendar/${data.data.estudio.slug}`,
      image: data.data.estudio.logo_url ?? undefined,
    });
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
    await Promise.all([retomar(), cargarPorPagar(), sumarServiciosConBono()]);
  } catch (e) {
    // Un negocio de clases no agenda citas (ADR 0104): a su página. Pero el enlace
    // para pagar una clase apartada (?pagar) y el regreso de la pasarela llegan
    // aquí: esos se atienden sin el asistente.
    if (esDeOtraModalidad(e) && vieneAPagar()) {
      await cargarParaPagar();
    } else if (esDeOtraModalidad(e)) {
      void router.replace({
        name: "estudio-publico",
        params: { slug: slug.value },
      });
    } else if (noEncontrado(e)) {
      noDisponible.value = true;
    } else {
      errorCarga.value = mensajeDeError(e);
    }
  } finally {
    cargando.value = false;
  }
}

// Llega con el enlace para pagar (?pagar=<orden>) o de vuelta de la pasarela.
function vieneAPagar(): boolean {
  return String(route.query.pagar ?? "") !== "" || retornoPago.value !== null;
}
// Negocio de clases (ADR 0104): solo la clase por pagar del enlace, o el aviso al
// volver de pagarla. La marca sale del escaparate; la orden y el pago, de las rutas
// del núcleo (/citas/orden y /citas/pagar), que no dependen de la modalidad.
const soloPago = ref(false);
async function cargarParaPagar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: { estudio: Opciones["estudio"] } }>(
      `/api/v1/app/${slug.value}/escaparate`,
    );
    opciones.value = {
      estudio: data.data.estudio,
      servicios: [],
      sucursales: [],
      instructores: [],
    };
    soloPago.value = true;
    await cargarPorPagar();
  } catch {
    noDisponible.value = true;
  }
}
// Textos del enlace para pagar: los de la cita o, en un negocio de clases, los de la
// clase (`perfilPublico.agendar.pagoClase`).
type TextoPago =
  | "titulo"
  | "pagaAntesDe"
  | "yaPagada"
  | "vencida"
  | "deNuevo"
  | "enlaceInvalido";
const TEXTOS_PAGO_CITA: Record<TextoPago, string> = {
  titulo: "pagaTuCita",
  pagaAntesDe: "pagaAntesDe",
  yaPagada: "yaPagada",
  vencida: "vencida",
  deNuevo: "agendarDeNuevo",
  enlaceInvalido: "enlaceInvalido",
};
function textoPago(
  clave: TextoPago,
  valores: Record<string, unknown> = {},
): string {
  return t(
    soloPago.value
      ? `perfilPublico.agendar.pagoClase.${clave}`
      : `perfilPublico.agendar.${TEXTOS_PAGO_CITA[clave]}`,
    valores,
  );
}

// Con su cuenta, además de los servicios con precio, los que puede tomar con su
// bono o membresía vigente (ADR 0091): se agendan sin pagar.
async function sumarServiciosConBono(): Promise<void> {
  if (!clienteConCuenta.value || opciones.value === null) {
    return;
  }
  try {
    const { data } = await api.get<{ data: { servicios: Servicio[] } }>(
      `/api/v1/app/${slug.value}/mi/citas/opciones`,
    );
    const actuales = new Set(opciones.value.servicios.map((x) => x.id));
    const conBono = data.data.servicios.filter(
      (x) => x.con_plan === true && !actuales.has(x.id),
    );
    if (conBono.length > 0) {
      opciones.value.servicios = [...opciones.value.servicios, ...conBono].sort(
        (a, b) => a.nombre.localeCompare(b.nombre),
      );
    }
  } catch {
    // Sin sus opciones, agenda lo de la página pública.
  }
}
// Precio a la vista: los de su bono no se pagan.
function precioDe(x: Servicio | null | undefined): string {
  return x?.con_plan
    ? t("perfilPublico.agendar.conTuBono")
    : dinero(x?.precio_minor ?? null, x?.moneda ?? null);
}
// Se paga en línea para confirmar, salvo lo que se toma con su bono.
const requierePago = computed(
  () => pagoObligatorio.value && servicioSel.value?.con_plan !== true,
);
// Visitante sin cuenta en un negocio con bonos: se le invita a entrar para usarlo.
const avisoBono = computed(
  () =>
    opciones.value?.hay_con_plan === true &&
    !clienteConCuenta.value &&
    !sesionDelEquipo.value,
);

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
  mensajeSiguiente.value = "";
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

// Los días con atención no garantizan huecos: se comprueba el servicio y el equipo.
// Búsqueda acotada a 14 días, descartada si cambia la selección o se sale de la vista.
async function buscarSiguienteHorario(): Promise<void> {
  if (buscandoSiguiente.value || buscandoSlots.value || !fecha.value) return;
  const consulta = busquedaActual;
  const vigente = (): boolean =>
    consulta === busquedaActual && paso.value === "horario";
  buscandoSiguiente.value = true;
  mensajeSiguiente.value = "";
  try {
    const desde = new Date(`${fecha.value}T12:00:00Z`);
    desde.setUTCDate(desde.getUTCDate() + 1);
    const { data } = await api.get<{
      data: { fecha: string; abierto: boolean }[];
    }>(`/api/v1/app/${slug.value}/citas/dias`, {
      params: {
        sucursal_id: sucursalId.value,
        desde: desde.toISOString().slice(0, 10),
        dias: 14,
        ...(filtro.value ? { instructor_id: filtro.value } : {}),
      },
    });
    if (!vigente()) return;
    for (const dia of data.data
      .filter((d) => d.abierto && d.fecha > fecha.value)
      .slice(0, 14)) {
      const respuesta = await api.get<{ data: { slots: Slot[] } }>(
        `/api/v1/app/${slug.value}/citas/disponibilidad`,
        {
          params: {
            sucursal_id: sucursalId.value,
            fecha: dia.fecha,
            duracion_minutos: duracion.value,
            oferta_id: servicioId.value,
            ...(filtro.value ? { instructor_id: filtro.value } : {}),
          },
        },
      );
      if (!vigente()) return;
      if (respuesta.data.data.slots.length) {
        fecha.value = dia.fecha;
        return;
      }
    }
    mensajeSiguiente.value = t("perfilPublico.agendar.sinSiguiente");
  } catch (e) {
    if (vigente()) mensajeSiguiente.value = mensajeDeError(e);
  } finally {
    buscandoSiguiente.value = false;
  }
}
onBeforeUnmount(() => {
  busquedaActual++;
});

// Con quién de partida: el del filtro, el único profesional o «cualquiera».
function profesionalDePartida(): string {
  const lista = profesionalesSede.value;
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

// El celular como lo pide el API: el número y su lada («+57»), o nada. Sin la lada
// del negocio, la que el campo propuso por su cuenta no se manda: el API le pone la
// del negocio (no se supone México).
function celularConLada(): { celular: string | null; lada: string | null } {
  const propuesta = ladaNegocio.value ?? sesion.lada;
  const { lada, numero } = separarTelefono(datos.value.celular, propuesta);
  if (numero === "") {
    return { celular: null, lada: null };
  }
  return ladaNegocio.value === null && lada === propuesta
    ? { celular: numero, lada: null }
    : { celular: numero, lada: `+${lada}` };
}
// Al llegar a los datos (ahí se escribe el celular) se pide la lada del negocio.
watch(paso, (p) => {
  if (p === "confirmar") {
    void cargarRegionNegocio();
  }
});

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
        total_minor: servicioSel.value?.con_plan
          ? null
          : (servicioSel.value?.precio_minor ?? null),
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
        ...celularConLada(),
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
  // En un negocio de clases se reserva de nuevo desde su página.
  if (soloPago.value) {
    void router.replace({
      name: "estudio-publico",
      params: { slug: slug.value },
    });
    return;
  }
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
      v-else-if="errorCarga"
      class="mx-auto max-w-md px-4 py-20 text-center"
      role="alert"
    >
      <p class="text-lg font-semibold">{{ errorCarga }}</p>
      <button type="button" class="tu-btn tu-btn-primario mt-4" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </section>
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
          retornoPago !== "exito"
            ? $t("pagoEnLinea.cancelado")
            : soloPago
              ? $t("pagoEnLinea.exito")
              : $t("pagoEnLinea.citaExito")
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
          <p
            v-if="!soloPago"
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("reservar.titulo") }}
          </p>
        </div>
      </header>

      <!-- ===== Pagar una cita (o clase) apartada (enlace del correo) ===== -->
      <div
        v-if="porPagar || enlaceInvalido"
        class="mt-8 tu-card p-6"
        data-prueba="por-pagar"
      >
        <template v-if="porPagar">
          <h2 class="text-xl font-semibold">
            {{ textoPago("titulo") }}
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
            {{ textoPago("yaPagada") }}
          </p>
          <template v-else-if="sePuedePagar">
            <p
              v-if="porPagar.vence_en"
              class="mt-4 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                textoPago("pagaAntesDe", {
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
              {{ textoPago("vencida") }}
            </p>
            <button
              class="tu-btn tu-btn-primario mt-3 w-full"
              type="button"
              @click="agendarDeNuevo"
            >
              {{ textoPago("deNuevo") }}
            </button>
          </template>
        </template>
        <template v-else>
          <p class="text-sm" data-prueba="enlace-invalido">
            {{ textoPago("enlaceInvalido") }}
          </p>
          <button
            class="tu-btn tu-btn-primario mt-3 w-full"
            type="button"
            @click="agendarDeNuevo"
          >
            {{ textoPago("deNuevo") }}
          </button>
        </template>
        <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
          {{ error }}
        </p>
      </div>

      <!-- Negocio de clases de vuelta de la pasarela: el aviso va arriba; a su página -->
      <div
        v-else-if="soloPago"
        class="mt-8 tu-card p-6 text-center"
        data-prueba="pago-clase"
      >
        <RouterLink
          :to="{ name: 'estudio-publico', params: { slug } }"
          class="tu-btn tu-btn-primario w-full"
          >{{ $t("perfilPublico.agendar.pagoClase.verClases") }}</RouterLink
        >
      </div>

      <!-- Sin servicios de cita -->
      <p
        v-else-if="opciones.servicios.length === 0"
        class="mt-10 tu-card p-6 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("reservar.sinServicios") }}
      </p>

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
          {{
            servicioSel?.con_plan
              ? $t("perfilPublico.agendar.conTuBono")
              : dinero(resultado.total_minor, resultado.moneda)
          }}
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

        <div
          ref="pasoTitulo"
          class="rc-paso-contenido mt-5 space-y-4 scroll-mt-4"
        >
          <div class="rc-guia" data-prueba="guia-paso">
            <h2>{{ $t(`perfilPublico.agendar.guia.${paso}.titulo`) }}</h2>
            <p>{{ $t(`perfilPublico.agendar.guia.${paso}.ayuda`) }}</p>
          </div>
          <!-- Paso: sucursal (con foto si la tiene) -->
          <fieldset
            v-if="paso === 'sucursal'"
            class="tu-card p-5 reserva-opciones rc-sucursales"
          >
            <legend class="sr-only">{{ $t("sucursalesPub.titulo") }}</legend>
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
                  <span class="rc-sede-elegir"
                    >{{ $t("perfilPublico.agendar.elegirSucursal") }}
                    <span aria-hidden="true">→</span></span
                  >
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
            <!-- ¿Tiene un bono? Lo usa desde su cuenta (ADR 0091/0093). -->
            <div
              v-if="avisoBono"
              class="rc-aviso-bono"
              data-prueba="aviso-bono"
            >
              <span>{{ $t("perfilPublico.agendar.avisoBono") }}</span>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma shrink-0"
                data-prueba="entrar-bono"
                @click="entrarParaAgendar"
              >
                {{ $t("perfilPublico.agendar.entrarBono") }}
              </button>
            </div>
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
            <div
              v-if="opciones.servicios.length > 5"
              class="rc-busqueda-servicio"
            >
              <label for="rc-buscar-servicio" class="sr-only">{{
                $t("perfilPublico.agendar.buscarServicio")
              }}</label>
              <input
                id="rc-buscar-servicio"
                v-model="busquedaServicio"
                type="search"
                class="tu-input"
                :placeholder="$t('perfilPublico.agendar.buscarServicio')"
              />
            </div>
            <div
              v-if="categoriasServicios.length > 1"
              class="rc-categorias"
              :aria-label="$t('perfilPublico.agendar.categorias')"
              role="group"
            >
              <button
                type="button"
                :aria-pressed="categoriaServicio === ''"
                @click="categoriaServicio = ''"
              >
                {{ $t("perfilPublico.agendar.todosServicios") }}
              </button>
              <button
                v-for="categoria in categoriasServicios"
                :key="categoria"
                type="button"
                :aria-pressed="categoriaServicio === categoria"
                @click="categoriaServicio = categoria"
              >
                {{ categoria }}
              </button>
            </div>
            <p v-if="!serviciosVisibles.length" class="rc-vacio" role="status">
              {{ $t("perfilPublico.agendar.sinResultadosServicio") }}
              <button
                v-if="busquedaServicio || categoriaServicio"
                type="button"
                class="tu-enlace"
                @click="
                  busquedaServicio = '';
                  categoriaServicio = '';
                "
              >
                {{ $t("perfilPublico.agendar.limpiarBusqueda") }}
              </button>
            </p>
            <fieldset class="rc-catalogo">
              <legend class="sr-only">{{ $t("reservar.servicio") }}</legend>
              <div
                v-for="g in gruposServicios"
                :key="g.nombre"
                class="rc-grupo-servicios"
              >
                <p
                  v-if="g.nombre"
                  class="rc-categoria-titulo"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ g.nombre }}
                </p>
                <label
                  v-for="s in g.lista"
                  :key="s.id"
                  class="rc-servicio"
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
                      <span class="font-medium block rc-nombre-servicio">{{
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
                  <span class="rc-servicio-precio">{{ precioDe(s) }}</span>
                </label>
              </div>
            </fieldset>
            <button
              v-if="variasSedes"
              type="button"
              class="tu-enlace rc-atras-servicios"
              @click="volverA('sucursal')"
            >
              {{ $t("perfilPublico.agendar.atras") }}
            </button>
          </div>

          <!-- Paso: fecha y hora (de todo el equipo o de quien se prefiera) -->
          <template v-else-if="paso === 'horario'">
            <p
              v-if="profesionalesSede.length === 0"
              class="tu-card p-5 reserva-ayuda"
            >
              {{ $t("reservar.sinProfesionales") }}
            </p>
            <div v-else class="tu-card rc-panel-horario">
              <div
                class="rc-contexto rc-eleccion-resumen"
                data-prueba="contexto"
              >
                <span class="rc-ticket-icono" aria-hidden="true"
                  ><IconoNav nombre="agenda" :tam="22"
                /></span>
                <span class="rc-eleccion-texto"
                  ><strong>{{ servicioSel?.nombre }}</strong>
                  <span
                    >{{ sucursalSel?.nombre
                    }}<template v-if="servicioSel?.duracion_minutos">
                      ·
                      {{
                        $t("reservar.duracionMin", {
                          n: servicioSel.duracion_minutos,
                        })
                      }}</template
                    >
                    ·
                    {{ precioDe(servicioSel) }}</span
                  >
                </span>
                <button
                  type="button"
                  class="tu-enlace"
                  @click="volverA('servicio')"
                >
                  {{ $t("perfilPublico.agendar.cambiar") }}
                </button>
              </div>
              <!-- Ver horarios de: todo el equipo o alguien, por su foto. -->
              <ElegirProfesional
                v-if="variosProfesionales"
                v-model="filtro"
                class="mb-4 rc-selector-profesional"
                :profesionales="profesionalesSede"
              />
              <div
                v-else-if="profesionalesSede.length === 1"
                class="rc-profesional-unico"
              >
                <FotoAmpliable
                  :nombre="profesionalesSede[0].nombre"
                  :foto="profesionalesSede[0].foto_url"
                />
                <span
                  ><small>{{
                    $t("perfilPublico.agendar.quienTeAtiende")
                  }}</small
                  ><strong>{{ profesionalesSede[0].nombre }}</strong></span
                >
              </div>
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
                role="status"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("reservar.calculando") }}
              </p>
              <template v-else-if="fecha !== '' && slotsCargados">
                <div
                  v-if="slots.length === 0"
                  class="rc-vacio"
                  data-prueba="sin-horarios"
                >
                  <IconoNav nombre="agenda" :tam="26" />
                  <p>{{ $t("reservar.sinHuecos") }}</p>
                  <button
                    type="button"
                    class="tu-btn"
                    :disabled="buscandoSiguiente"
                    data-prueba="siguiente-horario"
                    @click="buscarSiguienteHorario"
                  >
                    {{
                      $t(
                        buscandoSiguiente
                          ? "perfilPublico.agendar.buscandoSiguiente"
                          : "perfilPublico.agendar.buscarSiguiente",
                      )
                    }}
                  </button>
                  <p role="status" class="text-sm">{{ mensajeSiguiente }}</p>
                </div>
                <div v-else class="mt-3">
                  <p class="tu-label">{{ $t("reservar.hora") }}</p>
                  <p class="rc-zona-horaria">
                    {{ $t("perfilPublico.agendar.horaSucursal") }}
                  </p>
                  <fieldset
                    v-for="grupo in gruposHorarios"
                    :key="grupo.nombre"
                    class="rc-franja"
                  >
                    <legend>
                      {{ $t(`perfilPublico.agendar.franjas.${grupo.nombre}`) }}
                      <span>{{ grupo.lista.length }}</span>
                    </legend>
                    <div class="rc-horas">
                      <button
                        v-for="s in grupo.lista"
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
                  </fieldset>
                </div>
              </template>
            </div>

            <!-- Con quién: tras la hora, solo quienes están libres entonces. -->
            <fieldset
              v-if="slotSel !== '' && eligeConQuien"
              class="tu-card p-5 reserva-opciones rc-libres"
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

            <div class="rc-acciones">
              <button
                type="button"
                class="tu-enlace text-sm"
                @click="volverA('servicio')"
              >
                {{ $t("perfilPublico.agendar.atras") }}
              </button>
              <p class="rc-seleccion-hora" role="status">
                <template v-if="slotSel"
                  ><strong>{{ horaLocal(slotSel) }}</strong
                  ><span>{{ diaLocal(slotSel) }}</span></template
                ><template v-else>{{
                  $t("perfilPublico.agendar.eligeHorarioContinuar")
                }}</template>
              </p>
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
            <div class="rc-confirmacion">
              <aside
                class="tu-card rc-ticket"
                data-prueba="resumen"
                :aria-label="$t('perfilPublico.agendar.revisa')"
              >
                <div class="rc-ticket-cabecera">
                  <img
                    v-if="servicioSel?.foto_url"
                    :src="servicioSel.foto_url"
                    alt=""
                    class="rc-ticket-imagen"
                  />
                  <span v-else class="rc-ticket-icono" aria-hidden="true"
                    ><IconoNav nombre="agenda" :tam="25"
                  /></span>
                  <div>
                    <p class="rc-antetitulo">{{ opciones.estudio.nombre }}</p>
                    <h3 class="font-semibold">
                      {{ $t("perfilPublico.agendar.revisa") }}
                    </h3>
                  </div>
                </div>
                <dl class="rc-resumen mt-3">
                  <div>
                    <dt>
                      <IconoNav nombre="etiqueta" :tam="16" />{{
                        $t("perfilPublico.agendar.servicio")
                      }}
                    </dt>
                    <dd>
                      <span class="font-medium">{{ servicioSel?.nombre }}</span>
                      <span
                        v-if="servicioSel?.duracion_minutos"
                        class="rc-detalle-secundario"
                        >{{
                          $t("reservar.duracionMin", {
                            n: servicioSel.duracion_minutos,
                          })
                        }}</span
                      >
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
                      :aria-label="$t('perfilPublico.agendar.cambiarServicio')"
                      @click="volverA('servicio')"
                    >
                      {{ $t("perfilPublico.agendar.cambiar") }}
                    </button>
                  </div>
                  <div>
                    <dt>
                      <IconoNav nombre="agenda" :tam="16" />{{
                        $t("perfilPublico.agendar.cuando")
                      }}
                    </dt>
                    <dd>
                      <span class="font-medium">{{ diaLocal(slotSel) }}</span>
                      <span class="rc-hora-resumen">{{
                        horaLocal(slotSel)
                      }}</span>
                      <span class="rc-detalle-secundario" :title="zona">{{
                        $t("perfilPublico.agendar.horaSucursal")
                      }}</span>
                    </dd>
                    <button
                      type="button"
                      class="tu-enlace text-sm"
                      :aria-label="$t('perfilPublico.agendar.cambiarHorario')"
                      @click="volverA('horario')"
                    >
                      {{ $t("perfilPublico.agendar.cambiar") }}
                    </button>
                  </div>
                  <div>
                    <dt>
                      <IconoNav nombre="miembros" :tam="16" />{{
                        $t("perfilPublico.agendar.quienTeAtiende")
                      }}
                    </dt>
                    <dd class="rc-profesional-resumen">
                      <img
                        v-if="barberoSel?.foto_url"
                        :src="barberoSel.foto_url"
                        alt=""
                        class="rc-avatar-resumen"
                      />
                      <span>{{
                        barberoId === CUALQUIERA
                          ? $t("perfilPublico.agendar.cualquiera")
                          : barberoSel?.nombre
                      }}</span>
                    </dd>
                    <button
                      type="button"
                      class="tu-enlace text-sm"
                      :aria-label="
                        $t('perfilPublico.agendar.cambiarProfesional')
                      "
                      @click="volverA('horario')"
                    >
                      {{ $t("perfilPublico.agendar.cambiar") }}
                    </button>
                  </div>
                  <div v-if="sucursalSel" data-prueba="donde">
                    <dt>
                      <IconoNav nombre="ubicacion" :tam="16" />{{
                        $t("perfilPublico.agendar.donde")
                      }}
                    </dt>
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
                      :aria-label="$t('perfilPublico.agendar.cambiarSucursal')"
                      @click="volverA('sucursal')"
                    >
                      {{ $t("perfilPublico.agendar.cambiar") }}
                    </button>
                  </div>
                </dl>
                <div class="rc-precio-resumen" data-prueba="precio-resumen">
                  <span>{{ $t("perfilPublico.agendar.precioServicio") }}</span>
                  <strong>{{ precioDe(servicioSel) }}</strong>
                </div>
                <p class="rc-pago-ayuda" data-prueba="pago-ayuda">
                  <IconoNav nombre="pasarelas" :tam="18" />{{
                    $t(
                      servicioSel?.con_plan
                        ? "perfilPublico.agendar.pagoConBono"
                        : pagoObligatorio
                          ? "perfilPublico.agendar.pagoPrevio"
                          : pagoEnLinea
                            ? "perfilPublico.agendar.pagoFlexible"
                            : "perfilPublico.agendar.pagoEnLugar",
                    )
                  }}
                </p>
              </aside>

              <div class="tu-card rc-datos">
                <div class="rc-datos-cabecera">
                  <span class="rc-ticket-icono" aria-hidden="true"
                    ><IconoNav nombre="miembros" :tam="23"
                  /></span>
                  <div>
                    <h3>
                      {{
                        $t(
                          sesionDelEquipo
                            ? "perfilPublico.agendar.datosCliente"
                            : "reservar.datos",
                        )
                      }}
                    </h3>
                    <p>
                      {{
                        $t(
                          clienteConCuenta
                            ? "perfilPublico.agendar.datosCuentaAyuda"
                            : "perfilPublico.agendar.datosAyuda",
                        )
                      }}
                    </p>
                  </div>
                </div>
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
                  <p
                    v-if="sesionDelEquipo"
                    class="rc-aviso-equipo"
                    :style="{ color: 'var(--texto-suave)' }"
                    data-prueba="sesion-equipo"
                  >
                    {{
                      $t("perfilPublico.agendar.sesionEquipo", {
                        rol: rolDeSesion,
                        negocio: sesion.estudio?.nombre ?? "",
                      })
                    }}
                    <RouterLink
                      v-if="puedeEntrar('agenda', sesion)"
                      :to="{ name: 'agenda' }"
                      class="tu-enlace rc-enlace-equipo"
                      >{{ $t("perfilPublico.agendar.desdeAgenda") }}</RouterLink
                    >
                  </p>
                  <p
                    v-else
                    class="rc-acceso-datos"
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
                          :placeholder="$t('perfilPublico.agendar.opcional')"
                        />
                      </div>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-3">
                      <div>
                        <label class="tu-label" for="rc-cel">{{
                          $t("reservar.celular")
                        }}</label>
                        <CampoCelular
                          id="rc-cel"
                          v-model="datos.celular"
                          :pais="paisNegocio"
                          :lada="ladaNegocio"
                          maxlength="20"
                          autocomplete="tel-national"
                          :placeholder="$t('perfilPublico.agendar.opcional')"
                        />
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
                          aria-describedby="rc-email-ayuda"
                          required
                        />
                        <span id="rc-email-ayuda" class="tu-hint">{{
                          $t("perfilPublico.agendar.correoAyuda")
                        }}</span>
                      </div>
                    </div>
                    <details class="rc-opcional">
                      <summary>
                        {{ $t("perfilPublico.agendar.comoNosConociste") }}
                      </summary>
                      <label class="sr-only" for="rc-origen">{{
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
                    </details>
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
                <details class="rc-opcional mt-3">
                  <summary>{{ $t("perfilPublico.agendar.nota") }}</summary>
                  <label class="sr-only" for="rc-nota">{{
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
                </details>

                <button
                  class="tu-btn tu-btn-primario mt-4 w-full"
                  type="button"
                  :disabled="agendando || !listoParaAgendar"
                  @click="agendar"
                >
                  {{
                    agendando
                      ? $t("reservar.agendando")
                      : requierePago
                        ? $t("reservar.agendarYPagar")
                        : $t("perfilPublico.agendar.agendar")
                  }}
                </button>
                <!-- Antes de dar sus datos: quién los recibe y su aviso de privacidad. -->
                <p
                  v-if="!clienteConCuenta"
                  class="mt-3 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  data-prueba="aviso-datos"
                >
                  {{
                    $t("reservar.avisoDatos", {
                      negocio: opciones?.estudio.nombre ?? "",
                    })
                  }}
                  <RouterLink
                    v-if="opciones?.estudio.aviso_privacidad"
                    :to="{ name: 'aviso-negocio', params: { slug } }"
                    target="_blank"
                    class="tu-enlace"
                    >{{ $t("reservar.verAviso") }}</RouterLink
                  >
                </p>
                <p
                  v-if="error"
                  class="mt-3 text-sm"
                  style="color: var(--error)"
                >
                  {{ error }}
                </p>
              </div>
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
.rc-busqueda-servicio {
  margin-bottom: 1rem;
  max-width: 32rem;
}
.rc-categorias {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1.25rem;
}
.rc-categorias button {
  padding: 0.55rem 0.85rem;
  min-height: 44px;
  border: 1px solid var(--borde);
  border-radius: 10px;
  font-size: 0.85rem;
  cursor: pointer;
}
.rc-categorias button[aria-pressed="true"] {
  background: var(--primario-suave);
  border-color: var(--primario);
  color: var(--enlace);
}
.rc-catalogo {
  min-width: 0;
}
.rc-grupo-servicios {
  display: grid;
  gap: 0.75rem;
  margin-top: 0.75rem;
}
.rc-categoria-titulo {
  color: var(--texto-suave);
  font-size: 0.85rem;
  font-weight: 600;
  margin-top: 0.5rem;
}
.rc-servicio {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 1rem;
  padding: 1.1rem;
  border: 1px solid var(--borde);
  border-radius: 14px;
  cursor: pointer;
}
.rc-servicio input {
  accent-color: var(--primario);
}
.rc-servicio-precio {
  font-size: 1.05rem;
  font-weight: 600;
  white-space: nowrap;
  padding: 0.5rem 0.7rem;
  border-radius: 8px;
  background: var(--fondo);
}
.rc-atras-servicios {
  display: inline-block;
  margin-top: 1.25rem;
}
.rc-sede-elegir {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin-top: 1rem;
  color: var(--enlace);
  font-size: 0.85rem;
  font-weight: 500;
}
.rc-sede--compacta {
  position: relative;
}
.rc-sede--compacta .rc-sede-texto {
  width: 100%;
}
.rc-sede--compacta .rc-sede-elegir {
  border-top: 1px solid var(--borde);
  padding-top: 0.7rem;
}
.rc-eleccion-resumen {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--borde);
}
.rc-eleccion-texto {
  display: flex;
  flex: 1;
  min-width: 0;
  flex-direction: column;
  gap: 0.2rem;
  overflow-wrap: anywhere;
}
.rc-eleccion-texto strong {
  color: var(--texto);
  font-weight: 600;
}
.rc-eleccion-texto > span {
  font-size: 0.8rem;
}
.rc-profesional-unico {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  margin-bottom: 1.25rem;
}
.rc-profesional-unico strong,
.rc-profesional-unico small {
  display: block;
}
.rc-profesional-unico small {
  color: var(--texto-suave);
}
.rc-profesional-unico strong {
  font-weight: 500;
}
.rc-selector-profesional :deep(.ep-opcion) {
  gap: 0.7rem;
  padding: 0.75rem;
}
.rc-selector-profesional :deep(.ep-icono) {
  width: 2.5rem;
  height: 2.5rem;
}
.rc-selector-profesional :deep(.ep-texto strong) {
  font-size: 0.9rem;
}
.rc-selector-profesional :deep(.ep-texto small) {
  font-size: 0.75rem;
}
.rc-selector-profesional :deep(.ep-profesional) {
  flex: 0 0 auto;
  width: 7rem;
  min-width: 7rem;
  border-color: transparent;
  background: transparent;
}
.rc-selector-profesional :deep(.ep-profesional--activo) {
  border-color: var(--primario);
  background: var(--primario-suave);
}
.rc-zona-horaria {
  color: var(--texto-suave);
  font-size: 0.8rem;
  margin-top: -0.35rem;
}
.rc-franja {
  margin-top: 1rem;
  min-width: 0;
}
.rc-franja legend {
  font-size: 0.85rem;
  font-weight: 500;
  margin-bottom: 0.6rem;
}
.rc-franja legend span {
  margin-left: 0.3rem;
  color: var(--texto-suave);
  font-size: 0.75rem;
  font-weight: 400;
}
.rc-vacio {
  display: grid;
  justify-items: center;
  gap: 0.8rem;
  padding: 1.5rem 1rem;
  margin-top: 1rem;
  border-radius: 12px;
  background: var(--fondo);
  color: var(--texto-suave);
  text-align: center;
}
.rc-libres .reserva-eleccion {
  min-height: 64px;
  padding: 0.75rem;
}
.rc-libres .rc-equipo {
  width: 2.5rem;
  height: 2.5rem;
}
.rc-acciones {
  display: flex;
  align-items: center;
  gap: 1rem;
  justify-content: space-between;
  padding: 1rem;
  border: 1px solid var(--borde);
  border-radius: 14px;
  background: var(--superficie);
}
.rc-seleccion-hora {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: flex-end;
  gap: 0.5rem;
  flex: 1;
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.rc-seleccion-hora strong {
  color: var(--texto);
  font-size: 1.1rem;
}
.rc-categorias button:focus-visible,
.rc-servicio:has(:focus-visible) {
  outline: 2px solid var(--primario);
  outline-offset: 3px;
}
@media (max-width: 520px) {
  .rc-servicio {
    gap: 0.7rem;
    padding: 0.85rem;
  }
  .rc-servicio-precio {
    grid-column: 1 / -1;
    justify-self: end;
    font-size: 0.95rem;
  }
  .rc-acciones {
    flex-wrap: wrap;
  }
  .rc-seleccion-hora {
    order: -1;
    flex-basis: 100%;
    justify-content: flex-start;
  }
  .rc-eleccion-resumen > .rc-ticket-icono {
    display: none;
  }
}
.rc-guia {
  margin-bottom: 1.25rem;
}
.rc-guia h2 {
  font-size: clamp(1.15rem, 2.5vw, 1.4rem);
  font-weight: 600;
  line-height: 1.35;
}
.rc-guia p {
  margin-top: 0.35rem;
  color: var(--texto-suave);
  font-size: 0.9rem;
}
.rc-servicio:hover,
.rc-servicio:has(:focus-visible) {
  border-color: var(--primario);
}
.rc-nombre-servicio {
  display: block;
  overflow-wrap: anywhere;
}
.rc-panel-horario {
  padding: clamp(1rem, 3vw, 1.75rem);
}
.rc-panel-horario .tu-label {
  margin-bottom: 0.65rem;
  font-size: 0.95rem;
}
.rc-aviso-bono {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton);
  font-size: 0.9rem;
}
.rc-panel-horario .rc-contexto {
  margin-bottom: 1.35rem;
  font-size: 0.95rem;
}
.reserva-opciones legend:not(.sr-only) {
  float: left;
  width: 100%;
}
.reserva-opciones legend:not(.sr-only) + * {
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
.rc-sucursales .rc-sede-foto {
  height: 10rem;
  aspect-ratio: auto;
}
.rc-sucursales .rc-sede--compacta .rc-sede-foto {
  height: 36px;
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
  min-height: 44px;
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
.rc-confirmacion {
  display: grid;
  gap: 1.25rem;
  align-items: start;
  margin-bottom: 1.25rem;
}
.rc-ticket,
.rc-datos {
  min-width: 0;
  padding: clamp(1rem, 2.5vw, 1.5rem);
  border-radius: 18px;
}
.rc-ticket-cabecera,
.rc-datos-cabecera {
  display: flex;
  align-items: center;
  gap: 0.8rem;
}
.rc-ticket-cabecera h3,
.rc-datos-cabecera h3 {
  font-size: 1.1rem;
  font-weight: 600;
}
.rc-ticket-imagen,
.rc-ticket-icono {
  width: 3rem;
  height: 3rem;
  flex-shrink: 0;
  border-radius: 12px;
  object-fit: cover;
}
.rc-ticket-icono {
  display: grid;
  place-items: center;
  color: var(--enlace);
  background: var(--primario-suave);
}
.rc-antetitulo,
.rc-detalle-secundario,
.rc-datos-cabecera p {
  color: var(--texto-suave);
  font-size: 0.8rem;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.rc-detalle-secundario {
  display: block;
  margin-top: 0.2rem;
}
.rc-resumen > div {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.25rem 0.75rem;
  align-items: start;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.rc-resumen dt {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  grid-column: 1;
  grid-row: 1;
  font-size: 0.85rem;
  color: var(--texto-suave);
}
.rc-resumen dd {
  grid-column: 1 / -1;
  grid-row: 2;
  min-width: 0;
  overflow-wrap: anywhere;
}
.rc-resumen > div > button {
  grid-column: 2;
  grid-row: 1;
  min-height: 32px;
}
.rc-hora-resumen {
  display: inline-block;
  margin-left: 0.5rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.rc-profesional-resumen {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
.rc-avatar-resumen {
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  object-fit: cover;
  border-radius: 50%;
}
.rc-precio-resumen {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-top: 0.4rem;
  padding: 0.8rem 1rem;
  border-radius: 12px;
  background: var(--primario-suave);
  font-size: 0.85rem;
}
.rc-precio-resumen strong {
  font-size: 1.35rem;
  font-weight: 600;
}
.rc-pago-ayuda {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin-top: 0.85rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
  line-height: 1.5;
}
.rc-pago-ayuda svg,
.rc-resumen dt svg {
  flex-shrink: 0;
}
.rc-datos-cabecera {
  margin-bottom: 1rem;
}
.rc-datos-cabecera p {
  margin-top: 0.25rem;
}
.rc-aviso-equipo {
  padding: 0.8rem;
  margin-bottom: 1rem;
  border-left: 3px solid var(--borde);
  border-radius: 0 8px 8px 0;
  background: var(--fondo);
  font-size: 0.8rem;
  line-height: 1.6;
}
.rc-enlace-equipo {
  display: block;
  margin-top: 0.3rem;
}
.rc-acceso-datos {
  margin-bottom: 1rem;
  font-size: 0.85rem;
}
.rc-opcional {
  border-top: 1px solid var(--borde);
  font-size: 0.85rem;
}
.rc-opcional summary {
  padding: 0.8rem 0;
  min-height: 44px;
  cursor: pointer;
  color: var(--texto-suave);
}
.rc-opcional[open] summary {
  color: var(--texto);
}
.rc-datos input::placeholder {
  color: var(--texto-suave);
  opacity: 0.65;
}
.rc-datos #rc-cel {
  min-width: 0;
}
@media (min-width: 960px) {
  .rc-confirmacion {
    grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
  }
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
