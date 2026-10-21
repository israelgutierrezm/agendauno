<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import DetallePresupuesto, {
  type PresupuestoCliente,
} from "@/components/DetallePresupuesto.vue";
import RespuestaPresupuesto from "@/components/RespuestaPresupuesto.vue";
import { api } from "@/lib/api";
import { useRetornoPago } from "@/lib/retornoPago";

/**
 * Un presupuesto en línea, desde el enlace del correo (`/presupuesto/{negocio}/{id}?t=`),
 * sin cuenta: verlo, aceptarlo o rechazarlo, pagarlo y agendar sus servicios. El token
 * se guarda en la pestaña para volver del pago (la pasarela regresa sin él).
 */
const { t } = useI18n();
const route = useRoute();
const retornoPago = useRetornoPago();
const slug = String(route.params.slug ?? "");
const id = String(route.params.id ?? "");
const CLAVE = `presupuesto:${id}`;

function tokenDelEnlace(): string {
  const enUrl = typeof route.query.t === "string" ? route.query.t : "";
  try {
    if (enUrl !== "") {
      sessionStorage.setItem(CLAVE, enUrl);
      return enUrl;
    }
    return sessionStorage.getItem(CLAVE) ?? "";
  } catch {
    return enUrl;
  }
}

const token = tokenDelEnlace();
const presupuesto = ref<PresupuestoCliente | null>(null);
const marca = ref<{ nombre: string; logo_url: string | null } | null>(null);
const estado = ref<"cargando" | "listo" | "invalido">("cargando");
const url = computed(
  () =>
    `/api/v1/app/${encodeURIComponent(slug)}/presupuestos-en-linea/${encodeURIComponent(id)}`,
);

function vigencia(fecha: string): string {
  const [a, m, d] = fecha.split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(new Date(a ?? 1970, (m ?? 1) - 1, d ?? 1));
}

async function cargar(): Promise<void> {
  if (token === "") {
    estado.value = "invalido";
    return;
  }
  try {
    const [p, m] = await Promise.all([
      api.get<{ data: PresupuestoCliente }>(url.value, {
        params: { t: token },
      }),
      api
        .get<{
          data: { nombre: string; logo_url: string | null };
        }>(`/api/v1/app/${encodeURIComponent(slug)}/marca`)
        .catch(() => null),
    ]);
    presupuesto.value = p.data.data;
    marca.value = m?.data.data ?? null;
    estado.value = "listo";
  } catch {
    estado.value = "invalido";
  }
}

onMounted(cargar);
</script>

<template>
  <main class="pel">
    <p v-if="estado === 'cargando'" :style="{ color: 'var(--texto-suave)' }">
      {{ t("comun.cargando") }}
    </p>
    <div
      v-else-if="estado === 'invalido'"
      class="text-center py-16"
      data-prueba="presupuesto-invalido"
    >
      <h1 class="text-xl font-semibold">
        {{ t("presupuestos.enLinea.invalido") }}
      </h1>
      <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
        {{ t("presupuestos.enLinea.invalidoTexto") }}
      </p>
    </div>
    <article
      v-else-if="presupuesto"
      class="tu-card p-5 sm:p-7"
      data-prueba="presupuesto-en-linea"
    >
      <header class="pel-cabecera">
        <div class="flex items-center gap-3 min-w-0">
          <img
            v-if="marca?.logo_url"
            :src="marca.logo_url"
            alt=""
            class="pel-logo"
          />
          <strong class="text-lg truncate">{{ marca?.nombre }}</strong>
        </div>
        <div class="text-sm sm:text-right">
          <p class="font-semibold text-base">
            {{ t("presupuestos.impreso.folio", { folio: presupuesto.folio }) }}
          </p>
          <p :style="{ color: 'var(--texto-suave)' }">
            {{
              t("presupuestos.impreso.vigencia", {
                fecha: vigencia(presupuesto.valido_hasta),
              })
            }}
          </p>
        </div>
      </header>

      <AvisoTono
        v-if="retornoPago === 'exito' || retornoPago === 'pendiente'"
        tono="exito"
        class="mt-4"
      >
        {{
          t(
            retornoPago === "exito"
              ? "presupuestos.enLinea.pagoExito"
              : "presupuestos.enLinea.pagoPendiente",
          )
        }}
      </AvisoTono>
      <AvisoTono
        v-else-if="retornoPago === 'cancelado'"
        tono="aviso"
        class="mt-4"
      >
        {{ t("presupuestos.enLinea.pagoCancelado") }}
      </AvisoTono>

      <p v-if="presupuesto.cliente" class="mt-5 text-sm">
        {{ t("presupuestos.enLinea.para", { nombre: presupuesto.cliente }) }}
      </p>
      <DetallePresupuesto :presupuesto="presupuesto" class="mt-4" />
      <RespuestaPresupuesto
        class="mt-6"
        :presupuesto="presupuesto"
        :url="url"
        :slug="slug"
        :token="token"
        @actualizado="presupuesto = $event"
      />
    </article>
  </main>
</template>

<style scoped>
.pel {
  max-width: 44rem;
  margin: 0 auto;
  padding: 2rem 1rem 4rem;
}
.pel-cabecera {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}
.pel-logo {
  height: 2.75rem;
  width: auto;
  object-fit: contain;
}
</style>
