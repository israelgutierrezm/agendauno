<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import PestanasSeccion from "@/components/PestanasSeccion.vue";
import PlantillasWhatsAppNegocio, {
  type PlantillaWhatsApp,
} from "@/components/PlantillasWhatsAppNegocio.vue";
import RangoFechas from "@/components/RangoFechas.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { eventosPorArea, useEventosNegocio } from "@/lib/eventosNegocio";
import {
  fechaEnUrl,
  opcionEnUrl,
  useFiltrosEnUrl,
  usePestanaEnUrl,
} from "@/lib/filtrosEnUrl";
import { conTerminosActuales } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Comunicación con los alumnos: difusiones a un segmento, mensajes automáticos
 * que se disparan con los eventos del negocio (plantillas por evento y canal) y la
 * bandeja de salida con el estado de cada mensaje. Son tres cosas distintas y cada
 * pestaña dice cuál es; las reglas que crean tareas están en Tareas › Automatización.
 */
const { t, te } = useI18n();
const toast = useToastStore();
// Cada aviso con su nombre en palabras (nunca «pago.anulado»).
const { nombreEvento } = useEventosNegocio();

interface Segmento {
  clave: string;
  etiqueta: string;
  descripcion: string;
  total: number;
}
interface Difusion {
  id: string;
  segmento: string;
  segmento_etiqueta: string;
  canal: string;
  asunto: string;
  total: number;
  enviada_en: string | null;
  // B08: en qué va (enviando, en pausa o cancelada) y cuánto falta.
  estado?: "enviando" | "pausada" | "cancelada";
  enviados?: number;
  pendientes?: number;
  // Agotaron sus intentos (ya no se reintentan).
  fallidos?: number;
  descartados?: number;
}
// A cuántos llega por ese canal y quién queda fuera (ADR 0166), antes de enviar.
interface Alcance {
  elegibles: number;
  sin_consentimiento: number;
  sin_email: number;
  sin_app: number;
  // Por WhatsApp (ADR 0190): sin aceptarlo o sin celular, y lo que gasta.
  sin_whatsapp?: number;
  mensajes?: number;
  costo_minor?: number | null;
  // Pesos en México (más IVA); fuera de México, dólares sin IVA (ADR 0186).
  costo_moneda?: string;
}

