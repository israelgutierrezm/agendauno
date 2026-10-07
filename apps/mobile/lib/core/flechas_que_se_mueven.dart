import 'package:flutter/material.dart';

/// El ícono de «Cambiar de rol» con sus flechas que van y vienen, siempre: avisa que
/// se puede entrar con otro rol (como en la web). Con las animaciones del sistema
/// apagadas, quietas.
class FlechasQueSeMueven extends StatefulWidget {
  const FlechasQueSeMueven({super.key, this.tam = 24, this.color});

  final double tam;
  final Color? color;

  @override
  State<FlechasQueSeMueven> createState() => _FlechasQueSeMuevenState();
}

class _FlechasQueSeMuevenState extends State<FlechasQueSeMueven>
    with SingleTickerProviderStateMixin {
  late final AnimationController _vaiven = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 2400),
  );

  // Se separan en el primer cuarto, vuelven hacia la mitad y descansan el resto.
  late final Animation<double> _salto = TweenSequence<double>([
    TweenSequenceItem(
      tween: Tween(
        begin: 0.0,
        end: 1.0,
      ).chain(CurveTween(curve: Curves.easeInOut)),
      weight: 25,
    ),
    TweenSequenceItem(
      tween: Tween(
        begin: 1.0,
        end: 0.0,
      ).chain(CurveTween(curve: Curves.easeInOut)),
      weight: 30,
    ),
    TweenSequenceItem(tween: ConstantTween(0.0), weight: 45),
  ]).animate(_vaiven);

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.of(context).disableAnimations) {
      _vaiven.stop();
      _vaiven.value = 0;
    } else if (!_vaiven.isAnimating) {
      _vaiven.repeat();
    }
  }

  @override
  void dispose() {
    _vaiven.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final color = widget.color ?? IconTheme.of(context).color;
    final flecha = widget.tam * 0.62;
    final desplazamiento = widget.tam * 0.12;
    return SizedBox(
      width: widget.tam,
      height: widget.tam,
      child: AnimatedBuilder(
        animation: _salto,
        builder: (context, _) => Stack(
          children: [
            Align(
              alignment: Alignment.topCenter,
              child: Transform.translate(
                offset: Offset(-desplazamiento * _salto.value, 0),
                child: Icon(Icons.west, size: flecha, color: color),
              ),
            ),
            Align(
              alignment: Alignment.bottomCenter,
              child: Transform.translate(
                offset: Offset(desplazamiento * _salto.value, 0),
                child: Icon(Icons.east, size: flecha, color: color),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
