import 'package:flutter/material.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_models.dart';

/// «Ver horarios de» al agendar una cita, igual que en la web: todo el equipo o
/// alguien específico, elegido por su foto (la lupa la muestra en grande) por si no
/// recuerdan su nombre. `seleccionado` null = todo el equipo; al elegir «alguien
/// específico» queda el primero.
class ElegirProfesional extends StatelessWidget {
  const ElegirProfesional({
    super.key,
    required this.profesionales,
    required this.seleccionado,
    required this.alCambiar,
  });

  final List<OpcionCita> profesionales;
  final String? seleccionado;
  final ValueChanged<String?> alCambiar;

  /// En su tarjeta, el primer nombre; el completo si dos lo comparten.
  String _nombreTarjeta(OpcionCita p) {
    String primero(String n) => n.trim().split(RegExp(r'\s+')).first;
    final iguales = profesionales
        .where((o) => primero(o.nombre) == primero(p.nombre))
        .length;
    return iguales > 1 ? p.nombre : primero(p.nombre);
  }

  @override
  Widget build(BuildContext context) {
    final conAlguien = seleccionado != null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text(
          'Ver horarios de',
          style: TextStyle(fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 8),
        // Dos tarjetas, lado a lado si caben; en un teléfono, una bajo otra.
        LayoutBuilder(
          builder: (context, espacio) {
            final todos = _OpcionModo(
              icono: Icons.groups_outlined,
              titulo: 'Todo el equipo',
              detalle: 'Cualquier profesional',
              activa: !conAlguien,
              alTocar: () => alCambiar(null),
            );
            final alguien = _OpcionModo(
              icono: Icons.person_outline,
              titulo: 'Elegir a alguien específico',
              detalle: 'Selecciona un profesionista',
              activa: conAlguien,
              alTocar: () {
                if (!conAlguien && profesionales.isNotEmpty) {
                  alCambiar(profesionales.first.id);
                }
              },
            );
            if (espacio.maxWidth < 520) {
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [todos, const SizedBox(height: 10), alguien],
              );
            }
            return Row(
              children: [
                Expanded(child: todos),
                const SizedBox(width: 12),
                Expanded(child: alguien),
              ],
            );
          },
        ),
        if (conAlguien) ...[
          const SizedBox(height: 16),
          const Text(
            'Selecciona un profesionista',
            style: TextStyle(fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 8),
          SizedBox(
            height: 132,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: profesionales.length,
              separatorBuilder: (_, _) => const SizedBox(width: 10),
              itemBuilder: (context, i) {
                final p = profesionales[i];
                return _TarjetaProfesional(
                  profesional: p,
                  nombre: _nombreTarjeta(p),
                  activo: seleccionado == p.id,
                  alTocar: () => alCambiar(p.id),
                );
              },
            ),
          ),
        ],
      ],
    );
  }
}

/// Una de las dos opciones de «Ver horarios de»: su ícono en un círculo, qué es y
/// el círculo de la opción a la derecha.
class _OpcionModo extends StatelessWidget {
  const _OpcionModo({
    required this.icono,
    required this.titulo,
    required this.detalle,
    required this.activa,
    required this.alTocar,
  });

  final IconData icono;
  final String titulo;
  final String detalle;
  final bool activa;
  final VoidCallback alTocar;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      selected: activa,
      button: true,
      label: titulo,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: alTocar,
        child: Container(
          padding: const EdgeInsets.fromLTRB(12, 12, 14, 12),
          decoration: BoxDecoration(
            color: activa
                ? TemaAgendaUno.acentoSuave
                : TemaAgendaUno.superficie,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: activa ? TemaAgendaUno.acento : TemaAgendaUno.borde,
              width: activa ? 2 : 1,
            ),
          ),
          child: Row(
            children: [
              CircleAvatar(
                radius: 22,
                backgroundColor: activa
                    ? TemaAgendaUno.acento.withValues(alpha: 0.12)
                    : TemaAgendaUno.fondo,
                child: Icon(
                  icono,
                  size: 22,
                  color: activa
                      ? TemaAgendaUno.acento
                      : TemaAgendaUno.textoSuave,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      titulo,
                      style: const TextStyle(fontWeight: FontWeight.w500),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      detalle,
                      style: const TextStyle(
                        fontSize: 12.5,
                        color: TemaAgendaUno.textoSuave,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              _CirculoOpcion(activa: activa),
            ],
          ),
        ),
      ),
    );
  }
}