type Canal = "interno" | "email" | "push" | "whatsapp";
// A quién va el mensaje automático: la persona del evento, el profesional de la cita
// o el equipo del negocio (a quien puede atenderlo).
type Destinatario = "persona" | "profesional" | "equipo";
interface Plantilla {
  id: string;
  clave: string;
  canal: Canal;
  destinatario: Destinatario;
  asunto: string;
  cuerpo: string;
  activo: boolean;
}
interface Mensaje {
  id: string;
  persona: string | null;
  canal: Canal;
  destinatario: string | null;
  asunto: string;
  estado: "encolado" | "enviado" | "fallido" | "descartado";
  intentos: number;
  creado_en?: string | null;
  enviado_en: string | null;
  // Lo que Meta avisa de un WhatsApp (ADR 0074).
  entregado_en?: string | null;
  leido_en?: string | null;
  ultimo_error?: string | null;
}
type Pestana = "difusion" | "automaticos" | "whatsapp" | "salida";

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeAutomaticos = computed(() =>
  sesion.puede("comunicaciones.gestionar"),
);
const puedeSalida = computed(() => sesion.puede("comunicaciones.ver"));
// Las reglas que crean tareas (Tareas › Automatización): se enlazan si las puede ver.
const puedeReglasTareas = computed(() =>
  sesion.puede("automatizaciones.gestionar"),
);
const puedeEliminar = computed(() => sesion.puede("comunicaciones.eliminar"));
// Pausar, reanudar o cancelar una campaña en curso (B08).
const puedeControlar = computed(() => sesion.puede("comunicaciones.gestionar"));
const controlando = ref<string | null>(null);
async function controlar(
  d: Difusion,
  accion: "pausar" | "reanudar" | "cancelar",
): Promise<void> {
  if (
    accion === "cancelar" &&
    !(await confirmar(t("comunicaciones.confirmarCancelar"), { peligro: true }))
  ) {
    return;
  }
  controlando.value = d.id;
  try {
    const { data } = await api.post<{ data: Difusion }>(
      `${base.value}/comunicaciones/difusiones/${d.id}/${accion}`,
      {},
    );
    difusiones.value = difusiones.value.map((x) =>
      x.id === d.id ? data.data : x,
    );
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    controlando.value = null;
  }
}
const segmentos = ref<Segmento[]>([]);
const difusiones = ref<Difusion[]>([]);
// Por páginas: un negocio que difunde varias veces por semana junta cientos.
const metaDifusiones = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
} | null>(null);
const paginaDifusiones = ref(1);
async function cargarDifusiones(n = paginaDifusiones.value): Promise<void> {
  const { data } = await api.get<{
    data: Difusion[];
    meta?: typeof metaDifusiones.value;
  }>(`${base.value}/comunicaciones/difusiones`, { params: { page: n } });
  difusiones.value = data.data;
  metaDifusiones.value = data.meta ?? null;
  paginaDifusiones.value = n;
}
async function irPaginaDifusiones(n: number): Promise<void> {
  try {
    await cargarDifusiones(n);
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
const cargando = ref(true);
const enviando = ref(false);
const error = ref<string | null>(null);
// Segmentos e historial no se pudieron consultar: no es «aún no hay».
const errorCarga = ref(false);
// Lo que impidió enviar (p. ej. una difusión repetida), dentro de la revisión.
const errorEnvio = ref<string | null>(null);
const exito = ref<number | null>(null);

const form = ref({
  segmento: "",
  canal: "interno" as Canal,
  asunto: "",
  cuerpo: "",
  // Por WhatsApp: la plantilla aprobada del negocio (ADR 0190).
  plantilla_whatsapp: "",
});

// Las plantillas de WhatsApp aprobadas, para campañas por WhatsApp.
const plantillasWhatsApp = ref<PlantillaWhatsApp[]>([]);
const esCampanaWhatsApp = computed(() => form.value.canal === "whatsapp");
const plantillaElegida = computed(() =>
  plantillasWhatsApp.value.find((p) => p.id === form.value.plantilla_whatsapp),
);
async function cargarPlantillasWhatsApp(): Promise<void> {
  if (!canalesDifusion.value.includes("whatsapp")) {
    return;
  }
  try {
    const { data } = await api.get<{ data: PlantillaWhatsApp[] }>(
      `${base.value}/comunicaciones/whatsapp/plantillas`,
    );
    plantillasWhatsApp.value = data.data.filter((p) => p.estado === "aprobada");
  } catch {
    plantillasWhatsApp.value = [];
  }
}
const dineroEn = (minor: number, moneda = "MXN"): string =>
  new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);

const segmentoSel = computed(() =>
  segmentos.value.find((s) => s.clave === form.value.segmento),
);
// A cuántos llega de verdad por el canal elegido (lo calcula el API con la regla del
// envío); mientras no llega, el total del segmento.
const alcance = ref<Alcance | null>(null);
const destinatarios = computed(
  () => alcance.value?.elegibles ?? segmentoSel.value?.total ?? 0,
);
// Quién queda fuera y por qué, en una línea.
const excluidos = computed(() => {
  const a = alcance.value;
  if (a === null) {
    return null;
  }
  const partes = [
    a.sin_consentimiento > 0
      ? t(
          "operacion.comunicacion.sinConsentimiento",
          { n: a.sin_consentimiento },
          a.sin_consentimiento,
        )
      : null,
    a.sin_email > 0
      ? t("operacion.comunicacion.sinEmail", { n: a.sin_email }, a.sin_email)
      : null,
    a.sin_app > 0
      ? t("operacion.comunicacion.sinApp", { n: a.sin_app }, a.sin_app)
      : null,
    (a.sin_whatsapp ?? 0) > 0
      ? t(
          "comunicacionPantalla.whatsapp.sinWhatsApp",
          { n: a.sin_whatsapp },
          a.sin_whatsapp ?? 0,
        )
      : null,
  ].filter(Boolean);
  return partes.length > 0 ? partes.join(" · ") : null;
});

// Antes de un envío masivo: a cuántos, por qué canal y cómo se verá (revisión).
const revisando = ref(false);
async function abrirRevision(): Promise<void> {
  errorEnvio.value = null;
  alcance.value = null;
  revisando.value = true;
  try {
    const { data } = await api.get<{ data: Alcance }>(
      `${base.value}/comunicaciones/alcance`,
      {
        params: {
          segmento: form.value.segmento,
          canal: form.value.canal,
          ...(esCampanaWhatsApp.value
            ? { plantilla_whatsapp: form.value.plantilla_whatsapp }
            : {}),
        },
      },
    );
    alcance.value = data.data;
  } catch {
    // Sin el cálculo se queda el total del segmento; el envío dice a cuántos llegó.
  }
}
const EJEMPLO: Record<string, string> = {
  persona_nombre: "Ana López",
  persona_email: "ana@correo.mx",
};
function conEjemplo(texto: string): string {
  return texto.replace(
    /\{\{\s*(persona_nombre|persona_email)\s*\}\}/g,
    (_, clave: string) => EJEMPLO[clave] ?? "",
  );
}
const puedeEnviar = computed(
  () =>
    form.value.segmento !== "" &&
    (esCampanaWhatsApp.value
      ? plantillaElegida.value !== undefined
      : form.value.asunto.trim() !== "" && form.value.cuerpo.trim() !== ""),
);
// Lo que se verá en la revisión: el texto escrito o el de la plantilla.
const vistaAsunto = computed(() =>
  esCampanaWhatsApp.value
    ? (plantillaElegida.value?.titulo ?? "")
    : conEjemplo(form.value.asunto),
);
const vistaCuerpo = computed(() =>
  esCampanaWhatsApp.value
    ? (plantillaElegida.value?.cuerpo ?? "")
        .replace(/\{\{\s*persona_nombre\s*\}\}/g, "Ana")
        .replace(/\{\{\s*negocio\s*\}\}/g, sesion.estudio?.nombre ?? "")
    : conEjemplo(form.value.cuerpo),
);
// Por WhatsApp, lo que gasta del saldo (y cuánto cuesta, si la plataforma lo cobra).
const gastoWhatsApp = computed(() => {
  const a = alcance.value;
  if (!esCampanaWhatsApp.value || a?.mensajes === undefined) {
    return null;
  }
  return a.costo_minor === null || a.costo_minor === undefined
    ? t("comunicacionPantalla.whatsapp.gastoSinCosto", {
        mensajes: a.mensajes,
      })
    : t(
        (a.costo_moneda ?? "MXN") === "MXN"
          ? "comunicacionPantalla.whatsapp.gasto"
          : "comunicacionPantalla.whatsapp.gastoSinIva",
        {
          mensajes: a.mensajes,
          costo: dineroEn(a.costo_minor, a.costo_moneda ?? "MXN"),
        },
      );
});

// Ayuda de marcadores: se arma en el script para no meter `{{ }}` en el template
// (Vue lo interpretaría como interpolación anidada). El backend reemplaza estos
// tokens por los datos de cada alumno.
const marcadoresTexto = computed(() =>
  t("comunicaciones.marcadores", {
    a: "{{persona_nombre}}",
    b: "{{persona_email}}",
  }),
);

// El estado de cada mensaje de la bandeja: punto de color y texto.
const TONO_MENSAJE: Record<Mensaje["estado"], string> = {
  encolado: "var(--aviso)",
  enviado: "var(--exito)",
  fallido: "var(--error)",
  descartado: "var(--texto-suave)",
};

// Un WhatsApp enviado dice además si llegó o si ya lo leyeron.
function estadoDeMensaje(m: Mensaje): string {
  if (m.estado === "enviado" && m.leido_en) {
    return t("comunicacionesAuto.estados.leido");
  }
  if (m.estado === "enviado" && m.entregado_en) {
    return t("comunicacionesAuto.estados.entregado");
  }
  return t(`comunicacionesAuto.estados.${m.estado}`);
}

function canalTexto(canal: string): string {
  if (canal === "push") {
    return t("comunicacionesAuto.canalPush");
  }
  if (canal === "whatsapp") {
    return t("comunicacionesAuto.canalWhatsApp");
  }
  return canal === "email"
    ? t("comunicaciones.canalEmail")
    : t("comunicaciones.canalInterno");
}

// Canales que ofrece el servidor: push solo si la plataforma tiene FCM configurado.
const canalesDifusion = ref<Canal[]>(["interno", "email"]);
const canalesAuto = ref<Canal[]>(["interno", "email"]);
// Para filtrar lo enviado: solo los canales que el negocio tiene (WhatsApp, si la
// plataforma lo activó para él).
const canalesDeSalida = computed<Canal[]>(() =>
  (["email", "whatsapp", "push", "interno"] as Canal[]).filter(
    (c) =>
      canalesAuto.value.includes(c) ||
      canalesDifusion.value.includes(c) ||
      (c === "whatsapp" && sesion.estudio?.whatsapp_clientes === true),
  ),
);

// Las pestañas que puede ver, en la dirección (`?pestana=salida`): recargar o
// compartir el enlace abre la misma. WhatsApp, solo si el negocio lo tiene.
const pestanasPermitidas = computed<Pestana[]>(() => [
  "difusion",
  ...(puedeAutomaticos.value ? (["automaticos"] as const) : []),
  ...(puedeAutomaticos.value && canalesDifusion.value.includes("whatsapp")
    ? (["whatsapp"] as const)
    : []),
  ...(puedeSalida.value ? (["salida"] as const) : []),
]);
const pestana = usePestanaEnUrl(pestanasPermitidas);
const opcionesPestana = computed(() =>
  pestanasPermitidas.value.map((clave) => ({
    clave,
    texto: t(`comunicacionPantalla.pestanas.${clave}.titulo`),
  })),
);

// El nombre y la descripción de cada grupo con el término del negocio (el API los
// redacta con «miembro»): por su clave y, si es uno nuevo, el texto del API adaptado.
function textoSegmento(
  clave: string,
  campo: "etiqueta" | "descripcion",
  respaldo: string,
): string {
  const llave = `comunicaciones.segmentos.${clave}.${campo}`;
  return te(llave) ? t(llave) : conTerminosActuales(respaldo);
}

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  // En la zona horaria del negocio (ADR 0099), aunque quien mira esté en otra.
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: sesion.zonaHoraria || undefined,
  }).format(new Date(iso));
}

