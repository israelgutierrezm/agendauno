<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import ListaFormularios from "@/components/ListaFormularios.vue";
import MisDocumentos from "@/components/MisDocumentos.vue";
import { useMiCuenta, type Waiver } from "@/lib/miCuenta";

/**
 * Expediente del portal: lo que el negocio le pide firmar, sus documentos y los
 * formularios que debe llenar.
 */
const cuenta = useMiCuenta();
const variasSecciones = computed(
  () =>
    Number(cuenta.waivers.value.length > 0) +
      Number(cuenta.personaId.value !== null) +
      Number(
        cuenta.formularios.value.length > 0 && cuenta.personaId.value !== null,
      ) >
    1,
);

// Si aceptar un documento falla, el porqué va junto a ese documento (no arriba, donde
// el «Reintentar» solo recarga la página).
const errorWaiver = ref<{ id: string; mensaje: string } | null>(null);
async function aceptar(w: Waiver): Promise<void> {
  errorWaiver.value = null;
  if (!(await cuenta.aceptarWaiver(w))) {
    errorWaiver.value = { id: w.id, mensaje: cuenta.error.value ?? "" };
    cuenta.error.value = null;
  }
}

onMounted(() => void cuenta.asegurar());
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('portal.expediente.titulo')"
      :subtitulo="$t('portal.expediente.descripcion')"
    />

    <AvisoTono v-if="cuenta.error.value" tono="peligro" class="mt-3">
      {{ cuenta.error.value }}
      <button
        type="button"
        class="tu-btn tu-btn-secundario tu-btn-chico ml-2"
        @click="cuenta.cargar(true)"
      >
        {{ $t("comun.reintentar") }}
      </button>
    </AvisoTono>
    <p
      v-if="cuenta.cargando.value"
      class="mt-6"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else-if="!cuenta.error.value || cuenta.cargado.value">
      <nav
        v-if="variasSecciones"
        class="me-accesos"
        :aria-label="$t('portal.expediente.secciones')"
      >
        <a
          v-if="cuenta.waivers.value.length"
          href="#me-firmar"
          class="me-acceso tu-card tu-card-enlace"
          ><IconoNav nombre="expediente" :tam="26" /><span
            ><strong>{{ $t("portal.expediente.firmar") }}</strong
            ><small class="me-estado">{{
              $t("portal.inicio.atencion.firmar", cuenta.waivers.value.length)
            }}</small></span
          ></a
        >
        <a
          v-if="cuenta.personaId.value !== null"
          href="#me-documentos"
          class="me-acceso tu-card tu-card-enlace"
          ><IconoNav nombre="expediente" :tam="26" /><span
            ><strong>{{ $t("portal.expediente.documentos") }}</strong
            ><small>{{ $t("portal.expediente.documentosAyuda") }}</small></span
          ></a
        >
        <a
          v-if="
            cuenta.formularios.value.length && cuenta.personaId.value !== null
          "
          href="#me-fichas"
          class="me-acceso tu-card tu-card-enlace"
          ><IconoNav nombre="lista" :tam="26" /><span
            ><strong>{{ $t("portal.expediente.formularios") }}</strong
            ><small>{{ $t("portal.expediente.fichasAyuda") }}</small></span
          ></a
        >
      </nav>
      <!-- Por firmar -->
      <div
        v-if="cuenta.waivers.value.length > 0"
        id="me-firmar"
        class="mt-5 tu-card p-5"
        :style="{ borderLeft: '3px solid var(--aviso)' }"
      >
        <h2 class="tu-titulo-seccion">
          {{ $t("portal.expediente.firmar") }}
        </h2>
        <ul class="mt-3 space-y-4">
          <li v-for="w in cuenta.waivers.value" :key="w.id" class="text-sm">
            <p class="font-medium">{{ w.titulo }}</p>
            <p
              class="mt-1 whitespace-pre-line"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ w.contenido }}
            </p>
            <button
              class="tu-btn tu-btn-primario mt-2"
              :disabled="cuenta.accionando.value"
              @click="aceptar(w)"
            >
              {{ $t("miCuenta.aceptarWaiver") }}
            </button>
            <AvisoTono
              v-if="errorWaiver?.id === w.id"
              tono="peligro"
              class="mt-2"
              role="alert"
              data-prueba="error-waiver"
            >
              {{ errorWaiver.mensaje }}
            </AvisoTono>
          </li>
        </ul>
      </div>

      <div
        v-if="cuenta.personaId.value !== null"
        id="me-documentos"
        class="mt-5"
      >
        <MisDocumentos />
      </div>

      <div
        v-if="
          cuenta.formularios.value.length > 0 && cuenta.personaId.value !== null
        "
        id="me-fichas"
        class="mt-5 tu-card p-5"
      >
        <h2 class="tu-titulo-seccion">
          {{ $t("portal.expediente.formularios") }}
        </h2>
        <ListaFormularios
          class="mt-2"
          :formularios="cuenta.formularios.value"
          :persona-id="cuenta.personaId.value"
          :puede-responder="true"
          en-cuenta
          @guardado="cuenta.cargarFormularios()"
        />
      </div>
      <div
        v-if="!cuenta.waivers.value.length && cuenta.personaId.value === null"
        class="me-vacio tu-card"
      >
        <IconoNav nombre="expediente" :tam="36" />
        <p>{{ $t("portal.expediente.vacio") }}</p>
      </div>
    </template>
  </section>
</template>

<style scoped>
.me-accesos {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0.85rem;
  margin-top: 1.25rem;
}
.me-acceso {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 1rem;
  text-decoration: none;
}
.me-acceso > svg {
  flex-shrink: 0;
  color: var(--primario);
}
.me-acceso strong {
  display: block;
  font-size: 0.95rem;
}
.me-acceso small {
  display: block;
  margin-top: 0.2rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.me-vacio {
  display: grid;
  justify-items: center;
  gap: 1rem;
  margin-top: 1.5rem;
  padding: 2rem;
  color: var(--texto-suave);
}
/* En el teléfono, los accesos en dos columnas y sin descripción (como en Pagos). */
@media (max-width: 639px) {
  .me-accesos {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem;
  }
  .me-acceso {
    gap: 0.6rem;
    padding: 0.75rem;
  }
  .me-acceso strong {
    font-size: 0.875rem;
  }
  /* Lo que pide atención (cuántos por firmar) sí se queda. */
  .me-acceso small:not(.me-estado) {
    display: none;
  }
}
#me-firmar,
#me-documentos,
#me-fichas {
  scroll-margin-top: 6rem;
}
</style>
