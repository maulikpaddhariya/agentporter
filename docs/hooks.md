# AgentPorter hooks

Public actions and filters. Once released, a hook's name, arguments and meaning stay stable. Every hook listed here has a test.

## Actions

### `agentporter_booted`

Fires once, after AgentPorter has booted its subsystems.

| Argument | Type | Description |
|---|---|---|
| `$status` | `array{booted: list<string>, disabled: list<string>, locked_down: bool}` | Read-only boot status. `booted` lists subsystem ids that started; `disabled` lists ids switched off by an error; `locked_down` is true when a security-critical subsystem failed and all agent access is denied. |

Exceptions thrown by listeners are caught and logged (class name only); they never stop the site.

### `agentporter_subsystem_failed`

Fires when AgentPorter switches off one of its subsystems for the rest of the request after an unexpected exception. During boot, it fires after boot has finished.

| Argument | Type | Description |
|---|---|---|
| `$subsystem` | `string` | Subsystem id (for example `migrations`), `class:<ClassName>` when the subsystem failed before reporting its id, or `dependency:<id>` for subsystems switched off because something they depend on failed. |
| `$exception_class` | `string` | Class name of the exception. The message is never passed (it can contain private data). |

### `agentporter_guard_check`

Runs last in `AgentGuard`, only after every built-in check (kill switch, enabled ability, explicit capability grant, scope, rate limits) has passed. Use it to add your own denial rules, for example office hours.

| Argument | Type | Description |
|---|---|---|
| `$denials` | `AgentPorter\Security\GuardDenials` | Collector. Call `$denials->deny( 'code' )` to deny. Nothing can remove a denial, so a later listener can never cancel an earlier one. |
| `$ability` | `string` | Ability name, for example `agentporter/site-info`. |
| `$context` | `AgentPorter\Security\AgentContext` | Caller: actor type, user id, IP, scopes. |
| `$write` | `bool` | Whether the ability writes. |

The caller only ever sees a generic "This action is not available." with code `agentporter_policy_<your_code>`; your code is sanitised (lowercase letters, digits, underscores) and recorded in the audit log. Never put personal data in it. A listener that throws denies the call.

```php
add_action( 'agentporter_guard_check', function ( $denials ) {
	$hour = (int) wp_date( 'G' );
	if ( $hour < 8 || $hour >= 20 ) {
		$denials->deny( 'outside_hours' );
	}
} );
```

## Filters

### `agentporter_redact_fields`

Adds field names that the redactor masks in logs and audit entries.

| Argument | Type | Description |
|---|---|---|
| `$fields` | `string[]` | Extra field names, lowercase. Default: empty. |

You can only **add** names. Built-in names (password, token, key, secret, cookie, email, phone, ip and others) are always masked.

```php
add_filter( 'agentporter_redact_fields', function ( $fields ) {
	$fields[] = 'order_note';
	return $fields;
} );
```

## Constants

| Constant | Effect |
|---|---|
| `AGENTPORTER_DISABLE_AGENTS` | When `true`, the kill switch is on: every agent call is denied. It cannot be turned off from the admin. |
| `AGENTPORTER_SECRET_KEY` | Key material (32 bytes or more) for encrypting AgentPorter's own secrets. When it is not defined, the key is derived from `AUTH_KEY` and `SECURE_AUTH_SALT`. Placeholder salts are refused. |
