<?php

declare(strict_types=1);

namespace OCA\AutoSort\Service;

use DateTimeImmutable;

/** Text matching without any Nextcloud dependency */
class RuleMatcher {
    // Readable date blocks such as {JJJJ.MM.TT}
    private const DATE_BLOCK = '/\{((?:JJJJ|JJ|MM|TT)(?:[._ -]?(?:JJJJ|JJ|MM|TT))*)\}/';

    private const MONTHS = [
        'januar' => 1, 'jan' => 1, 'februar' => 2, 'feb' => 2, 'märz' => 3, 'maerz' => 3, 'mär' => 3, 'mrz' => 3,
        'april' => 4, 'apr' => 4, 'mai' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9, 'oktober' => 10, 'okt' => 10, 'november' => 11, 'nov' => 11,
        'dezember' => 12, 'dez' => 12,
    ];

    // Words may be split or joined differently by OCR and numbers are often grouped,
    // so whitespace between words and between digits counts as a match
    private static function termPattern(string $term): string {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('\s*', array_map(static fn (string $word) => preg_replace('/(?<=\d)(?=\d)/', '\s*', preg_quote($word, '/')), $words));
    }

    public static function containsTerm(string $text, string $term): bool {
        $pattern = self::termPattern($term);
        return $pattern !== '' && preg_match('/' . $pattern . '/iu', $text) === 1;
    }

    /** Returns the first valid date in the text, or the first one following $after if given */
    public static function findDate(string $text, string $after = ''): ?DateTimeImmutable {
        if ($after !== '') {
            $pattern = self::termPattern($after);
            if ($pattern === '' || preg_match('/' . $pattern . '/iu', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                return null;
            }
            $text = substr($text, $match[0][1] + strlen($match[0][0]));
        }

        $months = implode('|', array_keys(self::MONTHS));
        $pattern = '/(?<!\d)(\d{1,2})(?:\.\s*(\d{1,2})\.\s*|\.?\s*(' . $months . ')(?:\.\s*|\s+))((?:19|20)\d{2})(?!\d)/iu';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
            return null;
        }

        foreach ($matches as $found) {
            $day = (int)$found[1];
            $month = $found[2] !== '' ? (int)$found[2] : self::MONTHS[mb_strtolower($found[3])];
            $year = (int)$found[4];
            if (checkdate($month, $day, $year)) {
                return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
            }
        }
        return null;
    }

    public static function usesDate(string $template): bool {
        return preg_match('/\{(Datum|Jahr|Monat|Tag)\}/', $template) === 1 || preg_match(self::DATE_BLOCK, $template) === 1;
    }

    public static function fill(string $template, ?DateTimeImmutable $date, string $original, string $hit = ''): string {
        $template = preg_replace_callback(self::DATE_BLOCK, static fn (array $block) => $date === null ? '' : strtr($block[1], [
            'JJJJ' => $date->format('Y'),
            'JJ' => $date->format('y'),
            'MM' => $date->format('m'),
            'TT' => $date->format('d'),
        ]), $template);

        return strtr($template, [
            '{Datum}' => $date?->format('Y.m.d') ?? '',
            '{Jahr}' => $date?->format('Y') ?? '',
            '{Monat}' => $date?->format('m') ?? '',
            '{Tag}' => $date?->format('d') ?? '',
            '{Original}' => $original,
            // A term could contain a slash, which would turn into a folder
            '{Treffer}' => str_replace(['/', '\\'], '-', $hit),
        ]);
    }

    /** Builds the new file name; a blank template keeps the original name */
    public static function buildFilename(string $template, ?DateTimeImmutable $date, string $originalName, string $hit = ''): string {
        if (trim($template) === '') {
            return $originalName;
        }
        $base = preg_replace('/\.pdf$/i', '', $originalName);
        $name = trim(str_replace(['/', '\\'], '-', self::fill(trim($template), $date, $base, $hit)));
        return preg_match('/\.pdf$/i', $name) === 1 ? $name : $name . '.pdf';
    }
}
