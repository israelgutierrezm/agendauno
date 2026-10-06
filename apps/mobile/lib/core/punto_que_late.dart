import 'package:flutter/material.dart';

import 'theme/tema_agendauno.dart';

/// Un punto pequeño con un halo que late, siempre: avisa que hay algo que hacer ahí
/// (p. ej. «Cambiar de rol» cuando se puede entrar con otro rol). Con las animaciones
/// del sistema apagadas, solo el punto.
class PuntoQueLate extends StatefulWidget {
  const PuntoQueLate({
    super.key,
    this.color = TemaAgendaUno.acento,
    this.tam = 8,
  });

  final Color color;
  final double tam;

  @override
  State<PuntoQueLate> createState() => _PuntoQueLateState();
}

class _PuntoQueLateState extends State<PuntoQueLate>
    with SingleTickerProviderStateMixin {
  late final AnimationController _latido = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1800),
  );

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.of(context).disableAnimations) {
      _latido.stop();
    } else if (!_latido.isAnimating) {
      _latido.repeat();
    }
  }

  @override
  void dispose() {
    _latido.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final punto = Container(
      width: widget.tam,
      height: widget.tam,
      decoration: BoxDecoration(color: widget.color, shape: BoxShape.circle),
    );
    return SizedBox(
      width: widget.tam * 2.6,
      height: widget.tam * 2.6,
      child: Stack(
        alignment: Alignment.center,
        children: [
          // El halo crece y se desvanece (como el de la web).
          AnimatedBuilder(
            animation: _latido,
            builder: (context, _) {
              final t = Curves.easeOut.transform(
                (_latido.value / 0.8).clamp(0.0, 1.0),
              );
              return Opacity(
                opacity: _latido.isAnimating ? 0.6 * (1 - t) : 0,
                child: Transform.scale(scale: 1 + 1.6 * t, child: punto),
              );
            },
          ),
          punto,
        ],
      ),
    );
  }
}
