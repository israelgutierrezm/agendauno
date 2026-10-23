<script setup lang="ts">
import axios from "axios";
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * Superadmin, «Equipo» (ADR 0132): los operadores de la plataforma (alta, contraseña,
 * activar o desactivar) y sus sesiones abiertas (cerrarlas). Antes solo se daban de
 * alta con un comando. Siempre queda al menos uno activo y nadie se desactiva a sí
 * mismo (lo cuida el servidor).
 */
const props = defineProps<{ apiUrl: string; token: string }>();

interface Operador {
  id: string;
  nombre: string;
  email: string;
  activo: boolean;
  ultimo_acceso_en: string | null;
  sesiones_abiertas: number;
  soy_yo: boolean;
}
interface Sesion {
  id: string;
  administrador: { nombre: string; email: string } | null;
  ip: string | null;
  agente: string | null;
  abierta_en: string | null;
  ultimo_uso_en: string | null;
  expira_en: string;
  actual: boolean;
}

const { t } = useI18n();
const toast = useToastStore();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});
const encabezados = () => ({
  headers: { Authorization: `Bearer ${props.token}` },
});

const operadores = ref<Operador[]>([]);
const sesiones = ref<Sesion[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const accionando = ref<string | null>(null);
const nuevo = ref({ nombre: "", email: "", password: "" });
const cambiando = ref<string | null>(null);
const nuevaContrasena = ref("");

async function cargar(): Promise<void> {
  error.value = null;
  try {
    const [ops, ses] = await Promise.all([
      cliente.get<{ data: Operador[] }>(
        "/api/v1/plataforma/administradores",
        encabezados(),
      ),
      cliente.get<{ data: Sesion[] }>(
        "/api/v1/plataforma/sesiones",
        encabezados(),
      ),
    ]);
    operadores.value = ops.data.data;
    sesiones.value = ses.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function agregar(): Promise<void> {
  accionando.value = "nuevo";
  try {
    await cliente.post(
      "/api/v1/plataforma/administradores",
      nuevo.value,
      encabezados(),
    );
    toast.exito(
      t("plataformaAdmin.equipo.agregado", { nombre: nuevo.value.nombre }),
    );
    nuevo.value = { nombre: "", email: "", password: "" };
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = null;
  }
}

async function cambiarActivo(o: Operador): Promise<void> {
  if (
    o.activo &&
    !(await confirmar(
      t("plataformaAdmin.equipo.confirmarDesactivar", { nombre: o.nombre }),
      { peligro: true },
    ))
  ) {
    return;
  }
  accionando.value = o.id;
  try {
    await cliente.put(
      `/api/v1/plataforma/administradores/${o.id}`,
      { activo: !o.activo },
      encabezados(),
    );
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = null;
  }
}

async function guardarContrasena(o: Operador): Promise<void> {
  accionando.value = o.id;
  try {
    await cliente.put(
      `/api/v1/plataforma/administradores/${o.id}`,
      { password: nuevaContrasena.value },
      encabezados(),
    );
    toast.exito(
      t("plataformaAdmin.equipo.contrasenaCambiada", { nombre: o.nombre }),
    );
    cambiando.value = null;
    nuevaContrasena.value = "";
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = null;
  }
}

async function cerrarSesion(s: Sesion): Promise<void> {
  if (
    !(await confirmar(
      s.actual
        ? t("plataformaAdmin.equipo.confirmarCerrarPropia")
        : t("plataformaAdmin.equipo.confirmarCerrar", {
            nombre: s.administrador?.nombre ?? "—",
          }),
      { peligro: true },
    ))
  ) {
    return;
  }
  accionando.value = s.id;
  try {
    await cliente.delete(`/api/v1/plataforma/sesiones/${s.id}`, encabezados());
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = null;
  }
}

function fecha(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(iso))
    : "—";
}

onMounted(() => void cargar());
</script>

<template>
  <div class="space-y-5" data-prueba="equipo-plataforma">
    <AvisoTono v-if="error" tono="peligro" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="cargar"
        >
          {{ t("plataformaAdmin.equipo.reintentar") }}
        </button>
      </template>
    </AvisoTono>

    <section class="tu-card p-5">
      <h2 class="tu-titulo-seccion">
        {{ t("plataformaAdmin.equipo.titulo") }}
      </h2>
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ t("plataformaAdmin.equipo.ayuda") }}
      </p>
      <p v-if="cargando" class="mt-4 text-sm" role="status">
        {{ t("plataformaAdmin.equipo.cargando") }}
      </p>
      <ul v-else class="mt-3">
        <li
          v-for="o in operadores"
          :key="o.id"
          class="eq-fila"
          data-prueba="operador"
        >
          <div class="min-w-0">
            <p class="font-medium">
              {{ o.nombre
              }}<span v-if="o.soy_yo" :style="{ color: 'var(--texto-suave)' }">
                · {{ t("plataformaAdmin.equipo.tu") }}</span
              >
            </p>
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ o.email }} ·
              {{
                t("plataformaAdmin.equipo.ultimoAcceso", {
                  fecha: fecha(o.ultimo_acceso_en),
                })
              }}
              ·
              {{
                t(
                  "plataformaAdmin.equipo.sesiones",
                  { n: o.sesiones_abiertas },
                  o.sesiones_abiertas,
                )
              }}
            </p>
            <form
              v-if="cambiando === o.id"
              class="mt-2 flex flex-wrap items-end gap-2"
              @submit.prevent="guardarContrasena(o)"
            >
              <input
                v-model="nuevaContrasena"
                type="password"
                class="tu-input w-auto"
                minlength="12"
                autocomplete="new-password"
                :aria-label="t('plataformaAdmin.equipo.nuevaContrasena')"
                :placeholder="t('plataformaAdmin.equipo.nuevaContrasena')"
              />
              <button
                type="button"
                class="tu-btn tu-btn-secundario tu-btn-chico"
                @click="cambiando = null"
              >
                {{ t("plataformaAdmin.equipo.cancelar") }}
              </button>
              <button
                type="submit"
                class="tu-btn tu-btn-primario tu-btn-chico"
                :disabled="nuevaContrasena.length < 12 || accionando !== null"
                :aria-busy="accionando === o.id || undefined"
              >
                {{ t("plataformaAdmin.equipo.guardar") }}
              </button>
            </form>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span
              class="tu-estado text-sm"
              :style="{
                '--tono': o.activo ? 'var(--exito)' : 'var(--texto-suave)',
              }"
              >{{
                o.activo
                  ? t("plataformaAdmin.equipo.activo")
                  : t("plataformaAdmin.equipo.inactivo")
              }}</span
            >
            <button
              type="button"
              class="tu-btn tu-btn-secundario tu-btn-chico"
              :disabled="accionando !== null"
              @click="
                cambiando = o.id;
                nuevaContrasena = '';
              "
            >
              {{ t("plataformaAdmin.equipo.cambiarContrasena") }}
            </button>
            <!-- Desactivar es destructiva (pide confirmación); activar, no. -->
            <button
              v-if="!o.soy_yo"
              type="button"
              class="tu-btn tu-btn-chico"
              :class="o.activo ? 'tu-btn-peligro' : 'tu-btn-secundario'"
              :disabled="accionando !== null"
              @click="cambiarActivo(o)"
            >
              {{
                o.activo
                  ? t("plataformaAdmin.equipo.desactivar")
                  : t("plataformaAdmin.equipo.activar")
              }}
            </button>
          </div>
        </li>
      </ul>

      <form
        class="mt-5 grid gap-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end"
        data-prueba="nuevo-operador"
        @submit.prevent="agregar"
      >
        <div>
          <label class="tu-label" for="op-nombre">{{
            t("plataformaAdmin.equipo.nombre")
          }}</label>
          <input
            id="op-nombre"
            v-model="nuevo.nombre"
            class="tu-input"
            required
            maxlength="120"
          />
        </div>
        <div>
          <label class="tu-label" for="op-correo">{{
            t("plataformaAdmin.equipo.correo")
          }}</label>
          <input
            id="op-correo"
            v-model="nuevo.email"
            class="tu-input"
            type="email"
            required
          />
        </div>
        <div>
          <label class="tu-label" for="op-contrasena">{{
            t("plataformaAdmin.equipo.contrasena")
          }}</label>
          <input
            id="op-contrasena"
            v-model="nuevo.password"
            class="tu-input"
            type="password"
            minlength="12"
            autocomplete="new-password"
            required
          />
        </div>
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="accionando !== null"
        >
          {{ t("plataformaAdmin.equipo.agregar") }}
        </button>
      </form>
      <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ t("plataformaAdmin.equipo.contrasenaAyuda") }}
      </p>
    </section>

    <section class="tu-card p-5">
      <h2 class="tu-titulo-seccion">
        {{ t("plataformaAdmin.equipo.sesionesTitulo") }}
      </h2>
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ t("plataformaAdmin.equipo.sesionesAyuda") }}
      </p>
      <ul class="mt-3">
        <li
          v-for="s in sesiones"
          :key="s.id"
          class="eq-fila"
          data-prueba="sesion"
        >
          <div class="min-w-0">
            <p class="font-medium">
              {{ s.administrador?.nombre ?? "—"
              }}<span v-if="s.actual" :style="{ color: 'var(--texto-suave)' }">
                · {{ t("plataformaAdmin.equipo.estaSesion") }}</span
              >
            </p>
            <p
              class="text-sm truncate"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ s.ip ?? "—" }} · {{ s.agente ?? "—" }}
            </p>
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{
                t("plataformaAdmin.equipo.usoSesion", {
                  abierta: fecha(s.abierta_en),
                  uso: fecha(s.ultimo_uso_en),
                  vence: fecha(s.expira_en),
                })
              }}
            </p>
          </div>
          <!-- Revoca la sesión: destructiva. -->
          <button
            type="button"
            class="tu-btn tu-btn-peligro tu-btn-chico shrink-0"
            :disabled="accionando !== null"
            @click="cerrarSesion(s)"
          >
            {{ t("plataformaAdmin.equipo.cerrar") }}
          </button>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.eq-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  border-top: 1px solid var(--borde);
  padding: 0.75rem 0;
}
</style>
