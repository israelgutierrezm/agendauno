<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import BuscarPersona from "@/components/BuscarPersona.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useDescartarCambios } from "@/lib/editorFormulario";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * La familia de un alumno en su ficha (ADR 0123): a quién tiene a su cargo y quién es
 * su responsable, con el estado de la autorización. El equipo pone a alguien a su
 * cargo (queda pendiente), registra que el responsable aceptó en el mostrador (con el
 * texto a la vista) y retira la autorización.
 */
interface Vinculo {
  id: string;
  persona: { id: string; nombre: string; tiene_cuenta: boolean };
  parentesco: string;
  estado: "pendiente" | "activa" | "retirada";
  autorizacion: {
    version: number | null;
    aceptada_en: string | null;
    via: string | null;
  } | null;
}
interface Familia {
  activo: boolean;
  version: number;
  texto: string;
  tiene_cuenta: boolean;
  a_su_cargo: Vinculo[];
  responsables: Vinculo[];
}

const PARENTESCOS = [
  "madre_padre",
  "tutor_legal",
  "familiar",
  "autorizado",
] as const;

const props = defineProps<{ personaId: string; nombre: string }>();
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("miembros.gestionar"));

const familia = ref<Familia | null>(null);
const error = ref<string | null>(null);
const agregando = ref(false);
const nuevo = ref<{ participante: string; parentesco: string }>({
  participante: "",
  parentesco: "madre_padre",
});
const guardando = ref(false);
// Lo que no se pudo vincular se ve en su panel (no en la ficha de atrás).
const errorVincular = ref<string | null>(null);
// Con lo elegido sin guardar, cerrar o salir pregunta antes (patrón de formularios).
const descartar = useDescartarCambios({
  abierto: agregando,
  valor: () => nuevo.value,
  guardando,
});
function abrirAgregar(): void {
  errorVincular.value = null;
  descartar.fijar();
  agregando.value = true;
}
function cerrarAgregar(): void {
  void descartar.cerrar();
}
// El vínculo cuya autorización se registra en el mostrador y quién la aceptó.
const enMostrador = ref<{ vinculo: Vinculo; responsable: string } | null>(null);
// Lo que no se pudo registrar se ve en el diálogo (no en un aviso flotante).
const errorMostrador = ref<string | null>(null);
function abrirMostrador(vinculo: Vinculo, responsable: string): void {
  errorMostrador.value = null;
  enMostrador.value = { vinculo, responsable };
}

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Familia }>(
      `${base.value}/miembros/${props.personaId}/familia`,
    );
    familia.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
onMounted(cargar);

// Solo lo vigente; lo retirado queda en la bitácora.
const aSuCargo = computed(() =>
  (familia.value?.a_su_cargo ?? []).filter((v) => v.estado !== "retirada"),
);
const responsables = computed(() =>
  (familia.value?.responsables ?? []).filter((v) => v.estado !== "retirada"),
);
const visible = computed(
  () =>
    familia.value !== null &&
    (familia.value.activo ||
      aSuCargo.value.length > 0 ||
      responsables.value.length > 0),
);

function fecha(iso: string | null): string {
  return iso === null
    ? ""
    : new Date(iso).toLocaleDateString("es-MX", {
        day: "numeric",
        month: "short",
        year: "numeric",
        timeZone: sesion.zonaHoraria || undefined,
      });
}

