<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * The two requests the palette studio of Theme > Branding makes (as JSON): a palette made from two colours, and the list of
 * Duo colour pairings. Neither stores anything; the palette is saved with the rest of the Theme form.
 */
final class PaletteAdmin
{
    /** The theme settings' fields for the Duo picker: on by default; an empty address means Duo's own. */
    public static function duoEnabled(array $settings): bool
    {
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
        return ($design['duo_picker'] ?? true) !== false;
    }

    /**
     * @param array<string, mixed> $input the request's own values (accent, base, base_role, secondary)
     * @return array<string, mixed>
     */
    public static function generate(array $input): array
    {
        $role = ($input['base_role'] ?? '') === 'ink' ? 'ink' : 'background';
        $palette = PaletteGenerator::generate((string)($input['accent'] ?? ''), (string)($input['base'] ?? ''), $role, (string)($input['secondary'] ?? ''));
        if ($palette === null) {
            return ['ok' => false, 'message' => 'Both colours must be hex codes such as #1d4ed8.'];
        }
        $palette['ok'] = true;
        $palette['passes'] = PaletteGenerator::passes($palette);
        return $palette;
    }

    /** @return array<string, mixed> */
    public static function duo(array $settings, DuoPairings $duo): array
    {
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
        if (!self::duoEnabled($settings)) {
            return ['ok' => false, 'off' => true, 'message' => ''];
        }
        $pairs = $duo->pairs((string)($design['duo_url'] ?? ''));
        if ($pairs === null) {
            return ['ok' => false, 'message' => 'Colour pairings are not available right now.'];
        }
        return ['ok' => true, 'pairs' => $pairs, 'credit' => DuoPairings::CREDIT, 'credit_url' => DuoPairings::SITE_URL];
    }
}
