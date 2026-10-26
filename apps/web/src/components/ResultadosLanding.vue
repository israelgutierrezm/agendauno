<script setup lang="ts">
import type { Modo } from "@/marketing/modalidades";

/**
 * La landing ordenada por resultados (comparativo del 2026-10-20): atraer, convertir,
 * retener y operar. Cada bloque abre con la pregunta del dueño y cinco cosas que ya
 * hace el sistema (nada que no exista), con lo nuevo de su modalidad: Wellhub,
 * TotalPass y Fitpass, widgets, Wallet, bandeja, kiosco y apartados en clases;
 * anticipo, presupuestos, factura automática, «Agendar cita» y calendarios en citas.
 * Lo que solo aplica en México lleva «*» (la nota la pone la página).
 */
defineProps<{ modo: Modo }>();

interface Bloque {
  clave: string;
  titulo: string;
  pregunta: string;
  beneficios: string[];
}

const BLOQUES: Record<Modo, Bloque[]> = {
  clases: [
    {
      clave: "atraer",
      titulo: "Atraer",
      pregunta: "¿Cómo consigo más alumnos sin vivir en Instagram?",
      beneficios: [
        "Tu sitio con SEO y, si quieres, tu propio dominio",
        "Tu calendario y tus planes dentro de tu propia web",
        "Wellhub, TotalPass y Fitpass* en tu misma agenda",
        "Clase muestra para quien viene por primera vez",
        "Formularios y prospectos con seguimiento",
      ],
    },
    {
      clave: "convertir",
      titulo: "Convertir",
      pregunta: "¿Cómo hago que quien vino una vez se quede?",
      beneficios: [
        "Paquetes, membresías y cobro automático*",
        "Pago en línea con tu pasarela: tarjeta u OXXO*",
        "WhatsApp e Instagram en una sola bandeja",
        "Antes de reservar ve tu política y su plan",
        "Planes «socio» con acceso anticipado e invitados",
      ],
    },
    {
      clave: "retener",
      titulo: "Retener",
      pregunta: "¿Cómo sé quién está por irse antes de que se vaya?",
      beneficios: [
        "Avisos de quien dejó de venir y de planes por vencer",
        "Su pase en Apple Wallet y Google Wallet, siempre al día",
        "Lealtad, cupones y promociones",
        "Recordatorios automáticos de cada clase",
        "Videos y clases en línea para tus socios",
      ],
    },
    {
      clave: "operar",
      titulo: "Operar",
      pregunta: "¿Cómo dejo de cuadrar todo en hojas de cálculo?",
      beneficios: [
        "Caja con arqueo, cobro mixto y devoluciones",
        "Facturas CFDI a tus alumnos*",
        "Pago a coaches con cortes cerrados",
        "Kiosco de llegada y pase de lista desde el celular",
        "Productos apartados al reservar, listos en el mostrador",
      ],
    },
  ],
  citas: [
    {
      clave: "atraer",
      titulo: "Atraer",
      pregunta: "¿Cómo lleno mi agenda sin contestar mensajes todo el día?",
      beneficios: [
        "Tu página para agendar en línea, sin crear cuenta",
        "Tus servicios para agendar, dentro de tu propia web",
        "Sitio con SEO, enlaces para tus redes y tu dominio (Premium)",
        "Formularios y prospectos con seguimiento (Pro)",
        "Cualquier profesional disponible, en un clic",
      ],
    },
    {
      clave: "convertir",
      titulo: "Convertir",
      pregunta: "¿Cómo evito que me dejen plantado?",
      beneficios: [
        "Anticipo o pago completo al agendar*",
        "Presupuestos que se aceptan y pagan en línea*",
        "WhatsApp e Instagram en una sola bandeja (Pro)",
        "Recordatorios automáticos de cada cita",
        "Política de cancelación visible al agendar",
      ],
    },
    {
      clave: "retener",
      titulo: "Retener",
      pregunta: "¿Cómo hago que regresen?",
      beneficios: [
        "Avisos de quien ya debería haber regresado",
        "Bonos de sesiones y paquetes de servicios (Premium)",
        "Cupones y promociones (Premium); lealtad (Pro)",
        "Reseñas de tus clientes con cuenta, en tu página",
        "Historial y notas de cada cliente",
      ],
    },
    {
      clave: "operar",
      titulo: "Operar",
      pregunta: "¿Cómo cuadro la caja y pago comisiones sin pelearme?",
      beneficios: [
        "Caja con arqueo, cobro mixto y devoluciones",
        "Factura de anticipos y citas, automática para quien la pide en su cuenta* (Pro)",
        "Comisiones y nómina por profesional (Premium)",
        "Punto de venta e inventario (Premium)",
        "Google Calendar y Outlook, sin choques de horario (Pro)",
      ],
    },
  ],
};
</script>

<template>
  <div class="resultados" data-prueba="resultados-landing">
    <article
      v-for="b in BLOQUES[modo]"
      :key="b.clave"
      class="resultado"
      :data-resultado="b.clave"
    >
      <p class="resultado-etiqueta">{{ b.titulo }}</p>
      <h3>{{ b.pregunta }}</h3>
      <ul>
        <li v-for="x in b.beneficios" :key="x">{{ x }}</li>
      </ul>
    </article>
  </div>
</template>

<style scoped>
.resultados {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(16rem, 100%), 1fr));
  gap: 1rem;
}
.resultado {
  padding: 1.25rem;
  border: 1px solid var(--borde);
  border-radius: 16px;
  background: var(--superficie);
}
.resultado-etiqueta {
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--primario);
}
.resultado h3 {
  margin-top: 0.35rem;
  font-weight: 600;
  font-size: 1.05rem;
  line-height: 1.3;
}
.resultado ul {
  margin-top: 0.75rem;
  display: grid;
  gap: 0.4rem;
  padding-left: 1.1rem;
  list-style: disc;
  font-size: 0.95rem;
}
</style>
