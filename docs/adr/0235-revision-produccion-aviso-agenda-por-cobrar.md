# ADR 0235 — Aviso antes de pedir datos, fechas de la agenda y búsqueda en Por cobrar

Estado: Aceptado (2026-11-07). Completa la ADR 0234 (L3 y L4), la 0232 (registros de nginx) y
la 0229 (regla 5, errores junto al formulario).

## Contexto

Una revisión para producción encontró siete huecos: un negocio de citas salía «listo» sin aviso
de privacidad aunque en producción el agendado sin cuenta lo rechazaba al confirmar; la versión
del aviso era opcional al agendar; una tabla de una sola banda sin tope no detectaba una subida
de tarifa; nginx no tachaba el token de la descarga `.ics` de una sola cita; `GET /sesiones` con
una fecha imposible daba 500; tres formularios mostraban su error solo en un aviso flotante, y
«Pendientes de pago» no se podía buscar.

## Decisión

1. **Un solo criterio para pedir datos**: `WaiversTenant::puedePedirDatos()` (aviso publicado, o
   fuera de producción). Lo usan `PuestaEnMarchaTenant` (en citas, `reservable` y `listo` lo
   exigen; motivo `sin_aviso`; comprobación `aviso` hacia Documentos), `GET /citas/opciones`
   (`estudio.recibe_citas`) y `POST /citas`. La página para agendar dice que las citas aún no están
   abiertas antes de pedir nada. Publicar el aviso habilita; retirarlo vuelve a cerrar.
2. **La versión del aviso es obligatoria** al agendar sin cuenta cuando hay aviso publicado: falta
   o desactualizada → 422 entendible en `aviso_version`; la web la manda, muestra el error junto
   al formulario y toma la versión vigente para el reintento. *Compatibilidad*: un cliente viejo
   que no la mande recibe 422 en vez de que se le atribuya en silencio la vigente; la web actual
   siempre la manda y la app no agenda sin cuenta. La captación (`CaptacionTenant`) no comparte este
   código y se deja como estaba.
3. **Subidas de tarifa**: `SubidaDeTarifa` compara también desde el primer alumno, así una tabla
   de una sola banda sin tope (o una banda abierta) se compara.
4. **nginx** tacha `/calendario/{token}.ics` y `/calendario/{token}/{evento}.ics` con la misma
   regla. *Para el dueño*: si ya se desplegó la regla anterior, los registros de acceso guardaron
   tokens de descargas de una sola cita: conviene rotar los enlaces de calendario (cada persona
   genera uno nuevo en Mi perfil) y borrar o restringir los registros existentes.
5. **Fechas de la agenda**: `GET /sesiones` valida `desde`/`hasta` con `date_format:Y-m-d`,
   `hasta ≥ desde` y una ventana de 366 días (tope técnico; el de filas sigue siendo `LIMITE`),
   422 por campo. La conversión a UTC con un día de margen no cambia.
6. **Errores junto al formulario** en Mi perfil (datos, correo, contraseña), Reglas de agenda
   (reglas, excepción, día cerrado) y Corregir cobro: `AvisoTono role="alert"` dentro del
   formulario, enfocado, con los errores de campo junto a su campo y sin perder lo escrito. El
   éxito sigue en el aviso flotante. Se quitan de los pendientes de la ADR 0229.
7. **Por cobrar** busca del lado del servidor (`q`: persona, folio, referencia o concepto) y filtra
   por `tipo` y fechas, con el alcance por sucursal de siempre; el resumen (cuánto se debe) no
   depende de la página ni de los filtros. La web suma buscador con espera, tipo, rango de fechas y
   «Limpiar». La ficha de la persona ya mostraba sus adeudos.

## Consecuencias

- En producción, un negocio de citas sin aviso no aparece listo ni recibe citas en línea, y lo
  sabe en «Pon tu negocio en marcha».
- Clientes externos que agenden por API sin `aviso_version` deben mandarla.
