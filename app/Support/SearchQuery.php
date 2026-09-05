<?php
declare(strict_types=1);

namespace App\Support;

/** Read-time search normalization only: never rewrite names, codes, or phone numbers. */
final class SearchQuery {
    public const MAX_LENGTH = 300;
    public const MAX_TERMS = 12;

    // Character-class fragments using literal Unicode ranges. This avoids SQL
    // backslash-mode differences between MySQL's ICU and MariaDB's PCRE regexes.
    private const SPACES = " \t\n\r\v\f\u{0085}\u{00a0}\u{1680}\u{2000}-\u{200a}\u{2028}\u{2029}\u{202f}\u{205f}\u{3000}";
    private const IGNORED = "\u{200b}-\u{200f}\u{061c}\u{202a}-\u{202e}\u{2066}-\u{2069}\u{feff}\u{0640}\u{0610}-\u{061a}\u{064b}-\u{065f}\u{0670}";

    private function __construct(
        public readonly string $value,
        public readonly array $terms,
        public readonly ?string $error = null,
    ) {}

    public static function fromInput(mixed $value): self {
        $value ??= '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return new self('', [], 'عبارت جستجو باید متن معتبر باشد.');
        }
        if (mb_strlen($value, 'UTF-8') > self::MAX_LENGTH) {
            return new self($value, [], 'عبارت جستجو حداکثر ' . Jalali::fa(self::MAX_LENGTH) . ' نویسه باشد.');
        }
        // Reject non-whitespace C0 controls, including the field-boundary marker.
        if (preg_match('/[\x00-\x08\x0e-\x1f\x7f]/u', $value)) {
            return new self($value, [], 'عبارت جستجو دارای نویسهٔ نامعتبر است.');
        }
        $value = preg_replace('/\A[' . self::SPACES . ']+|[' . self::SPACES . ']+\z/u', '', $value);
        $words = preg_split('/[' . self::SPACES . ']+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_values(array_unique(array_filter(array_map(self::normalize(...), $words), static fn($term) => $term !== '')));
        if (count($terms) > self::MAX_TERMS) {
            return new self($value, [], 'عبارت جستجو حداکثر ' . Jalali::fa(self::MAX_TERMS) . ' واژه باشد.');
        }
        return new self($value, $terms);
    }

    /** Compact comparison key; presentation and database values stay unchanged. */
    public static function normalize(string $value): string {
        $value = strtr(mb_strtolower($value, 'UTF-8'), self::folds());
        return preg_replace('/' . self::ignoredPattern() . '/u', '', $value) ?? '';
    }

    /**
     * $expressions are trusted, server-defined SQL column/name expressions ONLY.
     * Every search word must occur in the row; word order is immaterial.
     * @return array{string, list<string>} WHERE predicate and bound parameters
     */
    public function predicate(array $expressions): array {
        if ($this->error !== null) return ['1=0', []];
        if (!$this->terms) return ['', []];
        if (!$expressions) return ['1=0', []];

        // Preserve boundaries between unrelated fields. The full name is supplied
        // as ONE expression, so both "محمد رضا" and "محمدرضا" still match it.
        // CAST keeps CHAR(31) nonbinary; otherwise LOWER/REGEXP can break on MySQL.
        $text = 'CONCAT_WS(CAST(CHAR(31) AS CHAR), ' . implode(', ', $expressions) . ')';
        $text = 'LOWER(REGEXP_REPLACE(' . $text . ', ' . self::literal(self::ignoredPattern()) . ", ''))";
        $needed = implode('', $this->terms);
        foreach (self::folds() as $from => $to) {
            // Replacements whose output is absent from every search word cannot
            // affect a match. Avoid twenty digit replacements for a name search.
            if (str_contains($needed, $to)) {
                $text = 'REPLACE(' . $text . ', ' . self::literal($from) . ', ' . self::literal($to) . ')';
            }
        }
        $conditions = $params = [];
        foreach ($this->terms as $term) {
            $conditions[] = "$text LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($term, ['!'=>'!!', '%'=>'!%', '_'=>'!_']) . '%';
        }
        return ['(' . implode(' AND ', $conditions) . ')', $params];
    }

    private static function ignoredPattern(): string {
        return '[' . self::SPACES . self::IGNORED . ']';
    }

    private static function folds(): array {
        static $map = null;
        if ($map === null) {
            $map = ['ي'=>'ی', 'ى'=>'ی', 'ك'=>'ک', 'أ'=>'ا', 'إ'=>'ا', 'آ'=>'ا', 'ٱ'=>'ا', 'ؤ'=>'و', 'ئ'=>'ی', 'ة'=>'ه', 'ۀ'=>'ه'];
            foreach (['۰۱۲۳۴۵۶۷۸۹', '٠١٢٣٤٥٦٧٨٩'] as $digits) {
                foreach (preg_split('//u', $digits, -1, PREG_SPLIT_NO_EMPTY) as $number => $digit) $map[$digit] = (string)$number;
            }
        }
        return $map;
    }

    private static function literal(string $value): string {
        // Used only for fixed normalization constants, never for user input.
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
