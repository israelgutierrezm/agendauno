<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";

import LogoCalendario from "@/components/LogoCalendario.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Google Calendar y Outlook en las dos direcciones (ADR 0199), en Mi perfil de quien
 * atiende. «Conectar» lleva a Google o Microsoft; la vuelta regresa aquí con el código
 * en la URL y esta tarjeta lo confirma con la sesión (y lo quita de la URL).
 */
type Proveedor = "google" | "microsoft";
type Conexion = {
  id: string;
  proveedor: Proveedor;
  cuenta: string | null;
  estado: "activo" | "reconectar";
  ultimo_error: string | null;
  sincronizado_en: string | null;
};
type Opcion = {
  proveedor: Proveedor;
  configurado: boolean;
  conexion: Conexion | null;
};

const emit = defineEmits<{ disponible: [valor: boolean] }>();

const { t, locale } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
// Sin enrutador (p. ej. en pruebas aisladas) no hay vuelta que procesar.
const ruta = useRoute() as ReturnType<typeof useRoute> | undefined;
const router = useRouter() as ReturnType<typeof useRouter> | undefined;
const base = () => `/api/v1/app/${sesion.slug}/yo/calendarios`;

const opciones = ref<Opcion[]>([]);
const ocupado = ref<Proveedor | null>(null);

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{
      data: { disponible: boolean; proveedores: Opcion[] };
    }>(base());
    opciones.value = data.data.proveedores.filter(
      (o) => o.configurado || o.conexion !== null,
    );
    emit("disponible", data.data.disponible);
  } catch {
    emit("disponible", false);
  }
}

function reemplazar(conexion: Conexion): void {
  const opcion = opciones.value.find((o) => o.proveedor === conexion.proveedor);
  if (opcion) {
    opcion.conexion = conexion;
  }
}

async function conectar(proveedor: Proveedor): Promise<void> {
  ocupado.value = proveedor;
  try {
    const { data } = await api.post<{ data: { url: string } }>(
      `${base()}/${proveedor}/conectar`,
    );
    window.location.assign(data.data.url);
  } catch (err) {
    toast.error(mensajeDeError(err, t("calendariosExternos.error")));
    ocupado.value = null;
  }
}

async function sincronizar(proveedor: Proveedor): Promise<void> {
  ocupado.value = proveedor;
  try {
    const { data } = await api.post<{ data: Conexion }>(
      `${base()}/${proveedor}/sincronizar`,
    );
    reemplazar(data.data);
  } catch (err) {
    toast.error(mensajeDeError(err, t("calendariosExternos.error")));
  } finally {
    ocupado.value = null;
  }
}

async function desconectar(proveedor: Proveedor): Promise<void> {
  if (
    !(await confirmar(t("calendariosExternos.desconectarConfirmar"), {
      peligro: true,
      aceptar: t("calendariosExternos.desconectar"),
    }))
  ) {
    return;
  }
  ocupado.value = proveedor;
  try {
    await api.delete(`${base()}/${proveedor}`);
    const opcion = opciones.value.find((o) => o.proveedor === proveedor);
    if (opcion) {
      opcion.conexion = null;
    }
    toast.exito(t("calendariosExternos.desconectado"));
  } catch (err) {
    toast.error(mensajeDeError(err, t("calendariosExternos.error")));
  } finally {
    ocupado.value = null;
  }
}

/** La vuelta de Google o Microsoft: confirma el código y lo quita de la URL. */
async function procesarVuelta(): Promise<void> {
  const q = ruta?.query ?? {};
  const calendario = typeof q.calendario === "string" ? q.calendario : null;
  if (calendario === null) {
    return;
  }
  const { codigo, estado } = q;
  const resto = { ...q };
  delete resto.calendario;
  delete resto.codigo;
  delete resto.estado;
  void router?.replace({ query: resto });
  if (calendario === "cancelado") {
    toast.error(t("calendariosExternos.cancelado"));
    return;
  }
  if (
    (calendario !== "google" && calendario !== "microsoft") ||
    typeof codigo !== "string" ||
    typeof estado !== "string"
  ) {
    toast.error(t("calendariosExternos.error"));
    return;
  }
  ocupado.value = calendario;
  try {
    const { data } = await api.post<{ data: Conexion }>(
      `${base()}/${calendario}/confirmar`,
      { codigo, estado },
    );
    reemplazar(data.data);
    toast.exito(t("calendariosExternos.listo"));
  } catch (err) {
    toast.error(mensajeDeError(err, t("calendariosExternos.error")));
  } finally {
    ocupado.value = null;
  }
}

function cuando(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso));
}

onMounted(async () => {
  await cargar();
  await procesarVuelta();
});
</script>

<template>
  <ul class="cc-lista" data-prueba="calendarios-externos">
    <li v-for="o in opciones" :key="o.proveedor" class="cc-fila">
      <LogoCalendario
        :marca="o.proveedor === 'google' ? 'google' : 'outlook'"
        :tam="22"
      />
      <div class="cc-texto">
        <strong>{{ $t(`calendariosExternos.${o.proveedor}`) }}</strong>
        <template v-if="o.conexion">
          <span class="cc-estado" :data-estado="o.conexion.estado">
            <span class="cc-punto" aria-hidden="true" />{{
              o.conexion.estado === "activo"
                ? $t("calendariosExternos.conectado")
                : $t("calendariosExternos.reconectar")
            }}<template v-if="o.conexion.cuenta">
              · {{ o.conexion.cuenta }}</template
            >
          </span>
          <span v-if="o.conexion.ultimo_error" class="tu-hint">{{
            o.conexion.ultimo_error
          }}</span>
          <span v-else-if="o.conexion.sincronizado_en" class="tu-hint">{{
            $t("calendariosExternos.ultima", {
              cuando: cuando(o.conexion.sincronizado_en),
            })
          }}</span>
        </template>
      </div>
      <div class="cc-acciones">
        <button
          v-if="!o.conexion || o.conexion.estado === 'reconectar'"
          type="button"
          class="tu-btn tu-btn-fantasma text-sm"
          :disabled="ocupado !== null || !o.configurado"
          @click="conectar(o.proveedor)"
        >
          {{ $t("calendariosExternos.conectar") }}
        </button>
        <button
          v-if="o.conexion?.estado === 'activo'"
          type="button"
          class="tu-btn tu-btn-fantasma text-sm"
          :disabled="ocupado !== null"
          @click="sincronizar(o.proveedor)"
        >
          {{ $t("calendariosExternos.sincronizar") }}
        </button>
        <button
          v-if="o.conexion"
          type="button"
          class="tu-btn tu-btn-fantasma text-sm"
          :disabled="ocupado !== null"
          @click="desconectar(o.proveedor)"
        >
          {{ $t("calendariosExternos.desconectar") }}
        </button>
      </div>
    </li>
  </ul>
</template>

<style scoped>
.cc-lista {
  display: grid;
  gap: 0.75rem;
}
.cc-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}
.cc-texto {
  display: grid;
  flex: 1 1 12rem;
  min-width: 0;
  gap: 0.125rem;
  font-size: 0.875rem;
}
.cc-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  overflow-wrap: anywhere;
  color: var(--texto-suave);
}
.cc-punto {
  width: 0.5rem;
  height: 0.5rem;
  flex: none;
  border-radius: 999px;
  background: var(--exito);
}
.cc-estado[data-estado="reconectar"] .cc-punto {
  background: var(--aviso);
}
.cc-acciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
</style>
