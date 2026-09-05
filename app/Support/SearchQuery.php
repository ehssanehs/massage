<?php
declare(strict_types=1);

namespace App\Support;

/** Read-time search normalization only: never rewrite names, codes, or phone numbers. */
final class SearchQuery {
    public const MAX_LENGTH = 300;
    public const MAX_TERMS = 12;

    // Character-class fragments using literal Unicode ranges. These drive the
    // PHP-side normalization (search terms) only — see normalize().
    private const SPACES = " \t\n\r\v\f\u{0085}\u{00a0}\u{1680}\u{2000}-\u{200a}\u{2028}\u{2029}\u{202f}\u{205f}\u{3000}";
    private const IGNORED = "\u{200b}-\u{200f}\u{061c}\u{202a}-\u{202e}\u{2066}-\u{2069}\u{feff}\u{0640}\u{0610}-\u{061a}\u{064b}-\u{065f}\u{0670}";

    // Field boundary used inside the CONCAT_WS expression. A control character
    // that never occurs in user input (see fromInput()) keeps unrelated fields
    // apart, e.g. searching "نامEND" must not match first_name="نام" + code="END".
    private const SEPARATOR = "\x1f";

    // Marks removed from TEXT columns by the SQL normalizer. The set is bounded
    // so the generated REPLACE() chain stays SHALLOW: SQLite's parser overflows
    // around ~27 nested function calls, and keeping the chain short also avoids
    // approaching MySQL/MariaDB expression-depth limits. It covers the spaces
    // and format/joining marks that realistically appear in Persian names.
    // Search terms are normalized more aggressively on the PHP side (IGNORED
    // includes every combining mark and bidi control), so this only needs to
    // remove the marks that can actually be stored in a name field.
    private const SQL_STRIP_TEXT = " \t\n\r\u{00a0}\u{200c}\u{200b}\u{200e}\u{200f}\u{2067}\u{2069}\u{0640}\u{064e}\u{0650}";

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
     *
     * Normalization uses only CONCAT_WS/LOWER/REPLACE — never REGEXP_REPLACE.
     * REGEXP_REPLACE is unavailable on MySQL < 8.0.4 and behaves differently
     * between MySQL (ICU) and MariaDB (PCRE); relying on it crashed every text
     * search with a SQL error, while date-only filters (which never build this
     * predicate) kept working.
     *
     * @param list<string> $expressions columns/expressions to search
     * @param list<int>    $numericKeys indices in $expressions holding phone/code
     *     columns — these fold only digits (names never contain digits; phone and
     *     code fields never contain letters), keeping each REPLACE branch shallow.
     * @return array{string, list<string>} WHERE predicate and bound parameters
     */
    public function predicate(array $expressions, array $numericKeys = []): array {
        if ($this->error !== null) return ['1=0', []];
        if (!$this->terms) return ['', []];
        if (!$expressions) return ['1=0', []];

        $numeric = array_flip($numericKeys);
        $needed = implode('', $this->terms);

        // The full name is supplied as ONE expression, so both "محمد رضا" and
        // "محمدرضا" match it; the CONCAT_WS separator joins adjacent fields while
        // keeping unrelated fields apart. Each expression carries its own shallow
        // normalization pipeline so nesting depth stays low on every engine.
        $normalized = [];
        foreach (array_values($expressions) as $index => $expression) {
            $normalized[] = isset($numeric[$index])
                ? self::normalizeNumeric($expression, $needed)
                : self::normalizeText($expression, $needed);
        }
        $text = 'LOWER(CONCAT_WS(' . self::literal(self::SEPARATOR) . ', ' . implode(', ', $normalized) . '))';

        $conditions = $params = [];
        foreach ($this->terms as $term) {
            $conditions[] = "$text LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($term, ['!'=>'!!', '%'=>'!%', '_'=>'!_']) . '%';
        }
        return ['(' . implode(' AND ', $conditions) . ')', $params];
    }

    /**
     * Wrap a trusted text expression (names, notes, titles, ...): remove spaces and
     * joining/format marks, then fold Arabic letter variants. Letter folds are
     * applied only when the search terms need them (a digit search skips them).
     */
    private static function normalizeText(string $expression, string $needed): string {
        $text = $expression;
        foreach (self::sqlStripCharacters() as $char) {
            $text = 'REPLACE(' . $text . ', ' . self::literal($char) . ", '')";
        }
        foreach (self::letterFolds() as $from => $to) {
            // A fold whose output appears in no search word cannot affect a match.
            if (str_contains($needed, $to)) {
                $text = 'REPLACE(' . $text . ', ' . self::literal($from) . ', ' . self::literal($to) . ')';
            }
        }
        return $text;
    }

    /**
     * Wrap a trusted phone/code expression: digits are the only character class
     * stored here, so fold just the Persian/Arabic-Indic digits to ASCII. No mark
     * stripping or letter folds are needed, which keeps the branch shallow.
     */
    private static function normalizeNumeric(string $expression, string $needed): string {
        $text = $expression;
        foreach (self::folds() as $from => $to) {
            // Only fold a digit when the query contains its ASCII counterpart.
            // $to is a single ASCII digit for digit folds; $from the Persian/Arabic.
            if (ctype_digit($to) && str_contains($needed, $to)) {
                $text = 'REPLACE(' . $text . ', ' . self::literal($from) . ', ' . self::literal($to) . ')';
            }
        }
        return $text;
    }

    private static function ignoredPattern(): string {
        return '[' . self::SPACES . self::IGNORED . ']';
    }

    /** Distinct characters in the text STRIP set as individual literals. */
    private static function sqlStripCharacters(): array {
        static $chars = null;
        return $chars ??= array_values(array_unique(preg_split('//u', self::SQL_STRIP_TEXT, -1, PREG_SPLIT_NO_EMPTY)));
    }

    /**
     * Letter-only folds (no digits) applied to TEXT columns. Limited to the common
     * Arabic-letter variants that real Persian names use, so the REPLACE chain stays
     * shallow. The broader fold set (rare marbuta/hamza forms) is still applied to
     * search terms on the PHP side; storing those rare forms in a name is uncommon
     * and not part of the hot path.
     */
    private static function letterFolds(): array {
        static $map = null;
        return $map ??= ['ي'=>'ی', 'ى'=>'ی', 'ك'=>'ک', 'أ'=>'ا', 'إ'=>'ا', 'آ'=>'ا', 'ٱ'=>'ا', 'ئ'=>'ی', 'ة'=>'ه'];
    }

    /**
     * Full fold map used for PHP-side term normalization and digit folding:
     * every Arabic letter variant plus Persian/Arabic-Indic digits. Deliberately
     * a superset of {@see letterFolds()} (which is the shallow, SQL-only subset),
     * so rare forms like ؤ/ۀ are still normalized in the (depth-unconstrained)
     * PHP path even though the SQL hot path skips them.
     */
    private static function folds(): array {
        static $map = null;
        if ($map === null) {
            $map = self::letterFolds() + ['ؤ'=>'و', 'ۀ'=>'ه'];
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