const ruta = useRoute();
async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  errorCarga.value = false;
  try {
    const [seg] = await Promise.all([
      api.get<{ data: Segmento[]; canales?: Canal[] }>(
        `${base.value}/comunicaciones/segmentos`,
      ),
      cargarDifusiones(1),
    ]);
    segmentos.value = seg.data.data;
    canalesDifusion.value = seg.data.canales ?? ["interno", "email"];
    await cargarPlantillasWhatsApp();
    // `?pestana=whatsapp` en la dirección: esa pestaña existe hasta saber los canales.
    if (
      ruta?.query.pestana === "whatsapp" &&
      pestanasPermitidas.value.includes("whatsapp")
    ) {
      pestana.value = "whatsapp";
    }
    // Desde Renovaciones › Para recuperar: «Escribirles» llega con su grupo elegido.
    const pedido = ruta?.query.segmento;
    if (
      form.value.segmento === "" &&
      typeof pedido === "string" &&
      segmentos.value.some((s) => s.clave === pedido)
    ) {
      form.value.segmento = pedido;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
    errorCarga.value = true;
  } finally {
    cargando.value = false;
  }
}

async function enviar(): Promise<void> {
  if (!puedeEnviar.value) {
    return;
  }
  enviando.value = true;
  errorEnvio.value = null;
  exito.value = null;
  try {
    const { data } = await api.post<{ data: Difusion }>(
      `${base.value}/comunicaciones/difusiones`,
      esCampanaWhatsApp.value
        ? {
            segmento: form.value.segmento,
            canal: form.value.canal,
            plantilla_whatsapp: form.value.plantilla_whatsapp,
          }
        : {
            segmento: form.value.segmento,
            canal: form.value.canal,
            asunto: form.value.asunto,
            cuerpo: form.value.cuerpo,
          },
    );
    exito.value = data.data.total;
    revisando.value = false;
    form.value.asunto = "";
    form.value.cuerpo = "";
    form.value.plantilla_whatsapp = "";
    await cargar();
  } catch (e) {
    errorEnvio.value = mensajeDeError(e);
  } finally {
    enviando.value = false;
  }
}

// ---- Mensajes automáticos (plantillas por evento y canal) ----
const plantillas = ref<Plantilla[]>([]);
const eventos = ref<string[]>([]);
// Por área (reservas, pagos…), para encontrar el aviso sin leer toda la lista.
const eventosAgrupados = computed(() => eventosPorArea(eventos.value));
const editor = ref<{ abierto: boolean; clave: string }>({
  abierto: false,
  clave: "",
});
const borrador = ref<{
  destinatario: Destinatario;
  canal: Canal;
  asunto: string;
  cuerpo: string;
  activo: boolean;
}>({
  destinatario: "persona",
  canal: "interno",
  asunto: "",
  cuerpo: "",
  activo: true,
});

// WhatsApp (si la plataforma lo encendió): el texto fijo de cada aviso que lo admite.
const textosWhatsApp = ref<Record<string, string>>({});
// Al equipo se le avisa por correo o en la app (no tiene bandeja en la app). WhatsApp
// solo al cliente y solo en los avisos con plantilla aprobada.
const canalesDelBorrador = computed(() =>
  canalesAuto.value.filter((c) =>
    c === "whatsapp"
      ? borrador.value.destinatario === "persona" &&
        editor.value.clave in textosWhatsApp.value
      : borrador.value.destinatario === "persona" || c !== "interno",
  ),
);
// Qué eventos admiten avisar al profesional de la cita o al equipo (del servidor).
const eventosPorDestinatario = ref<Record<"profesional" | "equipo", string[]>>({
  profesional: [],
  equipo: [],
});
function destinatariosDe(clave: string): Destinatario[] {
  return [
    "persona",
    ...(["profesional", "equipo"] as const).filter((d) =>
      eventosPorDestinatario.value[d].includes(clave),
    ),
  ];
}
function textoDestinatario(d: Destinatario): string {
  return d === "profesional"
    ? t("comunicacionesAuto.paraProfesional")
    : d === "equipo"
      ? t("comunicacionesAuto.paraEquipo")
      : t("comunicacionesAuto.paraPersona");
}
// Lo configurado de un evento, en orden: al alumno, al profesional y al equipo.
function configuradas(clave: string): Plantilla[] {
  return (["persona", "profesional", "equipo"] as const).flatMap((d) =>
    canalesAuto.value
      .map((c) => plantillaDe(clave, c, d))
      .filter((p): p is Plantilla => p !== undefined),
  );
}
// El patrón común de los formularios: el error dentro del panel y junto a su campo,
// no se cierra mientras guarda y pregunta antes de descartar (también al cambiar de
// canal o de destinatario, que carga otro texto).
const editorAbierto = computed({
  get: () => editor.value.abierto,
  set: (v: boolean) => {
    editor.value.abierto = v;
  },
});
const plantillaForm = useEditorFormulario({
  abierto: editorAbierto,
  valor: () => borrador.value,
  campos: ["asunto", "cuerpo", "canal", "destinatario"],
  formulario: "#form-plantilla",
});
const guardandoPlantilla = plantillaForm.guardando;
const erroresPlantilla = plantillaForm.errores;
const errorPlantilla = plantillaForm.error;
async function seguirSinGuardar(): Promise<boolean> {
  return (
    !plantillaForm.sucio.value ||
    confirmar(t("comun.cambiosSinGuardar"), {
      aceptar: t("comun.salirSinGuardar"),
      peligro: true,
    })
  );
}

