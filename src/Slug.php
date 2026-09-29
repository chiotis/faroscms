<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * Turns text into a web address: Greek is converted to Latin letters with the usual ELOT 743 rules
 * (ου → ou, μπ → b at the start of a word, and so on), other accents are dropped, everything else becomes dashes.
 */
final class Slug
{
    /** Words that already mean something at the root of the site, so a page cannot use them as its address. */
    private const ROOT_RESERVED = [
        'admin', 'assets', 'uploads', 'custom', 'pages', 'search', 'tag', 'tags', 'category', 'categories',
        'sitemap', 'sitemap-xml', 'robots', 'robots-txt', 'favicon', 'api', 'storage', 'themes', 'vendor',
    ];

    private const GREEK_LETTERS = [
        'α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'i', 'θ' => 'th', 'ι' => 'i',
        'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o', 'π' => 'p', 'ρ' => 'r', 'σ' => 's',
        'τ' => 't', 'υ' => 'y', 'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o', 'ϊ' => 'i', 'ϋ' => 'y',
    ];

    private const LATIN_ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e',
        'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o',
        'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'ß' => 'ss', 'š' => 's', 'ž' => 'z', 'č' => 'c', 'ł' => 'l', 'ğ' => 'g', 'ş' => 's', 'ı' => 'i',
    ];

    public const MAX_LENGTH = 80;

    public static function fromText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        // Stress marks are dropped, but the diaeresis stays: αϊ is two sounds, while αί is one.
        $text = strtr($text, [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', 'ς' => 'σ', 'ΐ' => 'ϊ', 'ΰ' => 'ϋ',
        ]);
        $text = strtr($text, self::LATIN_ACCENTS);

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        $count = count($chars);
        for ($i = 0; $i < $count; $i++) {
            $c = $chars[$i];
            $next = $chars[$i + 1] ?? '';
            $pair = $c . $next;
            $start = $i === 0 || !isset(self::GREEK_LETTERS[$chars[$i - 1]]);
            $voiceless = $next === '' || ($chars[$i + 2] ?? '') === '' || in_array($chars[$i + 2], ['κ', 'ξ', 'π', 'σ', 'τ', 'φ', 'χ', 'ψ'], true) || !isset(self::GREEK_LETTERS[$chars[$i + 2]]);

            $digraph = match ($pair) {
                'ου' => 'ou', 'ει' => 'ei', 'οι' => 'oi', 'αι' => 'ai', 'υι' => 'yi',
                'αυ' => $voiceless ? 'af' : 'av',
                'ευ' => $voiceless ? 'ef' : 'ev',
                'ηυ' => $voiceless ? 'if' : 'iv',
                'μπ' => $start ? 'b' : 'mp',
                'ντ' => $start ? 'd' : 'nt',
                'γκ' => $start ? 'g' : 'gk',
                'γγ' => 'ng', 'γξ' => 'nx', 'γχ' => 'nch',
                default => null,
            };
            if ($digraph !== null) {
                $out .= $digraph;
                $i++;
                continue;
            }
            $out .= self::GREEK_LETTERS[$c] ?? $c;
        }

        $out = preg_replace('/[^a-z0-9\-_]+/', '-', $out) ?? '';
        $out = trim((string)preg_replace('/-{2,}/', '-', $out), '-_');
        if (strlen($out) > self::MAX_LENGTH) {
            $cut = substr($out, 0, self::MAX_LENGTH);
            $dash = strrpos($cut, '-');
            $out = rtrim($dash !== false && $dash > 20 ? substr($cut, 0, $dash) : $cut, '-_');
        }
        return $out;
    }

    /**
     * @param string[] $reservedExtra content type names and language codes, which also live at the root
     */
    public static function isReserved(string $slug, array $reservedExtra = []): bool
    {
        return in_array($slug, self::ROOT_RESERVED, true) || in_array($slug, $reservedExtra, true);
    }
}