/// El círculo de una opción: vacío o con el punto del acento.
class _CirculoOpcion extends StatelessWidget {
  const _CirculoOpcion({required this.activa});

  final bool activa;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 22,
      height: 22,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: TemaAgendaUno.superficie,
        border: Border.all(
          color: activa ? TemaAgendaUno.acento : TemaAgendaUno.textoSuave,
          width: activa ? 2 : 1.5,
        ),
      ),
      alignment: Alignment.center,
      child: activa
          ? Container(
              width: 11,
              height: 11,
              decoration: const BoxDecoration(
                shape: BoxShape.circle,
                color: TemaAgendaUno.acento,
              ),
            )
          : null,
    );
  }
}

class _TarjetaProfesional extends StatelessWidget {
  const _TarjetaProfesional({
    required this.profesional,
    required this.nombre,
    required this.activo,
    required this.alTocar,
  });

  final OpcionCita profesional;
  final String nombre;
  final bool activo;
  final VoidCallback alTocar;

  @override
  Widget build(BuildContext context) {
    final foto = profesional.fotoUrl;
    return Semantics(
      selected: activo,
      button: true,
      label: profesional.nombre,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: alTocar,
        child: Container(
          width: 112,
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: activo
                ? TemaAgendaUno.acentoSuave
                : TemaAgendaUno.superficie,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: activo ? TemaAgendaUno.acento : TemaAgendaUno.borde,
              width: activo ? 2 : 1,
            ),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Stack(
                clipBehavior: Clip.none,
                children: [
                  CircleAvatar(
                    radius: 32,
                    backgroundColor: TemaAgendaUno.acentoSuave,
                    foregroundImage: foto != null ? NetworkImage(foto) : null,
                    onForegroundImageError: foto != null ? (_, _) {} : null,
                    child: Text(
                      profesional.nombre.trim().isEmpty
                          ? '?'
                          : profesional.nombre.trim()[0].toUpperCase(),
                      style: const TextStyle(
                        color: TemaAgendaUno.acento,
                        fontWeight: FontWeight.w700,
                        fontSize: 20,
                      ),
                    ),
                  ),
                  if (foto != null)
                    Positioned(
                      right: -6,
                      bottom: -6,
                      child: Material(
                        color: TemaAgendaUno.superficie,
                        shape: const CircleBorder(
                          side: BorderSide(color: TemaAgendaUno.borde),
                        ),
                        child: IconButton(
                          tooltip:
                              'Ver la foto de ${profesional.nombre} en grande',
                          visualDensity: VisualDensity.compact,
                          iconSize: 16,
                          constraints: const BoxConstraints.tightFor(
                            width: 32,
                            height: 32,
                          ),
                          padding: EdgeInsets.zero,
                          icon: const Icon(Icons.zoom_in),
                          onPressed: () => _verEnGrande(context, foto),
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                nombre,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// La foto en grande, con el nombre; no elige a la persona.
  void _verEnGrande(BuildContext context, String foto) {
    showDialog<void>(
      context: context,
      builder: (context) => Dialog(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Image.network(
                  foto,
                  fit: BoxFit.cover,
                  errorBuilder: (_, _, _) => const SizedBox(
                    height: 160,
                    child: Center(child: Icon(Icons.person_outline, size: 48)),
                  ),
                ),
              ),
              const SizedBox(height: 10),
              Text(
                profesional.nombre,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              TextButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Cerrar'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
