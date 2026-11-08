<?php
/**
 * Minimal test runner (no Composer, no PHPUnit) so the suite runs anywhere PHP 8.1+ runs:
 *   php tests/run.php
 *   docker compose run --rm tests
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

final class TestFailure extends Exception {}
final class TestSkipped extends Exception {}

abstract class TestCase {
	protected function assertSame( $expected, $actual, string $msg = '' ): void {
		if ( $expected !== $actual ) {
			throw new TestFailure( ( $msg ? "$msg: " : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}
	protected function assertTrue( $v, string $msg = '' ): void {
		$this->assertSame( true, $v, $msg );
	}
	protected function assertFalse( $v, string $msg = '' ): void {
		$this->assertSame( false, $v, $msg );
	}
	protected function assertContains( string $needle, array $haystack, string $msg = '' ): void {
		foreach ( $haystack as $item ) {
			if ( str_contains( (string) $item, $needle ) ) {
				return;
			}
		}
		throw new TestFailure( ( $msg ? "$msg: " : '' ) . "no item contains '$needle' in " . json_encode( $haystack ) );
	}
	protected function skip( string $why ): void {
		throw new TestSkipped( $why );
	}
	protected function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/' . $name ), true, 512, JSON_THROW_ON_ERROR );
	}
}

$files = glob( __DIR__ . '/unit/*Test.php' );
$pass  = 0;
$fail  = 0;
$skip  = 0;
foreach ( $files as $file ) {
	require_once $file;
	$class = basename( $file, '.php' );
	foreach ( get_class_methods( $class ) as $method ) {
		if ( ! str_starts_with( $method, 'test' ) ) {
			continue;
		}
		$name = "$class::$method";
		try {
			( new $class() )->$method();
			$pass++;
			echo "  ok    $name\n";
		} catch ( TestSkipped $e ) {
			$skip++;
			echo "  skip  $name ({$e->getMessage()})\n";
		} catch ( Throwable $e ) {
			$fail++;
			echo "  FAIL  $name\n        " . get_class( $e ) . ': ' . $e->getMessage() . "\n";
		}
	}
}
printf( "\n%d passed, %d failed, %d skipped\n", $pass, $fail, $skip );
exit( $fail > 0 ? 1 : 0 );
