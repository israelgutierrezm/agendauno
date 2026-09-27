<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRouter } from "vue-router";

import CargadorLogo from "@/components/CargadorLogo.vue";
import IconoNav from "@/components/IconoNav.vue";
import { api, mensajeDeError } from "@/lib/api";
import { trackEvent } from "@/lib/analytics";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Asistente de configuración: pasos numerados en horizontal, el avance arriba y un
 * pie común (omitir, anterior, guardar y continuar). Cada paso guarda lo suyo.
 */
const { t } = useI18n();
const router = useRouter();
const sesion = useSesionTenantStore();

// Ícono de cada paso (el de la tarjeta).
const ICONOS: Record<string, string> = {
  marca: "mi-cuenta",
  sucursal: "ubicacion",
  actividades: "agenda",
  horarios: "reloj",
  productos: "etiqueta",
  politicas: "documentos",
  pasarela: "pasarelas",
  personal: "instructores",
  publicacion: "contenido",
};

const ZONAS = [
  "America/Mexico_City",
  "America/Tijuana",
  "America/Monterrey",
  "America/Cancun",
  "America/Bogota",
  "America/Lima",
  "America/Santiago",
  "America/Argentina/Buenos_Aires",
];

const pasos = ref<string[]>([]);
const completados = ref<Set<string>>(new Set());
const completo = ref(false);
const indice = ref(0);
const cargando = ref(true);
const guardando = ref(false);
const error = ref<string | null>(null);
const mensaje = ref<string | null>(null);

const pasoActual = computed(() => pasos.value[indice.value] ?? "");
const base = computed(() => `/api/v1/app/${sesion.slug}`);

// Modelos por paso.
const logoUrl = ref<string | null>(sesion.estudio?.logo_url ?? null);
const suc = ref({ nombre: "", zona: "America/Mexico_City" });
const act = ref({
  programa: "",
  actividad: "",
  oferta: "",
  modalidad: "grupal",
  capacidad: "",
});
const prod = ref({ nombre: "", tipo: "paquete", precio: "899", creditos: "8" });
const per = ref({ nombre: "", email: "", rol: "recepcionista" });
const pub = ref({ publicado: true, privado: false });
const invitacion = ref<{ email: string; token: string } | null>(null);
const configReal = ref<{ horarios: boolean; politicas: boolean }>({
  horarios: false,
  politicas: false,
});
const politica = ref({ horas: "6", penalizaTarde: true, penalizaNoShow: true });

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: {
        pasos: string[];
        completados: string[];
        completo: boolean;
        config?: { horarios: boolean; politicas: boolean };
      };
    }>(`${base.value}/onboarding`);
    pasos.value = data.data.pasos;
    completados.value = new Set(data.data.completados);
    completo.value = data.data.completo;
    configReal.value = data.data.config ?? {
      horarios: false,
      politicas: false,
    };
    // Empieza en el primer paso no completado.
    const pendiente = pasos.value.findIndex((p) => !completados.value.has(p));
    indice.value = pendiente === -1 ? pasos.value.length - 1 : pendiente;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function marcar(
  paso: string,
  datos: Record<string, unknown> | null = null,
): Promise<void> {
  const { data } = await api.put<{
    data: { completados: string[]; completo: boolean };
  }>(`${base.value}/onboarding`, { paso, datos });
  completados.value = new Set(data.data.completados);
  completo.value = data.data.completo;
  trackEvent("onboarding_step_completed", {
    step: paso,
    onboarding_complete: data.data.completo,
  });
  if (data.data.completo) {
    trackEvent("onboarding_completed");
  }
}

function avanzar(): void {
  mensaje.value = null;
  if (indice.value < pasos.value.length - 1) {
    indice.value += 1;
  }
}
function retroceder(): void {
  mensaje.value = null;
  if (indice.value > 0) {
    indice.value -= 1;
  }
}
function irA(i: number): void {
  mensaje.value = null;
  indice.value = i;
}