// Marcadores de los correos que traen datos legibles (confirmación, recordatorios,
// recibo, bienvenida); el resto usa los datos del evento.
const MARCADORES_SESION = [
  "persona_nombre",
  "actividad",
  "fecha",
  "hora",
  "sucursal",
  "con",
  "negocio",
];
const MARCADORES_POR_EVENTO: Record<string, string[]> = {
  "reserva.confirmada": MARCADORES_SESION,
  "reserva.apartada": [...MARCADORES_SESION, "total", "vence", "enlace"],
  "reserva.recordatorio_24h": MARCADORES_SESION,
  "reserva.recordatorio_2h": MARCADORES_SESION,
  "reserva.cancelada": [...MARCADORES_SESION, "credito"],
  "reserva.sesion_cancelada": [...MARCADORES_SESION, "credito", "enlace"],
  "reserva.reprogramada": [...MARCADORES_SESION, "antes_fecha", "antes_hora"],
  "orden.pagada": [
    "persona_nombre",
    "detalle",
    "total",
    "metodo",
    "fecha",
    "folio",
    "negocio",
  ],
  "cuenta.creada": ["persona_nombre", "enlace", "negocio"],
  "membresia.pausada": ["persona_nombre", "producto", "hasta", "negocio"],
  "membresia.reanudada": ["persona_nombre", "producto", "negocio"],
  "membresia.renovacion_proxima": [
    "persona_nombre",
    "producto",
    "fecha",
    "monto",
    "como_pagar",
    "enlace",
    "negocio",
  ],
  "cobro.fallido": [
    "persona_nombre",
    "producto",
    "motivo",
    "enlace",
    "negocio",
  ],
  "pago_automatico.solicitado": [
    "persona_nombre",
    "producto",
    "enlace",
    "negocio",
  ],
};

const marcadoresAuto = computed(() => {
  const propios = MARCADORES_POR_EVENTO[editor.value.clave];
  return propios
    ? t("comunicacionesAuto.marcadoresLista", {
        lista: propios.map((m) => `{{${m}}}`).join(", "),
      })
    : t("comunicacionesAuto.marcadores", {
        a: "{{persona_nombre}}",
        b: "{{persona_email}}",
        c: "{{estado}}",
        d: "{{negocio}}",
      });
});

function plantillaDe(
  clave: string,
  canal: Canal,
  destinatario: Destinatario = "persona",
): Plantilla | undefined {
  return plantillas.value.find(
    (p) =>
      p.clave === clave &&
      p.canal === canal &&
      (p.destinatario ?? "persona") === destinatario,
  );
}

function cargarBorrador(): void {
  const existente = plantillaDe(
    editor.value.clave,
    borrador.value.canal,
    borrador.value.destinatario,
  );
  borrador.value = {
    destinatario: borrador.value.destinatario,
    canal: borrador.value.canal,
    asunto: existente?.asunto ?? "",
    cuerpo: existente?.cuerpo ?? "",
    activo: existente?.activo ?? true,
  };
  // Lo que se carga es lo guardado: desde aquí cuentan los cambios.
  plantillaForm.empezar();
}

function configurar(clave: string): void {
  editor.value = { abierto: true, clave };
  // Abre en lo que ya esté configurado (p. ej. un aviso que solo va al equipo).
  const primera = configuradas(clave)[0];
  borrador.value.destinatario = primera?.destinatario ?? "persona";
  borrador.value.canal = primera?.canal ?? "interno";
  cargarBorrador();
}

async function elegirCanal(canal: Canal): Promise<void> {
  if (canal === borrador.value.canal || !(await seguirSinGuardar())) {
    return;
  }
  borrador.value.canal = canal;
  cargarBorrador();
}

// Configurado por WhatsApp: su texto no se edita (lo pone el servidor).
const esWhatsApp = computed(() => borrador.value.canal === "whatsapp");

async function elegirDestinatario(destinatario: Destinatario): Promise<void> {
  if (
    destinatario === borrador.value.destinatario ||
    !(await seguirSinGuardar())
  ) {
    return;
  }
  borrador.value.destinatario = destinatario;
  const canales = canalesDelBorrador.value;
  if (!canales.includes(borrador.value.canal)) {
    borrador.value.canal =
      canales.find((c) => plantillaDe(editor.value.clave, c, destinatario)) ??
      canales[0] ??
      "email";
  }
  cargarBorrador();
}

async function cargarAutomaticos(): Promise<void> {
  const { data } = await api.get<{
    data: Plantilla[];
    eventos_disponibles: string[];
    canales?: Canal[];
    destinatarios?: Record<"profesional" | "equipo", string[]>;
    whatsapp?: Record<string, string> | null;
  }>(`${base.value}/plantillas-mensaje`);
  plantillas.value = data.data;
  eventos.value = data.eventos_disponibles;
  canalesAuto.value = data.canales ?? ["interno", "email"];
  textosWhatsApp.value = data.whatsapp ?? {};
  eventosPorDestinatario.value = data.destinatarios ?? {
    profesional: [],
    equipo: [],
  };
}

async function guardarPlantilla(): Promise<void> {
  const listo = await plantillaForm.guardar(
    () =>
      api.put(`${base.value}/plantillas-mensaje`, {
        clave: editor.value.clave,
        ...borrador.value,
      }),
    t("comunicacionesAuto.guardado"),
  );
  if (listo !== undefined) {
    editor.value.abierto = false;
    await cargarAutomaticos();
  }
}

