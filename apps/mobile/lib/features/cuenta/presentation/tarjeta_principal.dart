import 'package:flutter/material.dart';

import '../../../core/config/app_config.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_models.dart';

/// La foto que acompaña al negocio según su giro: las mismas del sitio web (no se
/// copian a la app), `public/assets/landing/disciplinas`.
String fotoNegocio(String? perfil) {
  const fotos = {
    'barberia': 'barberia-v1.jpg',
    'estetica': 'estetica-v1.jpg',
    'salon': 'estetica-v1.jpg',
    'spa': 'spa-v1.webp',
    'salud': 'consultorios-v1.webp',
    'pilates': 'pilates-v1.jpg',
    'pole': 'pole-v1.jpg',
    'yoga': 'yoga-v1.jpg',
    'danza': 'danza-v1.jpg',
    'gimnasio': 'gimnasio-v1.jpg',
    'crossfit': 'crossfit-v1.webp',
    'hyrox': 'crossfit-hyrox-v1.webp',
    'natacion': 'natacion-v1.jpg',
    'academia': 'academias-v1.jpg',
  };
  final archivo = fotos[perfil] ?? 'wellness-v1.webp';
  return '${AppConfig.webBaseUrl}/assets/landing/disciplinas/$archivo';
}

/// Dibujo del clima por la clave que manda la API; de noche, el sol es luna.
IconData iconoClima(String icono, {bool deDia = true}) => switch (icono) {
  'despejado' => deDia ? Icons.wb_sunny_outlined : Icons.nightlight_outlined,
  'parcial' => deDia ? Icons.wb_cloudy_outlined : Icons.nightlight_outlined,
  'niebla' => Icons.foggy,
  'llovizna' => Icons.grain_outlined,
  'lluvia' => Icons.water_drop_outlined,
  'nieve' => Icons.ac_unit_outlined,
  'tormenta' => Icons.thunderstorm_outlined,
  _ => Icons.cloud_outlined,
};

/// Tarjeta grande del Inicio: la foto del giro con el clima encima y, debajo, su
/// próxima clase o cita (o la invitación a reservar). Siempre se muestra.
class TarjetaPrincipal extends StatelessWidget {
  const TarjetaPrincipal({
    super.key,
    required this.etiqueta,
    required this.foto,
    required this.clima,
    required this.dondeClima,
    required this.contenido,
  });

  /// "TU PRÓXIMA CLASE".
  final String etiqueta;
  final String foto;
  final ClimaMiembro? clima;
  final String dondeClima;
  final List<Widget> contenido;

  @override
  Widget build(BuildContext context) => Card(
    margin: EdgeInsets.zero,
    clipBehavior: Clip.antiAlias,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(
          height: 170,
          child: Stack(
            fit: StackFit.expand,
            children: [
              // Sin la foto (sin red o en desarrollo), un tinte del color de marca.
              const ColoredBox(color: TemaAgendaUno.acentoSuave),
              Image.network(
                foto,
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) => const SizedBox.shrink(),
              ),
              if (clima != null)
                Positioned(
                  left: 12,
                  right: 12,
                  bottom: 12,
                  child: Align(
                    alignment: Alignment.bottomLeft,
                    child: _Clima(clima: clima!, donde: dondeClima),
                  ),
                ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                etiqueta.toUpperCase(),
                style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 0.9,
                  color: TemaAgendaUno.acento,
                ),
              ),
              ...contenido,
            ],
          ),
        ),
      ],
    ),
  );
}

/// El clima sobre la foto: fondo oscuro translúcido para leerse sobre cualquier foto.
class _Clima extends StatelessWidget {
  const _Clima({required this.clima, required this.donde});

  final ClimaMiembro clima;
  final String donde;

  @override
  Widget build(BuildContext context) {
    final lluvia = clima.lluvia;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: const Color(0xFF0F172A).withValues(alpha: 0.6),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            iconoClima(clima.icono, deDia: clima.esDeDia),
            color: Colors.white,
            size: 26,
          ),
          const SizedBox(width: 10),
          Flexible(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(
                        text: '${clima.temperatura}°',
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      TextSpan(text: '  ${clima.condicion}'),
                      if (lluvia != null && lluvia >= 20)
                        TextSpan(text: ' · $lluvia % de lluvia'),
                    ],
                  ),
                  style: const TextStyle(color: Colors.white, fontSize: 13),
                ),
                if (donde.isNotEmpty)
                  Text(
                    donde,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: Colors.white70, fontSize: 12),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