/** Ejecuta la accion de un paso, lo marca y avanza; muestra errores sin romper. */
async function ejecutar(
  paso: string,
  accion: () => Promise<Record<string, unknown> | null>,
): Promise<void> {
  guardando.value = true;
  error.value = null;
  try {
    const datos = await accion();
    await marcar(paso, datos);
    if (paso !== "publicacion") {
      avanzar();
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

const guardarMarca = () =>
  ejecutar("marca", async () => ({ logo_url: logoUrl.value || null }));

const crearSucursal = () =>
  ejecutar("sucursal", async () => {
    const org = await api.post<{ data: { id: string } }>(
      `${base.value}/organizaciones`,
      {
        nombre: "Principal",
      },
    );
    const { data } = await api.post<{ data: { id: string; nombre: string } }>(
      `${base.value}/organizaciones/${org.data.data.id}/sucursales`,
      { nombre: suc.value.nombre, zona_horaria: suc.value.zona },
    );
    mensaje.value = "sucursal.creada:" + data.data.nombre;
    return { sucursal: data.data.id };
  });

const crearActividad = () =>
  ejecutar("actividades", async () => {
    const programa = await api.post<{ data: { id: string } }>(
      `${base.value}/programas`,
      {
        nombre: act.value.programa,
      },
    );
    const actividad = await api.post<{ data: { id: string } }>(
      `${base.value}/programas/${programa.data.data.id}/actividades`,
      { nombre: act.value.actividad },
    );
    const oferta = await api.post<{ data: { id: string; nombre: string } }>(
      `${base.value}/actividades/${actividad.data.data.id}/ofertas`,
      {
        nombre: act.value.oferta,
        modalidad: act.value.modalidad,
        capacidad:
          act.value.capacidad !== "" ? Number(act.value.capacidad) : null,
      },
    );
    mensaje.value = "actividades.creada:" + oferta.data.data.nombre;
    return { oferta: oferta.data.data.id };
  });

const crearProducto = () =>
  ejecutar("productos", async () => {
    const esPaquete = prod.value.tipo === "paquete";
    const { data } = await api.post<{ data: { id: string; nombre: string } }>(
      `${base.value}/productos`,
      {
        nombre: prod.value.nombre,
        tipo: esPaquete ? "paquete" : "membresia",
        precio_minor: Math.round(Number(prod.value.precio) * 100),
        moneda: "MXN",
        ilimitado: !esPaquete,
        creditos_incluidos: esPaquete
          ? Math.round(Number(prod.value.creditos) * 1000)
          : null,
      },
    );
    mensaje.value = "productos.creado:" + data.data.nombre;
    return { producto: data.data.id };
  });

const invitarPersonal = () =>
  ejecutar("personal", async () => {
    const { data } = await api.post<{
      data: { activacion: { email: string; token: string } };
    }>(`${base.value}/usuarios/invitar`, {
      nombre: per.value.nombre,
      email: per.value.email,
      rol: per.value.rol,
    });
    invitacion.value = data.data.activacion;
    return { invitado: per.value.email };
  });

const publicar = () =>
  ejecutar("publicacion", async () => {
    await api.put(`${base.value}/publicacion`, {
      publicado: pub.value.publicado,
      privado: pub.value.privado,
    });
    trackEvent("studio_publication_updated", {
      published: pub.value.publicado,
      private: pub.value.privado,
    });
    return { publicado: pub.value.publicado, privado: pub.value.privado };
  });

function continuarSimple(): void {
  void ejecutar(pasoActual.value, async () => null);
}

// Política de cancelación: config mínima inline (sin salir del asistente).
const guardarPolitica = () =>
  ejecutar("politicas", async () => {
    await api.put(`${base.value}/politicas-cancelacion`, {
      horas_limite: Number(politica.value.horas) || 0,
      penaliza_tarde: politica.value.penalizaTarde,
      penaliza_no_show: politica.value.penalizaNoShow,
    });
    configReal.value.politicas = true;
    return null;
  });

// Horarios: se crean en la Agenda; se abre la pantalla y al volver el paso continúa.
// En citas, "horarios" es cuándo atiende cada profesional; en clases, la agenda.
function irAgenda(): void {
  void router.push({ name: sesion.esCitas ? "horarios" : "agenda" });
}

// Avance: lo completado del total de pasos.
const porcentaje = computed(() =>
  pasos.value.length === 0
    ? 0
    : Math.round((completados.value.size / pasos.value.length) * 100),
);

/**
 * Botón principal del pie según el paso: guarda lo capturado y avanza; en pasos
 * sin captura (o ya resueltos), solo continúa.
 */
const accion = computed<{
  texto: string;
  deshabilitado: boolean;
  ejecutar: () => void;
}>(() => {
  const g = guardando.value;
  const guardarYContinuar = t("onboarding.siguiente");
  switch (pasoActual.value) {
    case "marca":
      return {
        texto: guardarYContinuar,
        deshabilitado: g,
        ejecutar: () => void guardarMarca(),
      };
    case "sucursal":
      return completados.value.has("sucursal")
        ? {
            texto: t("asistente.continuar"),
            deshabilitado: g,
            ejecutar: avanzar,
          }
        : {
            texto: guardarYContinuar,
            deshabilitado: g || suc.value.nombre.trim() === "",
            ejecutar: () => void crearSucursal(),
          };
    case "actividades":
      return {
        texto: guardarYContinuar,
        deshabilitado:
          g ||
          act.value.programa.trim() === "" ||
          act.value.actividad.trim() === "" ||
          act.value.oferta.trim() === "",
        ejecutar: () => void crearActividad(),
      };
    case "productos":
      return {
        texto: guardarYContinuar,
        deshabilitado: g || prod.value.nombre.trim() === "",
        ejecutar: () => void crearProducto(),
      };
    case "personal":
      return {
        texto: t("asistente.invitarYContinuar"),
        deshabilitado:
          g || per.value.nombre.trim() === "" || per.value.email.trim() === "",
        ejecutar: () => void invitarPersonal(),
      };
    case "publicacion":
      return {
        texto: t("asistente.guardar"),
        deshabilitado: g,
        ejecutar: () => void publicar(),
      };
    case "horarios":
      return {
        texto: configReal.value.horarios
          ? t("asistente.continuar")
          : t("onboarding.horarios.yaListo"),
        deshabilitado: g,
        ejecutar: continuarSimple,
      };
    case "politicas":
      return configReal.value.politicas
        ? {
            texto: t("asistente.continuar"),
            deshabilitado: g,
            ejecutar: continuarSimple,
          }
        : {
            texto: guardarYContinuar,
            deshabilitado: g,
            ejecutar: () => void guardarPolitica(),
          };
    default:
      return {
        texto: t("asistente.continuar"),
        deshabilitado: g,
        ejecutar: continuarSimple,
      };
  }
});

const mensajeTexto = computed(() => {
  if (mensaje.value === null) {
    return null;
  }
  const [clave, valor] = mensaje.value.split(/:(.*)/s);
  return { clave, valor };
});

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 py-8 sm:py-10">
    <!-- Encabezado y avance -->
    <div class="flex flex-wrap items-end justify-between gap-6">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold sm:text-3xl">
          {{ $t("onboarding.titulo") }}
        </h1>
        <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("onboarding.subtitulo") }}
        </p>
      </div>
      <div v-if="!cargando" class="ob-avance">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("asistente.paso", { n: indice + 1, total: pasos.length }) }}
        </p>
        <div class="mt-2 flex items-center gap-3">
          <div
            class="ob-barra"
            role="progressbar"
            :aria-valuenow="porcentaje"
            aria-valuemin="0"
            aria-valuemax="100"
          >
            <span :style="{ width: `${porcentaje}%` }" />
          </div>
          <span
            class="text-sm tabular-nums"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ porcentaje }}%</span
          >
        </div>
      </div>
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <!-- Pasos numerados -->
      <ol class="ob-pasos" :aria-label="$t('onboarding.titulo')">
        <li
          v-for="(p, i) in pasos"
          :key="p"
          class="ob-paso"
          :class="{
            'ob-actual': i === indice,
            'ob-hecho': completados.has(p) && i !== indice,
          }"
        >
          <button
            type="button"
            class="ob-paso-boton"
            :aria-current="i === indice ? 'step' : undefined"
            @click="irA(i)"
          >
            <span class="ob-circulo">
              <IconoNav
                v-if="completados.has(p) && i !== indice"
                nombre="hecho"
                :tam="16"
              />
              <template v-else>{{ i + 1 }}</template>
            </span>
            <span class="ob-etiqueta">{{ $t(`onboarding.pasos.${p}`) }}</span>
          </button>
        </li>
      </ol>

      <div
        v-if="completo"
        class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl p-4 text-sm"
        :style="{ background: 'var(--exito-suave)', color: 'var(--exito)' }"
      >
        <span>{{ $t("onboarding.completo") }}</span>
        <button
          class="tu-btn tu-btn-primario"
          @click="router.push({ name: 'panel' })"
        >
          {{ $t("onboarding.irPanel") }}
        </button>
      </div>

      <!-- Paso actual -->
      <article class="tu-card mt-6 p-5 sm:p-8">
        <header class="flex items-start gap-4">
          <span class="ob-icono" aria-hidden="true">
            <IconoNav
              :nombre="ICONOS[pasoActual] ?? 'configuracion'"
              :tam="28"
            />
          </span>
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-xl font-semibold sm:text-2xl">
                {{ $t(`onboarding.pasos.${pasoActual}`) }}
              </h2>
              <span
                v-if="completados.has(pasoActual)"
                class="tu-badge tu-badge-exito"
                >{{ $t("onboarding.hecho") }}</span
              >
            </div>
            <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`onboarding.${pasoActual}.desc`) }}
            </p>
          </div>
        </header>

        <p
          v-if="mensajeTexto"
          class="mt-5 rounded-lg p-2.5 text-sm"
          :style="{ background: 'var(--exito-suave)', color: 'var(--exito)' }"
        >
          {{
            $t(`onboarding.${mensajeTexto.clave}`, {
              nombre: mensajeTexto.valor,
            })
          }}
        </p>
        <p v-if="error" class="mt-5 text-sm" style="color: var(--error)">
          {{ error }}
        </p>

        <div class="mt-6 space-y-4">
          <!-- marca -->
          <template v-if="pasoActual === 'marca'">
            <div>
              <p class="tu-label">
                {{ $t("asistente.logo.etiqueta") }}
                <span :style="{ color: 'var(--texto-suave)' }">{{
                  $t("asistente.logo.opcional")
                }}</span>
              </p>
              <div class="mt-1">
                <CargadorLogo
                  :logo-url="logoUrl"
                  @update:logo-url="logoUrl = $event"
                />
              </div>
            </div>
          </template>

          <!-- sucursal -->
          <template v-else-if="pasoActual === 'sucursal'">
            <!-- Ya hay al menos una sede: se puede volver a agregar más desde Sucursales. -->
            <template v-if="completados.has('sucursal')">
              <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("onboarding.sucursal.yaTienes") }}
              </p>
              <RouterLink
                :to="{ name: 'sedes' }"
                class="tu-btn tu-btn-fantasma inline-flex"
              >
                {{ $t("onboarding.sucursal.gestionar") }}
              </RouterLink>
            </template>
            <div v-else class="grid gap-3 sm:grid-cols-2">
              <div>
                <label class="tu-label" for="sn">{{
                  $t("onboarding.sucursal.nombre")
                }}</label>
                <input
                  id="sn"
                  v-model="suc.nombre"
                  class="tu-input"
                  :placeholder="$t('onboarding.sucursal.nombrePh')"
                />
              </div>
              <div>
                <label class="tu-label" for="sz">{{
                  $t("onboarding.sucursal.zona")
                }}</label>
                <select id="sz" v-model="suc.zona" class="tu-input">
                  <option v-for="z in ZONAS" :key="z" :value="z">
                    {{ z }}
                  </option>
                </select>
              </div>
            </div>
          </template>

          <!-- actividades -->
          <template v-else-if="pasoActual === 'actividades'">
            <div class="grid gap-3 sm:grid-cols-2">
              <div>
                <label class="tu-label" for="ap">{{
                  $t("onboarding.actividades.programa")
                }}</label>
                <input
                  id="ap"
                  v-model="act.programa"
                  class="tu-input"
                  :placeholder="$t('onboarding.actividades.programaPh')"
                />
              </div>
              <div>
                <label class="tu-label" for="aa">{{
                  $t("onboarding.actividades.actividad")
                }}</label>
                <input
                  id="aa"
                  v-model="act.actividad"
                  class="tu-input"
                  :placeholder="$t('onboarding.actividades.actividadPh')"
                />
              </div>
              <div>
                <label class="tu-label" for="ao">{{
                  $t("onboarding.actividades.oferta")
                }}</label>
                <input
                  id="ao"
                  v-model="act.oferta"
                  class="tu-input"
                  :placeholder="$t('onboarding.actividades.ofertaPh')"
                />
              </div>
              <div>
                <label class="tu-label" for="am">{{
                  $t("onboarding.actividades.modalidad")
                }}</label>
                <select id="am" v-model="act.modalidad" class="tu-input">
                  <option value="grupal">
                    {{ $t("onboarding.modalidades.grupal") }}
                  </option>
                  <option value="privada">
                    {{ $t("onboarding.modalidades.privada") }}
                  </option>
                  <option value="individual">
                    {{ $t("onboarding.modalidades.individual") }}
                  </option>
                </select>
              </div>
              <div>
                <label class="tu-label" for="ac">{{
                  $t("onboarding.actividades.capacidad")
                }}</label>
                <input
                  id="ac"
                  v-model="act.capacidad"
                  class="tu-input"
                  type="number"
                  min="1"
                />
              </div>
            </div>
          </template>

          <!-- productos -->
          <template v-else-if="pasoActual === 'productos'">
            <div class="grid gap-3 sm:grid-cols-2">
              <div>
                <label class="tu-label" for="pn">{{
                  $t("onboarding.productos.nombre")
                }}</label>
                <input
                  id="pn"
                  v-model="prod.nombre"
                  class="tu-input"
                  :placeholder="$t('onboarding.productos.nombrePh')"
                />
              </div>
              <div>
                <label class="tu-label" for="pt">{{
                  $t("onboarding.productos.tipo")
                }}</label>
                <select id="pt" v-model="prod.tipo" class="tu-input">
                  <option value="paquete">
                    {{ $t("onboarding.tipos.paquete") }}
                  </option>
                  <option value="membresia">
                    {{ $t("onboarding.tipos.membresia") }}
                  </option>
                </select>
              </div>
              <div>
                <label class="tu-label" for="pp">{{
                  $t("onboarding.productos.precio")
                }}</label>
                <input
                  id="pp"
                  v-model="prod.precio"
                  class="tu-input"
                  type="number"
                  min="0"
                  step="0.01"
                />
              </div>
              <div v-if="prod.tipo === 'paquete'">
                <label class="tu-label" for="pc">{{
                  $t("onboarding.productos.creditos")
                }}</label>
                <input
                  id="pc"
                  v-model="prod.creditos"
                  class="tu-input"
                  type="number"
                  min="1"
                />
                <p
                  class="mt-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("onboarding.productos.creditosAyuda") }}
                </p>
              </div>
            </div>
          </template>

          <!-- personal -->
          <template v-else-if="pasoActual === 'personal'">
            <div class="grid gap-3 sm:grid-cols-3">
              <div>
                <label class="tu-label" for="pen">{{
                  $t("onboarding.personal.nombre")
                }}</label>
                <input id="pen" v-model="per.nombre" class="tu-input" />
              </div>
              <div>
                <label class="tu-label" for="pee">{{
                  $t("onboarding.personal.email")
                }}</label>
                <input
                  id="pee"
                  v-model="per.email"
                  class="tu-input"
                  type="email"
                />
              </div>
              <div>
                <label class="tu-label" for="per">{{
                  $t("onboarding.personal.rol")
                }}</label>
                <select id="per" v-model="per.rol" class="tu-input">
                  <option value="admin">
                    {{ $t("onboarding.roles.admin") }}
                  </option>
                  <option value="recepcionista">
                    {{ $t("onboarding.roles.recepcionista") }}
                  </option>
                  <option value="instructor">
                    {{ $t("onboarding.roles.instructor") }}
                  </option>
                </select>
              </div>
            </div>
            <div
              v-if="invitacion"
              class="rounded-lg p-3 text-sm break-all"
              :style="{ background: 'var(--superficie-2)' }"
            >
              <p class="font-semibold">
                {{
                  $t("onboarding.personal.invitado", {
                    email: invitacion.email,
                  })
                }}
              </p>
              <p class="mt-1">{{ $t("onboarding.personal.tokenDev") }}</p>
              <code>{{ invitacion.token }}</code>
            </div>
          </template>

          <!-- publicacion -->
          <template v-else-if="pasoActual === 'publicacion'">
            <label class="flex items-center gap-2 text-sm cursor-pointer">
              <input v-model="pub.publicado" type="checkbox" />
              <span>{{ $t("onboarding.publicacion.publicar") }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm cursor-pointer">
              <input v-model="pub.privado" type="checkbox" />
              <span>{{ $t("onboarding.publicacion.privado") }}</span>
            </label>
          </template>

          <!-- horarios (guiado: crear en la Agenda; sin callejón) -->
          <template v-else-if="pasoActual === 'horarios'">
            <p
              v-if="configReal.horarios"
              class="rounded-lg p-3 text-sm"
              :style="{
                background: 'var(--exito-suave)',
                color: 'var(--exito)',
              }"
            >
              {{ $t("onboarding.horarios.listo") }}
            </p>
            <template v-else>
              <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{
                  sesion.esCitas
                    ? $t("agendaVisual.onboarding.horariosAyuda")
                    : $t("onboarding.horarios.ayuda")
                }}
              </p>
              <button
                class="tu-btn tu-btn-fantasma"
                type="button"
                @click="irAgenda"
              >
                {{
                  sesion.esCitas
                    ? $t("agendaVisual.onboarding.abrirHorarios")
                    : $t("onboarding.horarios.abrirAgenda")
                }}
              </button>
            </template>
          </template>

          <!-- politicas (config minima inline; sin callejón) -->
          <template v-else-if="pasoActual === 'politicas'">
            <p
              v-if="configReal.politicas"
              class="rounded-lg p-3 text-sm"
              :style="{
                background: 'var(--exito-suave)',
                color: 'var(--exito)',
              }"
            >
              {{ $t("onboarding.politicas.listo") }}
            </p>
            <template v-else>
              <div>
                <label class="tu-label" for="poh">{{
                  $t("onboarding.politicas.horas")
                }}</label>
                <input
                  id="poh"
                  v-model="politica.horas"
                  class="tu-input w-32"
                  type="number"
                  min="0"
                  max="720"
                />
                <p
                  class="mt-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("onboarding.politicas.horasAyuda") }}
                </p>
              </div>
              <label class="flex items-center gap-2 text-sm cursor-pointer">
                <input v-model="politica.penalizaTarde" type="checkbox" />
                <span>{{ $t("onboarding.politicas.penalizaTarde") }}</span>
              </label>
              <label class="flex items-center gap-2 text-sm cursor-pointer">
                <input v-model="politica.penalizaNoShow" type="checkbox" />
                <span>{{ $t("onboarding.politicas.penalizaNoShow") }}</span>
              </label>
            </template>
          </template>
        </div>

        <!-- Pie: omitir · anterior · guardar y continuar -->
        <footer class="ob-pie">
          <button
            v-if="indice < pasos.length - 1"
            type="button"
            class="tu-enlace text-sm"
            @click="avanzar"
          >
            {{ $t("onboarding.omitir") }}
          </button>
          <span v-else />
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="indice === 0"
              @click="retroceder"
            >
              {{ $t("onboarding.anterior") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-primario"
              :disabled="accion.deshabilitado"
              @click="accion.ejecutar"
            >
              {{ accion.texto }}
              <span aria-hidden="true">→</span>
            </button>
          </div>
        </footer>
      </article>
    </template>
  </section>
</template>

<style scoped>
.ob-avance {
  width: min(100%, 26rem);
}
.ob-barra {
  flex: 1;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--superficie-2);
  overflow: hidden;
}
.ob-barra > span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--primario);
  transition: width 0.3s ease;
}

