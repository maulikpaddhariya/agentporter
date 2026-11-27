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
 * - JSON nested inside a string is decoded and redacted too.
 * - Depth, item count, string length, total work and output size are capped.
 *
 * The agentporter_redact_fields filter may only ADD field names; the defaults cannot be removed.
 */
final class Redactor {

	public const MASK = '[redacted]';

	private const MAX_DEPTH   = 10;
	private const MAX_ITEMS   = 100;
	private const MAX_STRING  = 500;
	private const MAX_NODES   = 5000;
	private const DEFAULT_MAX = 4096;

	/**
	 * Field-name segments that mark a field as sensitive (exact segment match).
	 */
	private const SENSITIVE_SEGMENTS = array(
		'password',
		'pass',
		'passwd',
		'pwd',
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
	private const SENSITIVE_WORDS = array( 'password', 'passwd', 'secret', 'token', 'credential', 'apikey', 'cookie', 'signature', 'authorization', 'privatekey' );

	/**
	 * Remaining node budget for the current redaction.
	 *
	 * @var int
	 */
	private int $budget = self::MAX_NODES;

	/**
	 * Redacts any value and returns JSON no longer than $max_bytes.
	 *
	 * @param mixed $data      Data to redact.
	 * @param int   $max_bytes Maximum output size in bytes (at least 32).
	 */
	public function to_json( $data, int $max_bytes = self::DEFAULT_MAX ): string {
		$max_bytes    = max( 32, $max_bytes );
		$this->budget = self::MAX_NODES;
		$clean        = $this->redact( $data );
		$json         = (string) wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
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
		if ( is_int( $data ) ) {
			// Long integers are phone or card numbers.
			return strlen( (string) abs( $data ) ) >= 9 ? '[number]' : $data;
		}
		if ( is_float( $data ) || is_bool( $data ) || null === $data ) {
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
		$secret_keys = '(?:pass(?:word|wd)?|pwd|secret|token|access[_-]?token|refresh[_-]?token|id[_-]?token|api[_-]?key|apikey|key|auth|authorization|session|sessid|sig|signature|code|otp|pin|client[_-]?secret)';
		$patterns    = array(
			// URL credentials: https://user:pass@host.
			'#\b([a-z][a-z0-9+.-]*://)[^/\s:@]+:[^/\s@]+@#i' => '$1[redacted]@',
			'/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i'         => 'Bearer [token]',
			'/\bBasic\s+[A-Za-z0-9+\/=]+/i'              => 'Basic [token]',
			// key=value / key: value secrets in text, query strings and headers.
			'/\b(' . $secret_keys . ')(\s*[=:]\s*)("?)[^\s&"\',;]+/i' => '$1$2$3[redacted]',
			'/ap_(?:live|test)_[A-Za-z0-9]{6,}(?:_[A-Za-z0-9]+)?/' => '[agent-key]',
			'/\beyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/' => '[jwt]',
			'/\b(?:AKIA|ASIA|AGPA|AIDA|AROA)[0-9A-Z]{16}\b/' => '[aws-key]',
			// WordPress application passwords: six groups of four characters.
			'/\b[A-Za-z0-9]{4}(?: [A-Za-z0-9]{4}){5}\b/' => '[app-password]',
			'/[\p{L}\p{N}._%+-]{1,64}@[\p{L}\p{N}.-]{1,253}\.\p{L}{2,24}/u' => '[email]',
			'/\b(?:\d{1,3}\.){3}\d{1,3}\b/'              => '[ip]',
			'/(?<![\w:])(?=[0-9a-f:]*[0-9a-f])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![\w:])/i' => '[ip]',
			// Long random-looking runs (hex, base64, base64url) containing at least one digit.
			'/(?=[A-Za-z0-9+\/_=-]{20,})(?=[A-Za-z+\/_=-]*\d)[A-Za-z0-9+\/_-]{20,}={0,2}/' => '[token]',
			// Phone and card numbers: 9+ digits, separators allowed, even glued to letters or before a full stop.
			'/(?<!\d)\+?\d(?:[\s().-]?\d){8,}(?!\d)/'    => '[number]',
		);
		$result      = preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
		// If a pattern fails (for example invalid UTF-8 for the /u pattern), never return the raw text.
		$text = is_string( $result ) ? $result : '[unredactable]';
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > self::MAX_STRING ) {
			return mb_substr( $text, 0, self::MAX_STRING, 'UTF-8' ) . '…';
		}
		return strlen( $text ) > self::MAX_STRING ? substr( $text, 0, self::MAX_STRING ) . '…' : $text;
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
		$normalised = self::normalise_key( $key );
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
		$extra = $this->extra_fields();
		foreach ( $data as $key => $value ) {
			if ( ++$count > self::MAX_ITEMS ) {
				$out['_more'] = count( $data ) - self::MAX_ITEMS;
				break;
			}
			$safe_key = is_string( $key ) ? $this->redact_string( $key ) : $key;
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
		$trimmed = ltrim( $text );
		if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) && strlen( $text ) <= 65536 ) {
			$decoded = json_decode( $text, true, max( 1, self::MAX_DEPTH - $depth ) );
			if ( is_array( $decoded ) ) {
				return $this->redact_array( $decoded, $depth + 1 );
			}
		}
		return $this->redact_string( $text );
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
