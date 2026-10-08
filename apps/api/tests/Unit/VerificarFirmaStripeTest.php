<?php

declare(strict_types=1);

use App\Modules\Tenancy\Pasarelas\Stripe\VerificarFirmaStripe;
use Illuminate\Support\Carbon;

/*
| Firma de los avisos de Stripe: vale con cualquiera de sus `v1` (rotación del
| secreto) y solo si se firmó hace menos de 5 minutos (no se repite uno viejo).
*/

function firmaDeStripe(string $cuerpo, string $secreto, int $marca): string
{
    return hash_hmac('sha256', $marca.'.'.$cuerpo, $secreto);
}

it('acepta la firma vigente, también entre varias v1', function (): void {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
    $cuerpo = '{"type":"checkout.session.completed"}';
    $marca = 1_800_000_000 - 60;
    $buena = firmaDeStripe($cuerpo, 'whsec_nuevo', $marca);

    expect(VerificarFirmaStripe::valida($cuerpo, "t={$marca},v1={$buena}", 'whsec_nuevo'))->toBeTrue()
        ->and(VerificarFirmaStripe::valida($cuerpo, "t={$marca},v1=otra,v1={$buena}", 'whsec_nuevo'))->toBeTrue()
        ->and(VerificarFirmaStripe::valida($cuerpo, "t={$marca},v1={$buena}", 'whsec_viejo'))->toBeFalse();
    Carbon::setTestNow();
});

it('rechaza un aviso firmado hace más de 5 minutos', function (): void {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
    $cuerpo = '{"type":"checkout.session.completed"}';
    $marca = 1_800_000_000 - VerificarFirmaStripe::TOLERANCIA - 1;

    expect(VerificarFirmaStripe::valida($cuerpo, 't='.$marca.',v1='.firmaDeStripe($cuerpo, 'whsec_x', $marca), 'whsec_x'))->toBeFalse()
        ->and(VerificarFirmaStripe::valida($cuerpo, 't=abc,v1=x', 'whsec_x'))->toBeFalse()
        ->and(VerificarFirmaStripe::valida($cuerpo, null, 'whsec_x'))->toBeFalse();
    Carbon::setTestNow();
});
