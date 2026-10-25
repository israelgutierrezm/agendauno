<script setup lang="ts">
import { RouterLink } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import { PRODUCTOS, productoActual } from "@/lib/producto";

/**
 * Cómo eliminar tu cuenta (ADR 0211): la página pública que piden las tiendas (Google
 * Play la enlaza en la ficha de la app). La cuenta se elimina desde la app o la web,
 * en Mi perfil; aquí se explica qué pasa con los datos.
 */
const datos = PRODUCTOS[productoActual()];
</script>

<template>
  <article class="eliminar-cuenta" aria-labelledby="eliminar-cuenta-titulo">
    <RouterLink to="/" class="tu-enlace text-sm"
      ><IconoNav nombre="atras" :tam="14" class="inline align-[-0.15em]" />
      {{ datos.nombre }}</RouterLink
    >
    <h1 id="eliminar-cuenta-titulo">Eliminar tu cuenta</h1>
    <p>
      Tu cuenta es la del negocio al que entras con {{ datos.nombre }}: la de tu
      estudio, tu barbería o tu consultorio. La eliminas tú, cuando quieras.
    </p>

    <h2>Cómo hacerlo</h2>
    <ol>
      <li>
        Entra a tu cuenta en la app o en la web (<RouterLink
          to="/entrar"
          class="tu-enlace"
          >Iniciar sesión</RouterLink
        >).
      </li>
      <li>Abre <strong>Mi perfil</strong>.</li>
      <li>
        Elige <strong>Eliminar mi cuenta</strong> y confírmalo con tu
        contraseña. Si entras solo con Google, primero crea una contraseña con
        «¿Olvidaste tu contraseña?».
      </li>
    </ol>

    <h2>Qué pasa con tus datos</h2>
    <ul>
      <li>
        Tu cuenta deja de funcionar en ese momento y se cierran tus sesiones.
      </li>
      <li>
        Si eres cliente del negocio, queda pedida la baja de tus datos: el
        negocio borra tu nombre, tu correo, tu celular, tus documentos, tus
        formularios y tus mensajes en el plazo que marca la ley. Solo conserva,
        sin datos que te identifiquen, lo que la ley lo obliga a guardar (pagos
        y facturas).
      </li>
      <li>
        Si eres el dueño del negocio, primero das de baja el negocio desde Mi
        suscripción; después puedes eliminar tu cuenta.
      </li>
    </ul>

    <p>
      ¿Dudas o no puedes entrar? Escríbenos a
      <a class="tu-enlace" :href="`mailto:${datos.soporte.correo}`">{{
        datos.soporte.correo
      }}</a
      >.
    </p>
  </article>
</template>

<style scoped>
.eliminar-cuenta {
  display: grid;
  gap: 0.9rem;
  max-width: 44rem;
  margin: 0 auto;
  padding: clamp(2rem, 6vw, 4rem) 1.25rem;
  line-height: 1.7;
}
.eliminar-cuenta h1 {
  font-size: clamp(1.7rem, 3vw, 2.2rem);
  font-weight: 500;
  letter-spacing: -0.02em;
}
.eliminar-cuenta h2 {
  margin-top: 0.75rem;
  font-size: 1.15rem;
  font-weight: 600;
}
.eliminar-cuenta ol,
.eliminar-cuenta ul {
  display: grid;
  gap: 0.4rem;
  padding-left: 1.25rem;
}
.eliminar-cuenta ol {
  list-style: decimal;
}
.eliminar-cuenta ul {
  list-style: disc;
}
</style>