async function vincular(): Promise<void> {
  if (nuevo.value.participante === "") {
    return;
  }
  guardando.value = true;
  errorVincular.value = null;
  try {
    await api.post(`${base.value}/miembros/${props.personaId}/participantes`, {
      participante_id: nuevo.value.participante,
      parentesco: nuevo.value.parentesco,
    });
    nuevo.value = { participante: "", parentesco: "madre_padre" };
    descartar.fijar();
    agregando.value = false;
    toast.exito(t("familias.ficha.vinculado"));
    await cargar();
  } catch (e) {
    errorVincular.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

async function registrarEnMostrador(): Promise<void> {
  const v = enMostrador.value?.vinculo;
  if (v === undefined || familia.value === null) {
    return;
  }
  guardando.value = true;
  errorMostrador.value = null;
  try {
    await api.post(`${base.value}/tutelas/${v.id}/autorizar`, {
      version: familia.value.version,
    });
    enMostrador.value = null;
    toast.exito(t("familias.ficha.registrada"));
    await cargar();
  } catch (e) {
    errorMostrador.value = mensajeDeError(e);
    await cargar();
  } finally {
    guardando.value = false;
  }
}

async function retirar(
  v: Vinculo,
  responsable: string,
  participante: string,
): Promise<void> {
  if (
    !(await confirmar(
      t("familias.ficha.confirmarRetiro", { responsable, participante }),
      { peligro: true },
    ))
  ) {
    return;
  }
  try {
    await api.post(`${base.value}/tutelas/${v.id}/retirar`, {});
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}
</script>

<template>
  <section v-if="visible" class="tu-card px-5 py-4" data-prueba="familia">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-medium">{{ $t("familias.ficha.titulo") }}</h2>
      <button
        v-if="puedeGestionar && familia?.activo && familia.tiene_cuenta"
        type="button"
        class="tu-enlace text-sm"
        data-prueba="agregar-familiar"
        @click="abrirAgregar"
      >
        {{ $t("familias.ficha.agregar") }}
      </button>
    </div>
    <p
      v-if="familia?.activo && !familia.tiene_cuenta && aSuCargo.length === 0"
      class="mt-1 text-xs"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("familias.ficha.sinCuenta") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-2" role="alert">
      {{ error }}
    </AvisoTono>

    <template
      v-for="grupo in [
        { clave: 'aSuCargo', lista: aSuCargo },
        { clave: 'responsables', lista: responsables },
      ]"
      :key="grupo.clave"
    >
      <template v-if="grupo.lista.length > 0">
        <h3
          class="mt-3 text-xs font-medium uppercase tracking-wide"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t(`familias.ficha.${grupo.clave}`) }}
        </h3>
        <ul class="mt-1 divide-y divide-[var(--borde)]">
          <li
            v-for="v in grupo.lista"
            :key="v.id"
            class="flex flex-wrap items-center justify-between gap-3 py-2.5"
          >
            <div class="min-w-0">
              <RouterLink
                class="tu-enlace text-sm font-medium"
                :to="{ name: 'ficha-miembro', params: { id: v.persona.id } }"
                >{{ v.persona.nombre }}</RouterLink
              >
              <div
                class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                <span>{{ $t(`familias.parentescos.${v.parentesco}`) }}</span>
                <span aria-hidden="true">·</span>
                <span class="inline-flex items-center gap-1.5">
                  <span
                    class="inline-block size-1.5 rounded-full"
                    :style="{
                      background:
                        v.estado === 'activa' ? 'var(--exito)' : 'var(--aviso)',
                    }"
                    aria-hidden="true"
                  />
                  {{
                    v.estado === "activa" && v.autorizacion
                      ? $t("familias.ficha.autorizadaEl", {
                          fecha: fecha(v.autorizacion.aceptada_en),
                          via: $t(
                            `familias.ficha.via.${v.autorizacion.via ?? "cuenta"}`,
                          ),
                        })
                      : $t(`familias.estados.${v.estado}`)
                  }}
                </span>
              </div>
            </div>
            <div v-if="puedeGestionar" class="flex items-center gap-3 text-sm">
              <button
                v-if="v.estado === 'pendiente'"
                type="button"
                class="tu-enlace"
                @click="
                  abrirMostrador(
                    v,
                    grupo.clave === 'aSuCargo' ? nombre : v.persona.nombre,
                  )
                "
              >
                {{ $t("familias.ficha.registrarMostrador") }}
              </button>
              <button
                type="button"
                class="tu-enlace"
                :style="{ color: 'var(--error)' }"
                @click="
                  grupo.clave === 'aSuCargo'
                    ? retirar(v, nombre, v.persona.nombre)
                    : retirar(v, v.persona.nombre, nombre)
                "
              >
                {{ $t("familias.ficha.retirar") }}
              </button>
            </div>
          </li>
        </ul>
      </template>
    </template>
    <p
      v-if="aSuCargo.length === 0 && responsables.length === 0"
      class="mt-2 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("familias.ficha.sinNada") }}
    </p>

    <!-- Poner a alguien a su cargo -->
    <PanelLateral
      :abierto="agregando"
      :titulo="$t('familias.ficha.agregar')"
      :ocupado="guardando"
      @cerrar="cerrarAgregar"
    >
      <form class="grid gap-4" @submit.prevent="vincular">
        <AvisoTono
          v-if="errorVincular"
          tono="peligro"
          role="alert"
          data-prueba="error-vincular"
        >
          {{ errorVincular }}
        </AvisoTono>
        <div>
          <label class="tu-label" for="fam-participante">{{
            $t("familias.ficha.participante")
          }}</label>
          <BuscarPersona
            v-model="nuevo.participante"
            campo-id="fam-participante"
            :buscar-en="`${base}/miembros`"
            :parametros="{ tipo: 'miembro' }"
            :deshabilitado="guardando"
          />
        </div>
        <div>
          <label class="tu-label" for="fam-parentesco">{{
            $t("familias.ficha.parentesco")
          }}</label>
          <select
            id="fam-parentesco"
            v-model="nuevo.parentesco"
            class="tu-input"
          >
            <option v-for="p in PARENTESCOS" :key="p" :value="p">
              {{ $t(`familias.parentescos.${p}`) }}
            </option>
          </select>
        </div>
        <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("familias.ficha.ayudaAgregar", { nombre }) }}
        </p>
      </form>
      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="guardando"
            @click="cerrarAgregar"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="guardando || nuevo.participante === ''"
            @click="vincular"
          >
            {{ $t("familias.ficha.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>

    <!-- El responsable aceptó en el mostrador: con el texto a la vista -->
    <ModalDialogo
      :abierto="enMostrador !== null"
      :titulo="$t('familias.ficha.registrarMostrador')"
      tam="md"
      :ocupado="guardando"
      @cerrar="enMostrador = null"
    >
      <div v-if="enMostrador && familia" class="grid gap-3 text-sm">
        <p>
          {{
            $t("familias.ficha.confirmarMostrador", {
              nombre: enMostrador.responsable,
            })
          }}
        </p>
        <blockquote
          class="rounded-lg border px-4 py-3 whitespace-pre-line"
          :style="{ borderColor: 'var(--borde)' }"
        >
          {{ familia.texto }}
        </blockquote>
        <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("familias.version", { n: familia.version }) }}
        </p>
        <AvisoTono v-if="errorMostrador" tono="peligro" role="alert">
          {{ errorMostrador }}
        </AvisoTono>
      </div>
      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="guardando"
            @click="enMostrador = null"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="guardando"
            @click="registrarEnMostrador"
          >
            {{ $t("familias.ficha.confirmarAceptacion") }}
          </button>
        </div>
      </template>
    </ModalDialogo>
  </section>
</template>
