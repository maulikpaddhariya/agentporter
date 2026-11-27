<?php
/**
 * Rector, dry run only (never applied in CI): fails when code can be modernised to the PHP 8.1
 * ceiling or still uses deprecated constructs.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
	->withPaths( array( __DIR__ . '/src', __DIR__ . '/uninstall.php' ) )
	->withSkip(
		array(
			// Must stay PHP 7.2-parseable (runs before the version check).
			__DIR__ . '/src/Core/Requirements.php',
			// Conflict with WordPress Coding Standards (long array syntax, mandatory DocBlock tags).
			Rector\Php54\Rector\Array_\LongArrayToShortArrayRector::class,
			Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector::class,
			Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector::class,
			Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector::class,
			// First-class callable syntax `fn(...)` fights WPCS spacing sniffs; string callables are fine.
			Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector::class,
			Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector::class,
			// uninstall.php deliberately guards PHP < 8.1 (it can run where the plugin never booted).
			Rector\DeadCode\Rector\ConstFetch\RemovePhpVersionIdCheckRector::class,
		)
	)
	->withPhpVersion( Rector\ValueObject\PhpVersion::PHP_81 )
	->withPhpSets( php81: true )
	->withPreparedSets( deadCode: true, typeDeclarations: true );
