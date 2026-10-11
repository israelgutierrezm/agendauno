import { computed, reactive, ref } from "vue";

import { api } from "@/lib/api";
import { fijarParticipante } from "@/lib/participanteEnUso";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Las personas a cargo de la cuenta y por cuál actúa ahora (familias, ADR 0123). Al
 * elegir a alguien, la cuenta del alumno (reservas, planes, pagos y documentos) habla
 * de esa persona: el cliente HTTP manda `X-Participante` y el servidor decide si su
 * autorización está vigente. La elección se recuerda en la pestaña (sessionStorage).
 * Un solo estado para todo el portal, como `miCuenta`.
 */
export interface Participante {
  id: string;
  persona: {
    id: string;
    nombre: string;
    tiene_cuenta: boolean;
    fecha_nacimiento: string | null;
  };
  parentesco: string;
  estado: "pendiente" | "activa" | "retirada";
  autorizacion: {
    version: number | null;
    aceptada_en: string | null;
    via: string | null;
  } | null;
}

const LLAVE = "agendauno:participante";

function leerGuardado(slug: string | null): string | null {
  try {
    const valor = sessionStorage.getItem(`${LLAVE}:${slug ?? ""}`);
    return valor !== null && valor !== "" ? valor : null;
  } catch {
    return null;
  }
}

function guardar(slug: string | null, ulid: string | null): void {
  try {
    if (ulid === null) {
      sessionStorage.removeItem(`${LLAVE}:${slug ?? ""}`);
    } else {
      sessionStorage.setItem(`${LLAVE}:${slug ?? ""}`, ulid);
    }
  } catch {
    // Sin almacenamiento (ventana privada): la elección vale mientras no recargue.
  }
}

const lista = ref<Participante[]>([]);
const activo = ref(false);
const version = ref(1);
const texto = ref("");
const cargado = ref(false);
// La persona por la que actúa la cuenta (null = la cuenta misma).
const actual = ref<string | null>(null);

const autorizados = computed(() =>
  lista.value.filter((p) => p.estado === "activa"),
);
const pendientes = computed(() =>
  lista.value.filter((p) => p.estado === "pendiente"),
);
const nombreActual = computed(
  () =>
    autorizados.value.find((p) => p.persona.id === actual.value)?.persona
      .nombre ?? null,
);

function slug(): string | null {
  return useSesionTenantStore().slug;
}

function elegir(personaId: string | null): void {
  actual.value = personaId;
  fijarParticipante(personaId);
  guardar(slug(), personaId);
}

/** Lee las personas a su cargo; si la elegida ya no está autorizada, vuelve a la suya. */
async function cargar(): Promise<void> {
  const { data } = await api.get<{
    data: Participante[];
    meta: { activo: boolean; version?: number; texto?: string };
  }>(`/api/v1/app/${slug()}/mi/participantes`);
  lista.value = data.data;
  activo.value = data.meta.activo;
  version.value = data.meta.version ?? 1;
  texto.value = data.meta.texto ?? "";
  cargado.value = true;
  const deseado = actual.value ?? leerGuardado(slug());
  elegir(
    deseado !== null && autorizados.value.some((p) => p.persona.id === deseado)
      ? deseado
      : null,
  );
}

async function autorizar(tutela: string): Promise<void> {
  await api.post(`/api/v1/app/${slug()}/mi/participantes/${tutela}/autorizar`, {
    acepto: true,
    version: version.value,
  });
  await cargar();
}

async function retirar(tutela: string): Promise<void> {
  const quien = lista.value.find((p) => p.id === tutela);
  await api.post(
    `/api/v1/app/${slug()}/mi/participantes/${tutela}/retirar`,
    {},
  );
  if (quien !== undefined && quien.persona.id === actual.value) {
    elegir(null);
  }
  await cargar();
}

/** Al salir o cambiar de cuenta: nada de la anterior. */
function reiniciar(): void {
  lista.value = [];
  activo.value = false;
  cargado.value = false;
  actual.value = null;
  fijarParticipante(null);
}

// El servidor ya no la reconoce (retirada o familias apagadas): de vuelta a la suya.
if (typeof window !== "undefined") {
  window.addEventListener("agendauno:participante-invalido", () => {
    actual.value = null;
    guardar(slug(), null);
    // Si ya ni siquiera puede ver la lista (módulo apagado), no queda nada a la vista.
    void cargar().catch(() => {
      lista.value = [];
    });
  });
}

const participantes = reactive({
  lista,
  activo,
  version,
  texto,
  cargado,
  actual,
  autorizados,
  pendientes,
  nombreActual,
  elegir,
  cargar,
  autorizar,
  retirar,
  reiniciar,
});

export function useParticipantes(): typeof participantes {
  return participantes;
}