async function eliminarPlantilla(): Promise<void> {
  const existente = plantillaDe(
    editor.value.clave,
    borrador.value.canal,
    borrador.value.destinatario,
  );
  if (
    existente === undefined ||
    !(await confirmar(t("comunicacionesAuto.confirmarEliminar"), {
      peligro: true,
    }))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/plantillas-mensaje/${existente.id}`);
    toast.exito(t("comunicacionesAuto.eliminado"));
    editor.value.abierto = false;
    await cargarAutomaticos();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

// ---- Bandeja de salida (por páginas: el API busca, filtra y cuenta) ----
const ESTADOS_SALIDA = [
  "encolado",
  "enviado",
  "fallido",
  "descartado",
] as const;
interface MetaSalida {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  conteos: Record<string, number>;
}
const mensajes = ref<Mensaje[]>([]);
const metaSalida = ref<MetaSalida | null>(null);
const filtroEstado = ref("");
const filtroCanal = ref("");
const buscarSalida = ref("");
const desdeSalida = ref("");
const hastaSalida = ref("");
const paginaSalida = ref(1);
const cargandoSalida = ref(false);
// No se pudo consultar: no es «aún no se envían mensajes».
const errorSalida = ref(false);
// Los filtros de lo enviado en la dirección: recargar o compartir el enlace conserva
// la búsqueda. Antes de vigilarlos, para no cargar de más.
useFiltrosEnUrl({
  q: buscarSalida,
  estado: opcionEnUrl(filtroEstado, ["", ...ESTADOS_SALIDA]),
  canal: opcionEnUrl(filtroCanal, ["", "email", "whatsapp", "push", "interno"]),
  desde: fechaEnUrl(desdeSalida),
  hasta: fechaEnUrl(hastaSalida),
});

let peticionSalida = 0;
async function cargarSalida(): Promise<void> {
  const mia = ++peticionSalida;
  cargandoSalida.value = true;
  errorSalida.value = false;
  const q = buscarSalida.value.trim();
  try {
    const { data } = await api.get<{ data: Mensaje[]; meta?: MetaSalida }>(
      `${base.value}/mensajes`,
      {
        params: {
          page: paginaSalida.value,
          ...(q !== "" ? { q } : {}),
          ...(filtroEstado.value !== "" ? { estado: filtroEstado.value } : {}),
          ...(filtroCanal.value !== "" ? { canal: filtroCanal.value } : {}),
          ...(desdeSalida.value !== "" ? { desde: desdeSalida.value } : {}),
          ...(hastaSalida.value !== "" ? { hasta: hastaSalida.value } : {}),
        },
      },
    );
    // Solo la última búsqueda: una anterior que llegue tarde no la pisa.
    if (mia === peticionSalida) {
      mensajes.value = data.data;
      metaSalida.value = data.meta ?? null;
    }
  } catch {
    if (mia === peticionSalida) {
      errorSalida.value = true;
    }
  } finally {
    if (mia === peticionSalida) {
      cargandoSalida.value = false;
    }
  }
}
async function irPaginaSalida(n = 1): Promise<void> {
  paginaSalida.value = n;
  try {
    await cargarSalida();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
// La búsqueda espera a que se deje de escribir; lo demás recarga ya.
let esperaSalida: ReturnType<typeof setTimeout> | undefined;
watch(buscarSalida, () => {
  clearTimeout(esperaSalida);
  esperaSalida = setTimeout(() => void irPaginaSalida(1), 300);
});
watch([filtroEstado, filtroCanal, desdeSalida, hastaSalida], () => {
  if (
    desdeSalida.value === "" ||
    hastaSalida.value === "" ||
    hastaSalida.value >= desdeSalida.value
  ) {
    void irPaginaSalida(1);
  }
});
onBeforeUnmount(() => clearTimeout(esperaSalida));
const conFiltrosSalida = computed(
  () =>
    buscarSalida.value.trim() !== "" ||
    filtroEstado.value !== "" ||
    filtroCanal.value !== "" ||
    desdeSalida.value !== "" ||
    hastaSalida.value !== "",
);
function limpiarFiltrosSalida(): void {
  buscarSalida.value = "";
  filtroEstado.value = "";
  filtroCanal.value = "";
  desdeSalida.value = "";
  hastaSalida.value = "";
}

// Los mensajes automáticos: cargando, o no se pudieron consultar (con reintentar).
const cargandoAuto = ref(false);
const errorAuto = ref(false);
async function abrirAutomaticos(): Promise<void> {
  cargandoAuto.value = true;
  errorAuto.value = false;
  try {
    await cargarAutomaticos();
  } catch {
    errorAuto.value = true;
  } finally {
    cargandoAuto.value = false;
  }
}

// Cada pestaña carga lo suyo al elegirla, también si llega en la dirección.
async function cargarPestana(p: Pestana): Promise<void> {
  try {
    if (p === "automaticos") {
      await abrirAutomaticos();
    } else if (p === "difusion") {
      await cargarPlantillasWhatsApp();
    } else if (p === "salida") {
      await cargarSalida();
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
watch(pestana, (p) => void cargarPestana(p), { immediate: true });

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('comunicaciones.titulo')"
      :subtitulo="$t('comunicacionesVisual.subtitulo')"
    />

    <!-- Tres cosas distintas: lo que escribes una vez, lo que se envía solo y lo
         que ya salió. Cada pestaña dice cuál es. -->
    <PestanasSeccion
      v-model="pestana"
      :opciones="opcionesPestana"
      :etiqueta="$t('comunicacionPantalla.etiquetaPestanas')"
    />
    <p
      class="mt-3 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
      data-prueba="ayuda-pestana"
    >
      {{ $t(`comunicacionPantalla.pestanas.${pestana}.ayuda`) }}
    </p>

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
      <template v-if="errorCarga" #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          data-prueba="reintentar-comunicaciones"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <template v-if="pestana === 'difusion'">
      <div
        v-if="exito !== null"
        class="mt-4 tu-card p-4 text-sm"
        :style="{ background: 'var(--exito-suave)', color: 'var(--exito)' }"
      >
        {{ $t("comunicaciones.enviada", { n: exito }) }}
      </div>

      <!-- Redactar difusión -->
      <div class="mt-6 tu-card p-6">
        <h3 class="text-sm font-semibold">
          {{ $t("comunicaciones.segmentoLabel") }}
        </h3>
        <p
          v-if="!cargando && !errorCarga && segmentos.length === 0"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comunicaciones.sinSegmento") }}
        </p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <button
            v-for="s in segmentos"
            :key="s.clave"
            type="button"
            class="text-left rounded-2xl border p-4 transition"
            :aria-pressed="form.segmento === s.clave"
            :style="
              form.segmento === s.clave
                ? {
                    borderColor: 'var(--primario)',
                    background: 'var(--superficie-2)',
                  }
                : { borderColor: 'var(--borde)' }
            "
            @click="form.segmento = s.clave"
          >
            <div class="flex items-center justify-between">
              <span class="font-semibold">{{
                textoSegmento(s.clave, "etiqueta", s.etiqueta)
              }}</span>
              <span class="tu-badge">{{ s.total }}</span>
            </div>
            <span
              class="block text-xs mt-1"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ textoSegmento(s.clave, "descripcion", s.descripcion) }}</span
            >
          </button>
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-2">
          <span id="com-canal-etiqueta" class="text-sm font-semibold">{{
            $t("comunicaciones.canalLabel")
          }}</span>
          <div
            class="tu-segmentado"
            role="group"
            aria-labelledby="com-canal-etiqueta"
          >
            <button
              v-for="canal in canalesDifusion"
              :key="canal"
              type="button"
              :aria-pressed="form.canal === canal"
              @click="form.canal = canal"
            >
              {{ canalTexto(canal) }}
            </button>
          </div>
        </div>

        <!-- Por WhatsApp va una plantilla aprobada del negocio (ADR 0190). -->
        <template v-if="esCampanaWhatsApp">
          <label class="block mt-5 text-sm font-semibold" for="com-plantilla">{{
            $t("comunicacionPantalla.whatsapp.plantilla")
          }}</label>
          <select
            v-if="plantillasWhatsApp.length > 0"
            id="com-plantilla"
            v-model="form.plantilla_whatsapp"
            class="tu-input mt-1 w-full"
            data-prueba="plantilla-campana"
          >
            <option value="" disabled>
              {{ $t("comunicacionPantalla.whatsapp.elegir") }}
            </option>
            <option v-for="p in plantillasWhatsApp" :key="p.id" :value="p.id">
              {{ p.titulo }}
            </option>
          </select>
          <p
            v-else
            class="mt-1 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            data-prueba="sin-plantillas-aprobadas"
          >
            {{ $t("comunicacionPantalla.whatsapp.sinAprobadas") }}
          </p>
          <p
            v-if="plantillaElegida"
            class="mt-2 text-sm whitespace-pre-line"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ vistaCuerpo }}
          </p>
        </template>
        <template v-else>
          <label class="block mt-5 text-sm font-semibold" for="com-asunto">{{
            $t("comunicaciones.asuntoLabel")
          }}</label>
          <input
            id="com-asunto"
            v-model="form.asunto"
            type="text"
            maxlength="255"
            class="tu-input mt-1 w-full"
            :placeholder="$t('comunicaciones.asuntoPh')"
          />

          <label class="block mt-4 text-sm font-semibold" for="com-cuerpo">{{
            $t("comunicaciones.cuerpoLabel")
          }}</label>
          <textarea
            id="com-cuerpo"
            v-model="form.cuerpo"
            rows="4"
            maxlength="5000"
            class="tu-input mt-1 w-full"
            :placeholder="$t('comunicaciones.cuerpoPh')"
          ></textarea>
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ marcadoresTexto }}
          </p>
        </template>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
          <span class="text-sm" :style="{ color: 'var(--texto-suave)' }">{{
            $t("comunicaciones.destinatarios", { n: destinatarios })
          }}</span>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="!puedeEnviar || enviando"
            data-prueba="revisar-difusion"
            @click="abrirRevision"
          >
            {{ $t("operacion.comunicacion.revisar") }}
          </button>
        </div>
      </div>

      <!-- Revisión antes de enviar: destinatarios, canal y vista previa -->
      <ModalDialogo
        :abierto="revisando"
        :titulo="$t('operacion.comunicacion.confirmarTitulo')"
        tam="md"
        :ocupado="enviando"
        @cerrar="revisando = false"
      >
        <p class="font-medium">
          {{
            $t(
              "operacion.comunicacion.destinatarios",
              { n: destinatarios },
              destinatarios,
            )
          }}
        </p>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            segmentoSel
              ? textoSegmento(
                  segmentoSel.clave,
                  "etiqueta",
                  segmentoSel.etiqueta,
                )
              : ""
          }}
          ·
          {{
            $t("operacion.comunicacion.canal", {
              canal: canalTexto(form.canal),
            })
          }}
        </p>
        <!-- Quién queda fuera y por qué: lo que se ve es lo que se manda. -->
        <p
          v-if="excluidos"
          class="mt-1 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="excluidos-difusion"
        >
          {{ $t("operacion.comunicacion.fuera", { motivos: excluidos }) }}
        </p>
        <p class="mt-5 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("operacion.comunicacion.vistaPrevia") }}
        </p>
        <div
          class="mt-1 rounded-xl border p-4"
          :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
        >
          <p class="font-semibold">{{ vistaAsunto }}</p>
          <p class="mt-2 whitespace-pre-line text-sm">
            {{ vistaCuerpo }}
          </p>
        </div>
        <p
          v-if="gastoWhatsApp"
          class="mt-3 text-sm"
          data-prueba="gasto-whatsapp"
        >
          {{ gastoWhatsApp }}
        </p>
        <AvisoTono v-if="destinatarios === 0" tono="aviso" class="mt-4">
          {{ $t("operacion.comunicacion.sinDestinatarios") }}
        </AvisoTono>
        <AvisoTono
          v-if="errorEnvio"
          tono="peligro"
          class="mt-4"
          role="alert"
          data-prueba="error-envio"
        >
          {{ errorEnvio }}
        </AvisoTono>
        <template #pie>
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="enviando"
            @click="revisando = false"
          >
            {{ $t("operacion.comunicacion.cancelar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="enviando || destinatarios === 0"
            :aria-busy="enviando || undefined"
            data-prueba="enviar-difusion"
            @click="enviar"
          >
            {{
              enviando
                ? $t("comunicaciones.enviando")
                : $t("operacion.comunicacion.enviar")
            }}
          </button>
        </template>
      </ModalDialogo>

      <!-- Historial: en el teléfono, una ficha por envío con sus controles a la vista -->
      <h2 class="tu-titulo-seccion mt-8">
        {{ $t("comunicaciones.historial") }}
      </h2>
      <EstadoVacio
        v-if="!cargando && !errorCarga && difusiones.length === 0"
        class="tu-card mt-3"
        icono="mensaje"
        :titulo="$t('comunicaciones.historialVacio')"
      />
      <div
        v-else-if="difusiones.length > 0"
        class="relative mt-3 tu-card overflow-x-auto tu-tabla-adaptable"
      >
        <table class="tu-tabla">
          <thead>
            <tr>
              <th>{{ $t("comunicaciones.colAsunto") }}</th>
              <th class="text-right">
                {{ $t("comunicaciones.colTotal") }}
              </th>
              <th>{{ $t("comunicaciones.colSegmento") }}</th>
              <th>{{ $t("comunicaciones.colEstado") }}</th>
              <th v-if="puedeControlar" class="text-right">
                {{ $t("comunicaciones.colAcciones") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in difusiones" :key="d.id">
              <td :data-etiqueta="$t('comunicaciones.colAsunto')">
                {{ d.asunto }}
                <span class="tu-sub font-normal whitespace-nowrap">{{
                  fecha(d.enviada_en)
                }}</span>
              </td>
              <td
                class="text-right font-semibold"
                :data-etiqueta="$t('comunicaciones.colTotal')"
              >
                {{ d.total }}
              </td>
              <td :data-etiqueta="$t('comunicaciones.colSegmento')">
                <span>
                  <span class="tu-badge">{{
                    textoSegmento(d.segmento, "etiqueta", d.segmento_etiqueta)
                  }}</span>
                  <span
                    class="block text-xs mt-1"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ canalTexto(d.canal) }}</span
                  >
                </span>
              </td>
              <td
                :data-etiqueta="$t('comunicaciones.colEstado')"
                data-prueba="estado-difusion"
              >
                <span>
                  <span
                    class="tu-estado text-sm"
                    :style="{
                      '--tono':
                        d.estado === 'pausada'
                          ? 'var(--aviso)'
                          : d.estado === 'cancelada'
                            ? 'var(--texto-suave)'
                            : (d.pendientes ?? 0) > 0
                              ? 'var(--primario)'
                              : 'var(--exito)',
                    }"
                  >
                    {{
                      d.estado === "pausada"
                        ? $t("comunicaciones.estados.pausada")
                        : d.estado === "cancelada"
                          ? $t("comunicaciones.estados.cancelada")
                          : (d.pendientes ?? 0) > 0
                            ? $t("comunicaciones.estados.enviando", {
                                n: d.pendientes ?? 0,
                              })
                            : $t("comunicaciones.estados.enviada")
                    }}
                  </span>
                  <!-- El resultado del envío: llegó, no se pudo, no se mandó. -->
                  <span
                    v-if="
                      (d.enviados ?? 0) +
                        (d.fallidos ?? 0) +
                        (d.descartados ?? 0) >
                      0
                    "
                    class="block text-xs mt-1"
                    :style="{ color: 'var(--texto-suave)' }"
                    data-prueba="resultado-difusion"
                    >{{
                      [
                        $t("comunicaciones.resultado.enviados", {
                          n: d.enviados ?? 0,
                        }),
                        (d.fallidos ?? 0) > 0
                          ? $t("comunicaciones.resultado.fallidos", {
                              n: d.fallidos ?? 0,
                            })
                          : null,
                        (d.descartados ?? 0) > 0
                          ? $t("comunicaciones.resultado.descartados", {
                              n: d.descartados ?? 0,
                            })
                          : null,
                      ]
                        .filter(Boolean)
                        .join(" · ")
                    }}</span
                  >
                </span>
              </td>
              <!-- Un envío en curso se pausa, se reanuda o se cancela lo que falta. -->
              <td
                v-if="puedeControlar"
                class="tu-celda-acciones"
                data-prueba="acciones-difusion"
              >
                <div
                  v-if="d.estado !== 'cancelada' && (d.pendientes ?? 0) > 0"
                  class="inline-flex flex-wrap gap-2"
                >
                  <button
                    v-if="d.estado !== 'pausada'"
                    type="button"
                    class="tu-btn tu-btn-secundario tu-btn-chico"
                    :disabled="controlando === d.id"
                    @click="controlar(d, 'pausar')"
                  >
                    {{ $t("comunicaciones.pausar") }}
                  </button>
                  <button
                    v-else
                    type="button"
                    class="tu-btn tu-btn-secundario tu-btn-chico"
                    :disabled="controlando === d.id"
                    @click="controlar(d, 'reanudar')"
                  >
                    {{ $t("comunicaciones.reanudar") }}
                  </button>
                  <button
                    type="button"
                    class="tu-btn tu-btn-peligro tu-btn-chico"
                    :disabled="controlando === d.id"
                    @click="controlar(d, 'cancelar')"
                  >
                    {{ $t("comunicaciones.cancelar") }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginacionListado
        v-if="metaDifusiones && metaDifusiones.ultima_pagina > 1"
        :page="metaDifusiones.page"
        :ultima-pagina="metaDifusiones.ultima_pagina"
        :total="metaDifusiones.total"
        :per-page="metaDifusiones.per_page"
        @ir="irPaginaDifusiones"
      />
    </template>

    <!-- Plantillas de WhatsApp del negocio (ADR 0190) -->
    <PlantillasWhatsAppNegocio
      v-if="pestana === 'whatsapp'"
      @cambio="cargarPlantillasWhatsApp"
    />

    <!-- Mensajes automáticos -->
    <div v-if="pestana === 'automaticos'" class="mt-5 tu-card p-5">
      <p
        v-if="cargandoAuto && eventos.length === 0"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
        aria-busy="true"
      >
        {{ $t("operacion.listados.cargando") }}
      </p>
      <p
        v-else-if="errorAuto"
        class="text-sm"
        role="alert"
        data-prueba="error-automaticos"
      >
        <span style="color: var(--error)">{{
          $t("comunicacionPantalla.errorAutomaticos")
        }}</span>
        <button
          type="button"
          class="tu-btn tu-btn-discreto tu-btn-chico ml-2"
          :disabled="cargandoAuto"
          @click="abrirAutomaticos"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </p>
      <template v-else>
        <section
          v-for="g in eventosAgrupados"
          :key="g.area"
          class="com-area"
          :data-prueba="`area-${g.area}`"
        >
          <h3 class="com-area-titulo">
            {{ $t(`comunicacionPantalla.areas.${g.area}`) }}
          </h3>
          <ul>
            <li
              v-for="ev in g.eventos"
              :key="ev"
              class="com-fila text-sm"
              :data-prueba="`evento-${ev}`"
            >
              <div class="min-w-0">
                <p class="font-medium" data-prueba="nombre-evento">
                  {{ nombreEvento(ev) }}
                </p>
                <!-- Lo configurado: punto verde si está encendido. -->
                <p
                  class="mt-0.5 flex flex-wrap gap-x-3 gap-y-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  <span
                    v-for="p in configuradas(ev)"
                    :key="p.id"
                    class="tu-estado"
                    :style="{
                      '--tono': p.activo
                        ? 'var(--exito)'
                        : 'var(--texto-suave)',
                    }"
                    >{{
                      p.destinatario === "profesional"
                        ? `${$t("comunicacionesAuto.alProfesional")} · ${canalTexto(p.canal)}`
                        : p.destinatario === "equipo"
                          ? `${$t("comunicacionesAuto.alEquipo")} · ${canalTexto(p.canal)}`
                          : canalTexto(p.canal)
                    }}{{
                      p.activo ? "" : ` · ${$t("comunicacionPantalla.apagado")}`
                    }}</span
                  >
                  <span v-if="configuradas(ev).length === 0">{{
                    $t("comunicacionesAuto.sinConfigurar")
                  }}</span>
                </p>
              </div>
              <button
                type="button"
                class="tu-btn tu-btn-secundario tu-btn-chico shrink-0"
                @click="configurar(ev)"
              >
                {{ $t("comunicacionesAuto.configurar") }}
              </button>
            </li>
          </ul>
        </section>
        <p
          v-if="puedeReglasTareas"
          class="mt-5 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="reglas-tareas"
        >
          {{ $t("comunicacionPantalla.reglasTareas") }}
          <RouterLink :to="{ name: 'tareas' }" class="tu-enlace ml-1">{{
            $t("comunicacionPantalla.irATareas")
          }}</RouterLink>
        </p>
      </template>
    </div>

    <!-- Bandeja de salida: por páginas, con búsqueda, estado, canal y fechas -->
    <div v-if="pestana === 'salida'" class="mt-5 tu-card overflow-hidden">
      <div class="tu-filtros">
        <label class="tu-buscar">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="buscarSalida"
            type="search"
            class="tu-input"
            :placeholder="$t('operacion.salida.buscar')"
            :aria-label="$t('operacion.salida.buscar')"
            data-prueba="buscar-salida"
          />
        </label>
        <div
          class="tu-segmentado"
          role="group"
          :aria-label="$t('operacion.salida.estado')"
        >
          <button
            v-for="e in ['', ...ESTADOS_SALIDA]"
            :key="e"
            type="button"
            :aria-pressed="filtroEstado === e"
            :data-prueba="`salida-estado-${e || 'todos'}`"
            @click="filtroEstado = e"
          >
            {{
              e === ""
                ? $t("operacion.salida.todos")
                : $t("operacion.salida.conCuenta", {
                    estado: $t(`comunicacionesAuto.estados.${e}`),
                    n: metaSalida?.conteos[e] ?? 0,
                  })
            }}
          </button>
        </div>
        <select
          v-model="filtroCanal"
          class="tu-input com-canal"
          :aria-label="$t('operacion.salida.canal')"
          data-prueba="salida-canal"
        >
          <option value="">{{ $t("operacion.salida.todosLosCanales") }}</option>
          <option v-for="c in canalesDeSalida" :key="c" :value="c">
            {{ canalTexto(c) }}
          </option>
        </select>
        <RangoFechas
          v-model:desde="desdeSalida"
          v-model:hasta="hastaSalida"
          prueba="salida"
          atajos
          :zona="sesion.zonaHoraria"
        />
        <button
          v-if="conFiltrosSalida"
          type="button"
          class="tu-btn tu-btn-discreto"
          @click="limpiarFiltrosSalida"
        >
          {{ $t("operacion.listados.quitarFiltros") }}
        </button>
      </div>
      <p
        v-if="errorSalida && !cargandoSalida"
        class="px-5 pb-5 text-sm"
        role="alert"
        data-prueba="error-salida"
      >
        <span style="color: var(--error)">{{
          $t("comunicacionesAuto.errorSalida")
        }}</span>
        <button
          type="button"
          class="tu-btn tu-btn-discreto tu-btn-chico ml-2"
          @click="irPaginaSalida(paginaSalida)"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </p>
      <p
        v-else-if="mensajes.length === 0"
        class="px-5 pb-5 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
        :aria-busy="cargandoSalida"
      >
        {{
          cargandoSalida
            ? $t("operacion.listados.cargando")
            : conFiltrosSalida
              ? $t("operacion.listados.sinResultados")
              : $t("comunicacionesAuto.salidaVacia")
        }}
      </p>
      <ul
        v-else
        class="px-5"
        :class="{ 'com-recargando': cargandoSalida }"
        :aria-busy="cargandoSalida"
      >
        <li v-for="m in mensajes" :key="m.id" class="com-fila text-sm">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ m.asunto }}</p>
            <p
              class="mt-0.5 text-xs truncate"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ m.persona ?? m.destinatario ?? "—" }} ·
              {{ canalTexto(m.canal) }} ·
              {{ fecha(m.enviado_en ?? m.creado_en ?? null) }}
            </p>
            <p
              v-if="m.estado === 'fallido' && m.ultimo_error"
              class="mt-0.5 text-xs truncate"
              style="color: var(--error)"
              :title="m.ultimo_error"
              data-prueba="error-mensaje"
            >
              {{ m.ultimo_error }}
            </p>
          </div>
          <span
            class="tu-estado shrink-0"
            :style="{ '--tono': TONO_MENSAJE[m.estado] }"
            data-prueba="estado-mensaje"
            >{{ estadoDeMensaje(m) }}</span
          >
        </li>
      </ul>
      <PaginacionListado
        v-if="metaSalida && metaSalida.ultima_pagina > 1"
        :page="metaSalida.page"
        :ultima-pagina="metaSalida.ultima_pagina"
        :total="metaSalida.total"
        :per-page="metaSalida.per_page"
        @ir="irPaginaSalida"
      />
    </div>

    <PanelLateral
      :abierto="editor.abierto"
      :titulo="
        editor.clave
          ? nombreEvento(editor.clave)
          : $t('comunicacionesAuto.editor')
      "
      :ocupado="guardandoPlantilla"
      @cerrar="plantillaForm.cerrar"
    >
      <form
        id="form-plantilla"
        class="space-y-4 p-5"
        @submit.prevent="guardarPlantilla"
      >
        <AvisoTono v-if="errorPlantilla" tono="peligro" role="alert">
          {{ errorPlantilla }}
        </AvisoTono>
        <div>
          <span class="tu-label">{{ $t("comunicacionesAuto.para") }}</span>
          <div class="tu-segmentado" role="group">
            <button
              v-for="d in destinatariosDe(editor.clave)"
              :key="d"
              type="button"
              :aria-pressed="borrador.destinatario === d"
              @click="elegirDestinatario(d)"
            >
              {{ textoDestinatario(d) }}
            </button>
          </div>
          <p
            v-if="borrador.destinatario !== 'persona'"
            class="mt-1 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              borrador.destinatario === "equipo"
                ? $t("comunicacionesAuto.ayudaEquipo")
                : $t("comunicacionesAuto.ayudaProfesional")
            }}
          </p>
        </div>
        <div>
          <span class="tu-label">{{ $t("comunicacionesAuto.canal") }}</span>
          <div class="tu-segmentado" role="group">
            <button
              v-for="canal in canalesDelBorrador"
              :key="canal"
              type="button"
              :aria-pressed="borrador.canal === canal"
              @click="elegirCanal(canal)"
            >
              {{ canalTexto(canal) }}
            </button>
          </div>
        </div>
        <div v-if="esWhatsApp" data-prueba="texto-whatsapp">
          <span class="tu-label">{{
            $t("comunicacionesAuto.textoWhatsApp")
          }}</span>
          <p class="com-fijo text-sm">
            {{ textosWhatsApp[editor.clave] }}
          </p>
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("comunicacionesAuto.ayudaWhatsApp") }}
          </p>
        </div>
        <div v-if="!esWhatsApp">
          <label class="tu-label" for="pl-asunto">{{
            borrador.canal === "push"
              ? $t("comunicacionesAuto.asuntoPush")
              : $t("comunicacionesAuto.asunto")
          }}</label>
          <input
            id="pl-asunto"
            v-bind="plantillaForm.marcar('asunto', 'pl-asunto-error')"
            v-model="borrador.asunto"
            class="tu-input"
            maxlength="255"
            required
          />
          <span
            v-if="erroresPlantilla.asunto"
            id="pl-asunto-error"
            class="tu-error-campo"
            >{{ erroresPlantilla.asunto }}</span
          >
        </div>
        <div v-if="!esWhatsApp">
          <label class="tu-label" for="pl-cuerpo">{{
            $t("comunicacionesAuto.cuerpo")
          }}</label>
          <textarea
            id="pl-cuerpo"
            v-bind="plantillaForm.marcar('cuerpo', 'pl-cuerpo-error')"
            v-model="borrador.cuerpo"
            class="tu-input"
            rows="6"
            maxlength="5000"
            required
          />
          <span
            v-if="erroresPlantilla.cuerpo"
            id="pl-cuerpo-error"
            class="tu-error-campo"
            >{{ erroresPlantilla.cuerpo }}</span
          >
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ marcadoresAuto }}
          </p>
          <p
            v-if="borrador.canal === 'push'"
            class="mt-1 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("comunicacionesAuto.ayudaPush") }}
          </p>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="borrador.activo" type="checkbox" />
          {{ $t("comunicacionesAuto.activo") }}
        </label>
      </form>
      <template #pie>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <button
            v-if="
              puedeEliminar &&
              plantillaDe(editor.clave, borrador.canal, borrador.destinatario)
            "
            type="button"
            class="tu-btn tu-btn-peligro mr-auto"
            @click="eliminarPlantilla"
          >
            {{ $t("comunicacionesAuto.eliminar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="guardandoPlantilla"
            @click="plantillaForm.cerrar"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="submit"
            form="form-plantilla"
            class="tu-btn tu-btn-primario"
            :disabled="guardandoPlantilla"
            :aria-busy="guardandoPlantilla || undefined"
          >
            {{ $t("comunicacionesAuto.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>

<style scoped>
.com-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.com-fila:first-child {
  border-top: 0;
}
.com-area + .com-area {
  margin-top: 1.25rem;
}
.com-area-titulo {
  padding-bottom: 0.25rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-weight: 600;
}
.com-canal {
  width: auto;
  min-height: 2.5rem;
  padding-block: 0.35rem;
  font-size: 0.85rem;
}
.com-recargando {
  opacity: 0.55;
  transition: opacity 0.15s;
}
.com-fijo {
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 10px;
  white-space: pre-line;
}
</style>
