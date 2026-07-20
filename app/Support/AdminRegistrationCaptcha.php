<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AdminRegistrationCaptcha
{
    public const SESSION_KEY = 'admin_registration_captcha';

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function issue(Request $request): string
    {
        $code = $this->randomCode();
        $request->session()->put(self::SESSION_KEY, $code);

        return $code;
    }

    public function validate(Request $request, string $input): bool
    {
        $expected = (string) $request->session()->pull(self::SESSION_KEY, '');
        $actual = Str::upper(trim($input));

        return $expected !== '' && hash_equals($expected, $actual);
    }

    public function renderSvg(string $code): string
    {
        $safeCode = e($code);
        $noise = '';
        for ($i = 0; $i < 7; $i++) {
            $x1 = 8 + ($i * 17);
            $y1 = 16 + (($i * 11) % 24);
            $x2 = 20 + ($i * 15);
            $y2 = 42 - (($i * 7) % 22);
            $noise .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#cbd5e1" stroke-width="1" opacity="0.75"/>',
                $x1,
                $y1,
                $x2,
                $y2
            );
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="128" height="48" viewBox="0 0 128 48" role="img" aria-label="captcha">
  <rect width="128" height="48" rx="8" fill="#f8fafc"/>
  {$noise}
  <text x="64" y="31" text-anchor="middle" font-family="Arial, sans-serif" font-size="24" font-weight="700" letter-spacing="4" fill="#1e293b">{$safeCode}</text>
  <path d="M10 36 C32 28, 48 42, 72 34 S104 28, 118 34" fill="none" stroke="#60a5fa" stroke-width="2" opacity="0.55"/>
</svg>
SVG;
    }

    private function randomCode(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < 4; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
