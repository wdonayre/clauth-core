<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** TOTP + recovery codes. */
class TwoFactor
{
    public static function engine(): Google2FA
    {
        return new Google2FA;
    }

    public static function newSecret(): string
    {
        return self::engine()->generateSecretKey(32);
    }

    public static function qrSvg(User $user, string $secret): string
    {
        $uri = self::engine()->getQRCodeUrl('Clauth', $user->email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(184, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /** Checks a TOTP code once (a code can't be replayed inside its window). */
    public static function verify(string $secret, string $code, ?string $userId = null): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        $ok = self::engine()->verifyKey($secret, $code, 1);
        if ($ok && $userId) {
            $k = "totp-used:$userId:$code";
            if (Cache::has($k)) {
                return false;
            }
            Cache::put($k, 1, 120);
        }

        return $ok;
    }

    public static function recoveryCodes(): array
    {
        return collect(range(1, 10))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
    }

    public static function useRecoveryCode(User $user, string $code): bool
    {
        $code = Str::lower(trim($code));
        $codes = $user->two_factor_recovery_codes ?? [];
        $i = array_search($code, $codes, true);
        if ($i === false) {
            return false;
        }
        unset($codes[$i]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }
}
