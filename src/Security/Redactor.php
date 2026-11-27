<?php
/**
 * Masks secrets and personal data before anything is logged or stored in the audit log.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Produces bounded, valid JSON with sensitive fields and values masked.
 *
 * - Field names are normalised (camelCase and separators split) and masked when a segment or a
 *   strong word (token, secret, password, credential, cookie…) marks them as sensitive.
 * - Array keys themselves are redacted (a key can be an email or a token).
 * - Values are masked wherever they appear: emails (including international), long digit runs
 *   (phones, cards), IP addresses, JWTs, bearer/basic credentials, AWS keys, WordPress application
 *   passwords, AgentPorter keys, key=value secrets, URL credentials and long random-looking strings.
 * - JSON nested inside a string is decoded and redacted too; JSON-shaped text that does not decode
 *   (malformed, truncated, too deep, too long) is dropped as [unparsed-json], never passed through.
 * - Depth, item count, string length, total nodes, total text bytes and output size are capped.
 * - Every pattern runs in linear time (possessive runs, anchored starts) so hostile input stays cheap.
 *
 * The agentporter_redact_fields filter may only ADD field names; the defaults cannot be removed.
 */
final class Redactor {

	public const MASK = '[redacted]';

	private const MAX_DEPTH   = 10;
	private const MAX_ITEMS   = 100;
	private const MAX_STRING  = 500;
	private const MAX_NODES   = 5000;
	private const MAX_TEXT    = 65536;
	private const DEFAULT_MAX = 4096;

	/**
	 * Field-name segments that mark a field as sensitive (exact segment match).
	 */
	private const SENSITIVE_SEGMENTS = array(
		'password',
		'pass',
		'passwd',
		'pwd',
		'pw',
		'passcode',
		'passphrase',
		'secret',
		'token',
		'key',
		'apikey',
		'auth',
		'authorization',
		'bearer',
		'cookie',
		'cookies',
		'nonce',
		'signature',
		'sig',
		'session',
		'sessid',
		'card',
		'cc',
		'cvv',
		'cvc',
		'iban',
		'ssn',
		'pan',
		'aadhaar',
		'otp',
		'pin',
		'mfa',
		'totp',
		'email',
		'mail',
		'phone',
		'mobile',
		'tel',
		'telephone',
		'whatsapp',
		'ip',
		'address',
		'credential',
		'credentials',
		'private',
		'salt',
		'hash',
		'jwt',
	);

	/**
	 * Words that make a field sensitive anywhere in its name (substring match).
	 */
	private const SENSITIVE_WORDS = array( 'pass', 'secret', 'token', 'credential', 'apikey', 'cookie', 'signature', 'authorization', 'privatekey', 'sessid', 'sessionid', 'cardnum', 'phonenum', 'nonce', 'csrf', 'xsrf', 'refresh', 'verificationcode', 'oauthcode' );

	/**
	 * Extra segments that mark a key=value pair in free text as secret (OAuth codes and similar).
	 */
	private const TEXT_ONLY_SEGMENTS = array( 'code', 'sid' );

	/**
	 * Remaining node budget for the current redaction.
	 *
	 * @var int
	 */
	private int $budget = self::MAX_NODES;

	/**
	 * Remaining text bytes the current redaction may process.
	 *
	 * @var int
	 */
	private int $text_budget = self::MAX_TEXT;

	/**
	 * Filter-added field names for the current redaction (null = not read yet).
	 *
	 * @var array<string, true>|null
	 */
	private ?array $extra_cache = null;

	/**
	 * Redacts any value and returns JSON no longer than $max_bytes.
	 *
	 * @param mixed $data      Data to redact.
	 * @param int   $max_bytes Maximum output size in bytes (at least 32).
	 */
	public function to_json( $data, int $max_bytes = self::DEFAULT_MAX ): string {
		$max_bytes = max( 32, $max_bytes );
		$clean     = $this->redact( $data );
		$json      = (string) wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( strlen( $json ) <= $max_bytes ) {
			return $json;
		}
		// Too big: keep a valid JSON summary instead of a cut string.
		$preview = function_exists( 'mb_strcut' ) ? mb_strcut( $json, 0, max( 0, $max_bytes - 64 ), 'UTF-8' ) : substr( $json, 0, max( 0, $max_bytes - 64 ) );
		$summary = $this->summary( $preview );
		$size    = strlen( $summary );
		while ( $size > $max_bytes && '' !== $preview ) {
			$preview = substr( $preview, 0, (int) ( strlen( $preview ) * 0.8 ) );
			$summary = $this->summary( $preview );
			$size    = strlen( $summary );
		}
		return $size <= $max_bytes ? $summary : '{"_truncated":true}';
	}

