<?php

declare(strict_types=1);

namespace BasilBook\Khqr;

/**
 * What a KHQR field may carry: printable Latin. Accents are folded (Café is Cafe), everything
 * else is dropped (Khmer, dashes, emoji), spaces are run together, and the text is cut to its
 * length. As the TypeScript: `normalize('NFKD')`, then `[\x20-\x7E]` only.
 *
 * NFKD comes from ext-intl when it is there; without it, a table made from ext-intl's NFKD
 * (`resources/latin-fold.php`) folds the same accented Latin, full-width letters and ligatures.
 *
 * @internal
 */
final class Latin
{
    /** @var array<string, string>|null */
    private static ?array $fold = null;

    private function __construct()
    {
    }

    public static function field(string $text, int $max, ?bool $useIntl = null): string
    {
        $useIntl ??= class_exists(\Normalizer::class);
        $decomposed = $useIntl ? \Normalizer::normalize($text, \Normalizer::FORM_KD) : false;
        if ($decomposed === false) {
            // Not intl, or text that is not valid UTF-8: fold with the table.
            $decomposed = strtr($text, self::fold());
        }
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '', $decomposed);
        $ascii = trim((string) preg_replace('/\s+/', ' ', $ascii));
        return trim(substr($ascii, 0, $max));
    }

    /** @return array<string, string> */
    private static function fold(): array
    {
        return self::$fold ??= require __DIR__ . '/../resources/latin-fold.php';
    }
}