/* Pasos: círculos numerados unidos por una línea; en el teléfono se desplazan. */
.ob-pasos {
  display: flex;
  margin-top: 2rem;
  overflow-x: auto;
  padding-bottom: 0.25rem;
}
.ob-paso {
  position: relative;
  flex: 1 0 5.5rem;
}
.ob-paso + .ob-paso::before {
  content: "";
  position: absolute;
  top: 1.25rem;
  right: calc(50% + 1.5rem);
  left: calc(-50% + 1.5rem);
  height: 1px;
  background: var(--borde);
}
.ob-paso.ob-hecho::before,
.ob-paso.ob-actual::before {
  background: color-mix(in srgb, var(--primario) 45%, var(--borde));
}
.ob-paso-boton {
  display: flex;
  width: 100%;
  flex-direction: column;
  align-items: center;
  gap: 0.45rem;
}
.ob-circulo {
  display: inline-flex;
  height: 2.5rem;
  width: 2.5rem;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: var(--superficie-2);
  color: var(--texto-suave);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  transition:
    background 0.15s ease,
    color 0.15s ease;
}
.ob-paso-boton:hover .ob-circulo {
  color: var(--texto);
}
.ob-etiqueta {
  font-size: 0.875rem;
  color: var(--texto-suave);
  white-space: nowrap;
}
.ob-hecho .ob-circulo {
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
.ob-actual .ob-circulo {
  background: var(--primario);
  color: var(--primario-contraste, #fff);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--primario) 18%, transparent);
}
.ob-actual .ob-etiqueta {
  color: var(--primario);
  font-weight: 600;
}

.ob-icono {
  display: inline-flex;
  height: 3.5rem;
  width: 3.5rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 1rem;
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}

.ob-pie {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-top: 2rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--borde);
}
</style>
