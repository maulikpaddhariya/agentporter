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
 * - Fields whose name looks sensitive (password, token, key, secret, cookie, email, phone, …) are replaced.
 * - Values that look like emails, phone numbers, IP addresses, bearer/hex/base64 tokens or AgentPorter
 *   keys are replaced, wherever they appear.
 * - Depth, item count, string length and total size are capped; output never exceeds the byte limit.
 *
 * The agentporter_redact_fields filter may only ADD field names; the defaults cannot be removed.
 */
final class Redactor {

	public const MASK = '[redacted]';

	private const MAX_DEPTH   = 10;
	private const MAX_ITEMS   = 100;
	private const MAX_STRING  = 500;
	private const DEFAULT_MAX = 4096;

	private const SENSITIVE_FIELDS = array(
		'password',
		'pass',
		'passwd',
		'pwd',
		'secret',
		'token',
		'access_token',
		'refresh_token',
		'api_key',
		'apikey',
		'key',
		'private_key',
		'authorization',
		'auth',
		'cookie',
		'nonce',
		'signature',
		'sig',
		'session',
		'card',
		'card_number',
		'cvv',
		'cvc',
		'iban',
		'ssn',
		'email',
		'billing_email',
		'phone',
		'billing_phone',
		'ip',
		'ip_address',
	);

	/**
	 * Redacts any value and returns JSON no longer than $max_bytes.
	 *
	 * @param mixed $data      Data to redact.
	 * @param int   $max_bytes Maximum output size in bytes.
	 */
	public function to_json( $data, int $max_bytes = self::DEFAULT_MAX ): string {
		$clean = $this->redact( $data );
		$json  = (string) wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( strlen( $json ) <= $max_bytes ) {
			return $json;
		}
		// Too big: keep a valid JSON summary instead of a cut string.
		$preview = function_exists( 'mb_strcut' ) ? mb_strcut( $json, 0, max( 0, $max_bytes - 64 ), 'UTF-8' ) : substr( $json, 0, max( 0, $max_bytes - 64 ) );
		$summary = (string) wp_json_encode(
			array(
				'_truncated' => true,
				'_preview'   => $preview,
			),
			JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);
		$size    = strlen( $summary );
		while ( $size > $max_bytes && '' !== $preview ) {
			$preview = substr( $preview, 0, (int) ( strlen( $preview ) * 0.8 ) );
			$summary = (string) wp_json_encode(
				array(
					'_truncated' => true,
					'_preview'   => $preview,
				),
				JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
			);
			$size    = strlen( $summary );
		}
		return $size <= $max_bytes ? $summary : '{"_truncated":true}';
	}

	/**
	 * Redacts a value recursively.
	 *
	 * @param mixed $data  Data.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	public function redact( $data, int $depth = 0 ) {
		if ( $depth >= self::MAX_DEPTH ) {
			return '[depth limit]';
		}
		if ( is_object( $data ) ) {
			$data = $data instanceof \JsonSerializable ? $data->jsonSerialize() : get_object_vars( $data );
		}
		if ( is_array( $data ) ) {
			$out    = array();
			$count  = 0;
			$fields = $this->sensitive_fields();
			foreach ( $data as $key => $value ) {
				if ( ++$count > self::MAX_ITEMS ) {
					$out['_more'] = count( $data ) - self::MAX_ITEMS;
					break;
				}
				$out[ $key ] = ( is_string( $key ) && $this->is_sensitive_field( $key, $fields ) )
					? self::MASK
					: $this->redact( $value, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $data ) ) {
			return $this->redact_string( $data );
		}
		if ( is_int( $data ) || is_float( $data ) || is_bool( $data ) || null === $data ) {
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
			'/ap_(?:live|test)_[A-Za-z0-9]{6,}(?:_[A-Za-z0-9]+)?/' => '[agent-key]',
			'/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i'         => 'Bearer [token]',
			'/[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,24}/' => '[email]',
			'/\b(?:\d{1,3}\.){3}\d{1,3}\b/'              => '[ip]',
			'/(?<![\w:])(?=[0-9a-f:]*[0-9a-f])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![\w:])/i' => '[ip]',
			'/\b[A-Fa-f0-9]{32,}\b/'                     => '[token]',
			'/\b[A-Za-z0-9_\-]{32,}={0,2}/'              => '[token]',
			'/(?<![\w.])\+?\d[\d\s().-]{6,}\d(?![\w.])/' => '[phone]',
		);
		$text     = (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > self::MAX_STRING ) {
			return mb_substr( $text, 0, self::MAX_STRING, 'UTF-8' ) . '…';
		}
		return strlen( $text ) > self::MAX_STRING ? substr( $text, 0, self::MAX_STRING ) . '…' : $text;
	}

	/**
	 * Default sensitive field names plus any added by the filter (never fewer than the defaults).
	 *
	 * @return array<string, true>
	 */
	public function sensitive_fields(): array {
		$fields = self::SENSITIVE_FIELDS;
		/**
		 * Adds field names to mask in logs and audit entries. Defaults cannot be removed.
		 *
		 * @param string[] $fields Extra field names (lowercase).
		 */
		$extra = apply_filters( 'agentporter_redact_fields', array() );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $field ) {
				if ( is_string( $field ) && '' !== $field ) {
					$fields[] = strtolower( $field );
				}
			}
		}
		return array_fill_keys( $fields, true );
	}

	/**
	 * Whether a field name is sensitive (exact match or a sensitive word as a segment, e.g. "user_password").
	 *
	 * @param string              $key    Field name.
	 * @param array<string, true> $fields Sensitive names.
	 */
	private function is_sensitive_field( string $key, array $fields ): bool {
		$key = strtolower( $key );
		if ( isset( $fields[ $key ] ) ) {
			return true;
		}
		$segments = preg_split( '/[^a-z0-9]+/', $key );
		foreach ( false === $segments ? array() : $segments as $segment ) {
			if ( '' !== $segment && isset( $fields[ $segment ] ) ) {
				return true;
			}
		}
		return false;
	}
}
