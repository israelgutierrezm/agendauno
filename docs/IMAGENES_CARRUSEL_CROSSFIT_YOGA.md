# Carrusel: CrossFit y HYROX por separado

Fecha: 2026-10-03.

- Carrusel: 17 categorías; CrossFit y HYROX tienen fotografía y descripción propias.
- Hero: Barberías, Pole dance y HYROX. La imagen del trineo conserva el archivo `crossfit-hyrox-v1.webp` para no romper otros consumidores.
- Las listas comerciales, precios y la ruta SEO compartida no cambian en esta tarea.
- Yoga usa la variante nueva solo en el carrusel. Las imágenes anteriores permanecen disponibles.

## Orden editorial

Pilates → Pole dance → HYROX → Academias → Acuáticas → CrossFit → Peluquerías y estéticas → Dentistas → Barberías → Psicólogos → Wellness → Nutriólogos → Spas → Gimnasios → Terapeutas → Danza → Yoga → vuelve a Pilates.

Composición revisada según los protagonistas de las ilustraciones fotográficas: bloques de dos figuras femeninas y una masculina, cerrando con Danza y Yoga. Así no se acumulan tres protagonistas del mismo género aparente, tampoco al reiniciar el ciclo. Es una decisión editorial sobre imágenes generadas, no una clasificación de usuarios ni una restricción de los negocios. Al sustituir fotografías, revisar de nuevo el ciclo y la prueba de orden en `LandingView.spec.ts`.

## Recursos generados

Herramienta integrada image_gen. Exportación a WebP, calidad 84, sin recorte. Imágenes revisadas antes de integrarlas.

### CrossFit

Archivo: `apps/web/public/assets/landing/disciplinas/crossfit-v1.webp`.

Prompt:

```text
Use case: photorealistic-natural. Asset type: portrait editorial sports photo for a business scheduling website carousel, CrossFit category. In a bright industrial functional training gym, an athletic adult man in a plain charcoal training t-shirt and dark shorts performing a controlled front squat with a barbell held in the front rack position at shoulder level. Clear believable front-rack grip and anatomy, bar resting across front shoulders, elbows raised, knees tracking over shoes, three-quarter front view, subject's face and torso centered and clearly visible. Background softly shows pull-up rig and wooden plyometric boxes, rubber floor and daylight windows. Premium candid sports photography with realistic skin and cloth, warm daylight, neutral muted colors, vertical 4:5 composition designed for a narrow portrait card crop. No sled, no readable text, no brands, no logos, no watermark, no extreme bodybuilder physique. This should read as actual functional group-fitness training, not a posed portrait.
```

### Yoga

Archivo: `apps/web/public/assets/landing/disciplinas/yoga-v2.webp`.

Prompt:

```text
Use case: photorealistic-natural. Asset type: portrait editorial wellness photo for the Yoga category in a Mexican scheduling website carousel. Adult male yoga practitioner around 35, short dark hair with a relaxed expression, plain sage t-shirt and soft charcoal yoga trousers, performing a stable tree pose on a yoga mat in a bright contemporary yoga studio with pale wood floor, cream walls, softly lit windows and a subtle plant. Full body visible, hands together gently at chest, one foot resting safely on inner calf below the knee, realistic balanced posture, natural anatomy. Front three-quarter view, face and torso centrally framed with sufficient safe margins for narrow portrait card crops, soft daylight, premium warm candid editorial photography, realistic fabric and skin texture. Vertical 4:5 composition. No text, logos, watermark, food or fitness machines.
```

