import 'package:flutter/material.dart';

/// Las apps de calendario que se nombran al agregar un evento.
enum MarcaCalendario { google, apple, outlook }

/// Miniatura de una app de calendario (Google Calendar, Apple Calendar u Outlook)
/// para reconocerla de un vistazo junto a su nombre: formas propias y sencillas con
/// sus colores, no el logotipo oficial (las mismas que en la web). Es decorativa.
class LogoCalendario extends StatelessWidget {
  const LogoCalendario(this.marca, {super.key, this.tam = 22});

  final MarcaCalendario marca;
  final double tam;

  @override
  Widget build(BuildContext context) => ExcludeSemantics(
    child: SizedBox.square(
      dimension: tam,
      child: CustomPaint(painter: _Pintor(marca)),
    ),
  );
}

class _Pintor extends CustomPainter {
  _Pintor(this.marca);

  final MarcaCalendario marca;

  @override
  void paint(Canvas canvas, Size size) {
    // Todo se dibuja en una cuadrícula de 24 × 24, como el SVG de la web.
    canvas.scale(size.width / 24, size.height / 24);
    switch (marca) {
      case MarcaCalendario.google:
        _google(canvas);
      case MarcaCalendario.apple:
        _apple(canvas);
      case MarcaCalendario.outlook:
        _outlook(canvas);
    }
  }

  static Paint _relleno(int color) => Paint()..color = Color(color);

  static void _texto(
    Canvas canvas,
    String texto,
    Offset centro,
    double tam,
    int color,
    FontWeight peso,
  ) {
    final pintor = TextPainter(
      text: TextSpan(
        text: texto,
        style: TextStyle(
          fontSize: tam,
          fontWeight: peso,
          color: Color(color),
          height: 1,
        ),
      ),
      textDirection: TextDirection.ltr,
    )..layout();
    pintor.paint(canvas, centro - Offset(pintor.width / 2, pintor.height / 2));
  }

  void _google(Canvas canvas) {
    final hoja = RRect.fromRectAndRadius(
      const Rect.fromLTWH(2.5, 2.5, 19, 19),
      const Radius.circular(3.5),
    );
    canvas.drawRRect(hoja, _relleno(0xFFFFFFFF));
    canvas.save();
    canvas.clipRRect(hoja);
    canvas.drawRect(const Rect.fromLTWH(2.5, 2.5, 19, 5), _relleno(0xFF4285F4));
    canvas.drawRect(
      const Rect.fromLTWH(17, 7.5, 4.5, 8.5),
      _relleno(0xFFFBBC04),
    );
    canvas.drawRect(
      const Rect.fromLTWH(2.5, 16, 4.5, 5.5),
      _relleno(0xFF34A853),
    );
    canvas.drawPath(
      Path()
        ..moveTo(17, 21.5)
        ..lineTo(17, 16)
        ..lineTo(21.5, 16)
        ..close(),
      _relleno(0xFFEA4335),
    );
    canvas.restore();
    _texto(
      canvas,
      '31',
      const Offset(11.75, 14.2),
      7.5,
      0xFF1A73E8,
      FontWeight.w700,
    );
    canvas.drawRRect(
      hoja,
      Paint()
        ..color = const Color(0xFFDADCE0)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 0.8,
    );
  }

  void _apple(Canvas canvas) {
    final hoja = RRect.fromRectAndRadius(
      const Rect.fromLTWH(2.5, 2.5, 19, 19),
      const Radius.circular(4.5),
    );
    canvas.drawRRect(hoja, _relleno(0xFFFFFFFF));
    canvas.drawRRect(
      hoja,
      Paint()
        ..color = const Color(0xFFDADCE0)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 0.8,
    );
    _texto(
      canvas,
      'LUN',
      const Offset(12, 7.4),
      4.6,
      0xFFFF3B30,
      FontWeight.w600,
    );
    _texto(
      canvas,
      '17',
      const Offset(12, 15.2),
      9.5,
      0xFF1C1C1E,
      FontWeight.w400,
    );
  }

  void _outlook(Canvas canvas) {
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        const Rect.fromLTWH(8, 4, 13.5, 16),
        const Radius.circular(2),
      ),
      _relleno(0xFF28A8EA),
    );
    canvas.drawPath(
      Path()
        ..moveTo(8, 9.5)
        ..lineTo(14.75, 13.75)
        ..lineTo(21.5, 9.5)
        ..lineTo(21.5, 18)
        ..arcToPoint(const Offset(19.5, 20), radius: const Radius.circular(2))
        ..lineTo(10, 20)
        ..arcToPoint(const Offset(8, 18), radius: const Radius.circular(2))
        ..close(),
      _relleno(0xFF0078D4),
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        const Rect.fromLTWH(2.5, 6.5, 11, 11),
        const Radius.circular(1.8),
      ),
      _relleno(0xFF0F5BAA),
    );
    canvas.drawOval(
      Rect.fromCenter(center: const Offset(8, 12), width: 5.8, height: 6.6),
      Paint()
        ..color = const Color(0xFFFFFFFF)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.6,
    );
  }

  @override
  bool shouldRepaint(_Pintor anterior) => anterior.marca != marca;
}
