<script setup lang="ts">
/*
| Ejemplo de la página pública de un estudio de clases (/clases). Datos ficticios y
| estáticos, sin API ni stores: se prerenderiza. Fiel al producto: muestra los
| lugares libres de cada clase y termina en «Pedir acceso / Ya soy alumno», porque
| las cuentas las da el negocio (registro cerrado, ADR 0093). Los rótulos van en
| `landing.clases.pagina.demo.*`; los datos ficticios (estudio, clases, horas), aquí.
*/
const CLASES = [
  {
    dia: "Lun",
    hora: "10:30",
    clase: "Pilates Reformer",
    instructor: "con Andrea",
    libres: 3,
  },
  {
    dia: "Mar",
    hora: "13:00",
    clase: "Pole dance básico",
    instructor: "con Sofía",
    libres: 0,
  },
  {
    dia: "Mié",
    hora: "17:30",
    clase: "Yoga flow",
    instructor: "con Elena",
    libres: 6,
  },
] as const;
</script>

<template>
  <div class="demo-clases" aria-hidden="true">
    <div class="demo-clases-cabecera">
      <span class="demo-clases-logo">IS</span>
      <div class="min-w-0">
        <p class="demo-clases-nombre">Impulso Studio</p>
        <p class="demo-clases-sede">Juárez · Ciudad de México</p>
      </div>
      <span class="tu-badge tu-badge-exito ml-auto">{{
        $t("landing.clases.pagina.demo.abierta")
      }}</span>
    </div>
    <div class="demo-clases-contenido">
      <div class="flex items-center justify-between gap-4">
        <p class="demo-clases-titulo">{{ $t("escaparate.proximasClases") }}</p>
        <span class="demo-clases-suave">{{
          $t("landing.clases.pagina.demo.semana")
        }}</span>
      </div>
      <ul class="mt-4 space-y-3" role="list">
        <li v-for="c in CLASES" :key="c.clase" class="demo-clase">
          <div class="demo-clase-fecha">
            <strong>{{ c.dia }}</strong>
            <span>{{ c.hora }}</span>
          </div>
          <div class="min-w-0">
            <p class="demo-clase-nombre">{{ c.clase }}</p>
            <p class="demo-clases-suave truncate">{{ c.instructor }}</p>
          </div>
          <span
            class="demo-clase-cupo"
            :class="
              c.libres === 0 ? 'tu-badge tu-badge-aviso' : 'demo-clases-suave'
            "
            >{{
              c.libres === 0
                ? $t("landing.clases.pagina.demo.lleno")
                : $t("landing.clases.pagina.demo.lugares", c.libres)
            }}</span
          >
        </li>
      </ul>
      <div class="demo-clases-acciones">
        <span class="demo-clases-boton demo-clases-boton--primario">{{
          $t("escaparate.pedirAcceso")
        }}</span>
        <span class="demo-clases-boton">{{
          $t("escaparate.yaSoyAlumno")
        }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.demo-clases {
  overflow: hidden;
  border-radius: var(--radio-panel, 28px);
  background: var(--fondo);
}
.demo-clases-cabecera {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 1.5rem;
  background: color-mix(in srgb, var(--acento) 8%, var(--superficie));
}
.demo-clases-logo {
  display: inline-flex;
  height: 3.25rem;
  width: 3.25rem;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  border-radius: 1rem;
  background: #031b4e;
  color: #fff;
  font-size: 0.85rem;
  font-weight: 700;
}
.demo-clases-nombre {
  font-size: 1.1rem;
  font-weight: 500;
}
.demo-clases-sede,
.demo-clases-suave {
  color: var(--texto-suave);
  font-size: 0.875rem;
}
.demo-clases-contenido {
  padding: 1.5rem;
}
.demo-clases-titulo {
  font-weight: 500;
}
.demo-clase {
  display: grid;
  grid-template-columns: 3.4rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.9rem;
  padding: 0.9rem;
  border-radius: 1.15rem;
  background: var(--superficie);
}
.demo-clase-fecha {
  display: flex;
  flex-direction: column;
  color: var(--texto);
  font-size: 0.78rem;
  line-height: 1.25;
}
.demo-clase-fecha strong {
  font-weight: 600;
}
.demo-clase-nombre {
  overflow: hidden;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.demo-clase-cupo {
  white-space: nowrap;
}
.demo-clases-acciones {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.6rem;
  margin-top: 1.25rem;
}
.demo-clases-boton {
  display: flex;
  min-height: 2.75rem;
  align-items: center;
  justify-content: center;
  padding-inline: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton, 11px);
  background: var(--superficie);
  color: var(--texto);
  font-size: 0.875rem;
  font-weight: 500;
  text-align: center;
}
.demo-clases-boton--primario {
  border-color: transparent;
  background: var(--primario);
  color: var(--primario-contraste, #fff);
}
@media (max-width: 639px) {
  .demo-clases-cabecera,
  .demo-clases-contenido {
    padding: 1.1rem;
  }
  .demo-clase {
    grid-template-columns: 3.1rem minmax(0, 1fr) auto;
    gap: 0.6rem;
  }
  .demo-clases-acciones {
    grid-template-columns: 1fr;
  }
}
</style>
