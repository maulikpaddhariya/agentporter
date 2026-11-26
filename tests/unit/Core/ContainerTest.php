<?php
/**
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Tests\Unit\Core;

use AgentPorter\Core\Container;
use AgentPorter\Core\ContainerException;
use AgentPorter\Tests\Unit\TestCase;

/**
 * @covers \AgentPorter\Core\Container
 */
final class ContainerTest extends TestCase {

	public function test_unknown_service_throws(): void {
		$this->expectException( ContainerException::class );
		( new Container() )->get( \ArrayObject::class );
	}

	public function test_duplicate_registration_throws(): void {
		$container = new Container();
		$container->set( \ArrayObject::class, static fn() => new \ArrayObject() );
		$this->expectException( ContainerException::class );
		$container->set( \ArrayObject::class, static fn() => new \ArrayObject() );
	}

	public function test_factory_returning_wrong_type_throws(): void {
		$container = new Container();
		$container->set( \ArrayObject::class, static fn() => new \stdClass() );
		$this->expectException( ContainerException::class );
		$container->get( \ArrayObject::class );
	}

	public function test_circular_dependency_throws_and_does_not_recurse_forever(): void {
		$container = new Container();
		$container->set( \ArrayObject::class, static fn( Container $c ) => $c->get( \SplStack::class ) );
		$container->set( \SplStack::class, static fn( Container $c ) => $c->get( \ArrayObject::class ) );
		$this->expectException( ContainerException::class );
		$this->expectExceptionMessage( 'Circular' );
		$container->get( \ArrayObject::class );
	}

	public function test_failed_build_can_be_retried(): void {
		$container = new Container();
		$calls     = 0;
		$container->set(
			\ArrayObject::class,
			static function () use ( &$calls ) {
				++$calls;
				if ( 1 === $calls ) {
					throw new \RuntimeException( 'first' );
				}
				return new \ArrayObject();
			}
		);
		try {
			$container->get( \ArrayObject::class );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$this->assertInstanceOf( \ArrayObject::class, $container->get( \ArrayObject::class ) );
	}

	public function test_services_are_built_once_and_shared(): void {
		$container = new Container();
		$builds    = 0;
		$container->set(
			\ArrayObject::class,
			static function () use ( &$builds ) {
				++$builds;
				return new \ArrayObject();
			}
		);
		$this->assertTrue( $container->has( \ArrayObject::class ) );
		$this->assertFalse( $container->has( \SplStack::class ) );
		$this->assertSame( $container->get( \ArrayObject::class ), $container->get( \ArrayObject::class ) );
		$this->assertSame( 1, $builds );
	}
}