	/**
	 * Redacts a value recursively.
	 *
	 * @param mixed $data  Data.
	 * @param int   $depth Current depth.
	 * @return array<array-key, mixed>|string|int|float|bool|null
	 */
	public function redact( $data, int $depth = 0 ): array|string|int|float|bool|null {
		if ( 0 === $depth ) {
			$this->budget      = self::MAX_NODES;
			$this->text_budget = self::MAX_TEXT;
			$this->extra_cache = null;
		}
		if ( --$this->budget < 0 ) {
			return '[limit]';
		}
		if ( $depth >= self::MAX_DEPTH ) {
			return '[depth limit]';
		}
		if ( is_object( $data ) ) {
			$data = $data instanceof \JsonSerializable ? $data->jsonSerialize() : get_object_vars( $data );
		}
		if ( is_array( $data ) ) {
			return $this->redact_array( $data, $depth );
		}
		if ( is_string( $data ) ) {
			return $this->redact_text_or_json( $data, $depth );
		}
		if ( is_int( $data ) || is_float( $data ) ) {
			return self::redact_number( $data );
		}
		if ( is_bool( $data ) || null === $data ) {
			return $data;
		}
		return '[unsupported]';
	}

	/**
	 * Masks sensitive patterns inside a string and caps its length.
	 *
	 * @param string $text Text.
	 */
	public function redact_string( string $text ): string {
		if ( strlen( $text ) > self::MAX_STRING * 4 ) {
			$text = substr( $text, 0, self::MAX_STRING * 4 );
		}
		$patterns = array(
			// URL credentials: https://user:pass@host, https://:pass@host, https://token@host.
			'#\b([a-z][a-z0-9+.-]*://)[^/\s@]++@#i'      => '$1[redacted]@',
			// Whole header values: Digest/Token schemes and multi-cookie lists have no single token shape.
			'/\b((?:Proxy-)?Authorization|(?:Set-)?Cookie)(\s*+:\s*+)[^\r\n]++/i' => '$1$2[redacted]',
			'/\bBearer\s+[A-Za-z0-9._~+\/=-]++/i'        => 'Bearer [token]',
			// Vendor keys with fixed prefixes (GitHub, Stripe, OpenAI-style, Slack), even without digits.
			'/\b(?:gh[pousr]|github_pat|[sprw]k_(?:live|test)|sk-(?:proj-|ant-)?)[A-Za-z0-9_-]{16,}+/' => '[token]',
			'/\bxox[abposr]-[A-Za-z0-9-]{10,}+/'         => '[token]',
			'/\bBasic\s+[A-Za-z0-9+\/=]++/i'             => 'Basic [token]',
			'/ap_(?:live|test)_[A-Za-z0-9]{6,}+(?:_[A-Za-z0-9]++)?/' => '[agent-key]',
			'/\beyJ[A-Za-z0-9_-]*+\.[A-Za-z0-9_-]++\.[A-Za-z0-9_-]*+/' => '[jwt]',
			'/\b(?:AKIA|ASIA|AGPA|AIDA|AROA)[0-9A-Z]{16}\b/' => '[aws-key]',
			// WordPress application passwords: six groups of four characters.
			'/\b[A-Za-z0-9]{4}(?: [A-Za-z0-9]{4}){5}\b/' => '[app-password]',
			// The local part starts at a boundary so each "@" is tried once, not once per preceding character.
			'/(?<![\p{L}\p{N}._%+-])[\p{L}\p{N}._%+-]++@[\p{L}\p{N}.-]{1,253}\.\p{L}{2,24}/u' => '[email]',
			// UPI payment addresses and other user@handle identifiers without a dotted domain.
			'/(?<![\p{L}\p{N}._%+-])[\p{L}\p{N}._-]{2,}+@[A-Za-z][A-Za-z0-9-]{1,63}+(?![.\p{L}\p{N}@-])/u' => '[email]',
			'/\b(?:\d{1,3}\.){3}\d{1,3}\b/'              => '[ip]',
		);
		// Credential schemes first, so "Authorization: Basic …" loses the credential, not just the word "Basic".
		$result = preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
		$result = null === $result ? null : $this->replace_key_values( $result );
		// Long random-looking runs (hex, base64, base64url) containing at least one digit. Possessive and
		// anchored at the start of a run, so a long run costs one pass; the digit test is a plain scan.
		$result = null === $result ? null : preg_replace_callback(
			'/(?<![A-Za-z0-9+\/_-])[A-Za-z0-9+\/_-]{20,}+={0,2}/',
			static fn( array $m ): string => strpbrk( $m[0], '0123456789' ) === false ? $m[0] : '[token]',
			$result
		);
		// IPv6: hex-and-colon runs that are valid addresses (so clock times and MAC addresses stay).
		$result = null === $result ? null : preg_replace_callback(
			'/(?<![\w:])[0-9A-Fa-f:]{2,45}+(?![\w:])/',
			static fn( array $m ): string => str_contains( $m[0], ':' ) && false !== filter_var( $m[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[ip]' : $m[0],
			$result
		);
		// Phone and card numbers: 9+ digits, separators allowed ("(555) 123-4567"), even glued to letters.
		$result = null === $result ? null : preg_replace( '/(?<!\d)\+?\d(?:[\s().-]{0,2}+\d){8,}+(?!\d)/', '[number]', $result );
		// If a pattern fails (for example invalid UTF-8 for the /u pattern), never return the raw text.
		$text = is_string( $result ) ? $result : '[unredactable]';
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > self::MAX_STRING ) {
			return mb_substr( $text, 0, self::MAX_STRING, 'UTF-8' ) . '…';
		}
		return strlen( $text ) > self::MAX_STRING ? substr( $text, 0, self::MAX_STRING ) . '…' : $text;
	}

	/**
	 * Masks the value of key=value, key: value, "key": "value", 'key' => 'value', [key] => value and
	 * key -> value pairs whose key is sensitive. Any identifier is a candidate (so db_password=,
	 * MY_SECRET=, user_pass: all count); the key is judged by the same rules as field names, including
	 * names added through agentporter_redact_fields.
	 *
	 * Each match consumes only the key and separator; the value is captured in a lookahead. So a
	 * non-sensitive prefix such as "Error:" or "https:" never swallows a later pair, and scanning
	 * continues inside it. Quoted values run to the closing quote, or to the end of the line when the
	 * quote is unterminated (for example cut off by truncation). Linear: each start is tried once.
	 *
	 * @param string $text Text.
	 */
	private function replace_key_values( string $text ): ?string {
		$found = preg_match_all(
			'/(?<![A-Za-z0-9_-])([A-Za-z][A-Za-z0-9_-]{0,63}+)(["\'\]]?+[ \t]*+(?:=>|->|=|:)[ \t]*+)/',
			$text,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		);
		if ( false === $found ) {
			return null;
		}
		$extra   = $this->extras();
		$verdict = array(); // Repeated keys are judged once.
		$out     = '';
		$cursor  = 0;
		foreach ( $matches as $m ) {
			$value_start = (int) $m[2][1] + strlen( (string) $m[2][0] );
			if ( $value_start <= $cursor ) {
				continue;
			}
			$key               = strtolower( (string) $m[1][0] );
			$verdict[ $key ] ??= $this->is_sensitive_text_key( (string) $m[1][0], $extra );
			if ( ! $verdict[ $key ] ) {
				continue; // Inside a value already masked, or a key that is not sensitive.
			}
			// Only sensitive keys scan their value, so the scan stays linear in the text length.
			if ( 1 !== preg_match( '/\G(?:"(?:[^"\\\\\r\n]++|\\\\.)*+"?|\'(?:[^\'\\\\\r\n]++|\\\\.)*+\'?|[^\s&"\',;]++)/', $text, $v, 0, $value_start ) ) {
				continue;
			}
			$value  = $v[0];
			$quote  = ( '"' === $value[0] || "'" === $value[0] ) ? $value[0] : '';
			$out   .= substr( $text, $cursor, $value_start - $cursor ) . $quote . self::MASK . $quote;
			$cursor = $value_start + strlen( $value );
		}
		return $out . substr( $text, $cursor );
	}

	/**
	 * Whether a key found in free text marks its value as secret.
	 *
	 * @param string              $key   Key.
	 * @param array<string, true> $extra Extra names from the filter.
	 */
	private function is_sensitive_text_key( string $key, array $extra ): bool {
		return $this->is_sensitive_field( $key, $extra )
			|| array_intersect( explode( '_', self::normalise_key( $key ) ), self::TEXT_ONLY_SEGMENTS ) !== array();
	}

	/**
	 * Filter-added field names, read once per redaction.
	 *
	 * @return array<string, true>
	 */
	private function extras(): array {
		$this->extra_cache ??= $this->extra_fields();
		return $this->extra_cache;
	}

	/**
	 * Long integers (and floats carrying them) are phone or card numbers.
	 *
	 * @param int|float $number Number.
	 */
	private static function redact_number( int|float $number ): int|float|string {
		if ( is_float( $number ) ) {
			return ( is_finite( $number ) && abs( $number ) < 1e8 ) ? $number : '[number]';
		}
		return strlen( (string) abs( $number ) ) >= 9 ? '[number]' : $number;
	}

	/**
	 * Sensitive field names added through the filter (lowercase, normalised).
	 *
	 * @return array<string, true>
	 */
	public function extra_fields(): array {
		/**
		 * Adds field names to mask in logs and audit entries. Defaults cannot be removed.
		 *
		 * @param string[] $fields Extra field names (lowercase).
		 */
		$extra  = apply_filters( 'agentporter_redact_fields', array() );
		$fields = array();
		if ( is_array( $extra ) ) {
			foreach ( $extra as $field ) {
				if ( is_string( $field ) && '' !== $field ) {
					$fields[ self::normalise_key( $field ) ] = true;
				}
			}
		}
		return $fields;
	}

	/**
	 * Whether a field name is sensitive.
	 *
	 * @param string              $key   Field name.
	 * @param array<string, true> $extra Extra normalised names from the filter.
	 */
	public function is_sensitive_field( string $key, array $extra = array() ): bool {
		$normalised = self::normalise_key( substr( $key, 0, self::MAX_STRING * 4 ) );
		if ( isset( $extra[ $normalised ] ) ) {
			return true;
		}
		$segments = explode( '_', $normalised );
		foreach ( $segments as $segment ) {
			if ( in_array( $segment, self::SENSITIVE_SEGMENTS, true ) ) {
				return true;
			}
		}
		$joined = str_replace( '_', '', $normalised );
		foreach ( self::SENSITIVE_WORDS as $word ) {
			if ( str_contains( $joined, $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lowercase snake_case form of a key: "accessToken" and "access-token" become "access_token".
	 *
	 * @param string $key Field name.
	 */
	public static function normalise_key( string $key ): string {
		$snake = (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', $key );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( $snake ) ), '_' );
	}

	/**
	 * Redacts an array: sensitive fields masked, keys redacted, items capped.
	 *
	 * @param array<array-key, mixed> $data  Data.
	 * @param int                     $depth Depth.
	 * @return array<array-key, mixed>
	 */
	private function redact_array( array $data, int $depth ): array {
		$out   = array();
		$count = 0;
		$extra = $this->extras();
		foreach ( $data as $key => $value ) {
			if ( ++$count > self::MAX_ITEMS ) {
				$out['_more'] = count( $data ) - self::MAX_ITEMS;
				break;
			}
			if ( is_string( $key ) ) {
				// Keys cost text budget like values; a long key is judged and shown by its first part only.
				$key                = substr( $key, 0, self::MAX_STRING * 4 );
				$this->text_budget -= strlen( $key );
				if ( $this->text_budget < 0 ) {
					$out['_limit'] = true;
					break;
				}
				$safe_key = $this->redact_string( $key );
			} else {
				$number   = self::redact_number( $key );
				$safe_key = is_int( $number ) ? $number : $number . '#' . $count;
			}
			if ( is_string( $safe_key ) && array_key_exists( $safe_key, $out ) ) {
				$safe_key .= '#' . $count;
			}
			$out[ $safe_key ] = ( is_string( $key ) && $this->is_sensitive_field( $key, $extra ) )
				? self::MASK
				: $this->redact( $value, $depth + 1 );
		}
		return $out;
	}

	/**
	 * Redacts text; JSON nested in a string is decoded and redacted as data.
	 *
	 * @param string $text  Text.
	 * @param int    $depth Depth.
	 * @return array<array-key, mixed>|string
	 */
	private function redact_text_or_json( string $text, int $depth ): array|string {
		$is_json = self::looks_like_json( $text );
		// JSON is decoded in full, so it costs its full length; plain text is cut to MAX_STRING * 4 first.
		$this->text_budget -= $is_json ? strlen( $text ) : min( strlen( $text ), self::MAX_STRING * 4 );
		if ( $this->text_budget < 0 ) {
			return '[limit]';
		}
		if ( $is_json ) {
			$decoded = strlen( $text ) <= self::MAX_TEXT ? json_decode( $text, true, max( 1, self::MAX_DEPTH - $depth ) ) : null;
			// Malformed, truncated, too deep or too long: its keys can't be judged, so keep none of it.
			return is_array( $decoded ) ? $this->redact_array( $decoded, $depth + 1 ) : '[unparsed-json]';
		}
		return $this->redact_string( $text );
	}

	/**
	 * Whether text is shaped like a JSON object or array (not a "[INFO] …" log line).
	 *
	 * @param string $text Text.
	 */
	private static function looks_like_json( string $text ): bool {
		return 1 === preg_match( '/\A\s*+(?:\{|\[\s*+(?:[\[{"\]\d-]|true|false|null))/', $text );
	}

	/**
	 * Truncation summary.
	 *
	 * @param string $preview Partial JSON.
	 */
	private function summary( string $preview ): string {
		return (string) wp_json_encode(
			array(
				'_truncated' => true,
				'_preview'   => $preview,
			),
			JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);
	}
}
