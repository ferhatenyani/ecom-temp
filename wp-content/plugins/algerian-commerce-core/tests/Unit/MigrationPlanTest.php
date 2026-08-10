<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Core\Migrations\MigrationPlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MigrationPlanTest extends TestCase
{
    public function testParsesVersionAndNameFromFilename(): void
    {
        self::assertSame(1, MigrationPlan::parseVersion('001_create_audit_logs.php'));
        self::assertSame('create_audit_logs', MigrationPlan::parseName('001_create_audit_logs.php'));
    }

    public function testParsesFromAnAbsolutePath(): void
    {
        $path = '/var/www/plugins/algerian-commerce-core/migrations/012_create_shipments.php';

        self::assertSame(12, MigrationPlan::parseVersion($path));
        self::assertSame('create_shipments', MigrationPlan::parseName($path));
    }

    /** @return array<string, array{0: string}> */
    public static function invalidFilenameProvider(): array
    {
        return [
            'no version prefix' => ['create_audit_logs.php'],
            'two digits' => ['01_create_audit_logs.php'],
            'four digits' => ['0001_create_audit_logs.php'],
            'uppercase' => ['001_CreateAuditLogs.php'],
            'hyphenated' => ['001-create-audit-logs.php'],
            'not php' => ['001_create_audit_logs.sql'],
            'readme' => ['README.md'],
            'gitkeep' => ['.gitkeep'],
            'version zero' => ['000_nothing.php'],
        ];
    }

    /** @dataProvider invalidFilenameProvider */
    public function testIgnoresFilesThatDoNotMatchTheConvention(string $filename): void
    {
        self::assertNull(MigrationPlan::parseVersion($filename));
        self::assertSame([], MigrationPlan::pending([$filename], 0));
    }

    public function testPendingReturnsOnlyMigrationsNewerThanTheCurrentVersion(): void
    {
        $files = ['001_a.php', '002_b.php', '003_c.php'];

        self::assertSame($files, MigrationPlan::pending($files, 0));
        self::assertSame(['003_c.php'], MigrationPlan::pending($files, 2));
        self::assertSame([], MigrationPlan::pending($files, 3));
        self::assertSame([], MigrationPlan::pending($files, 99), 'a newer schema than disk is not a downgrade');
    }

    public function testPendingSortsByVersionNotByDirectoryOrder(): void
    {
        // glob() order is filesystem-dependent; applying 010 before 002 would
        // build the schema in the wrong sequence.
        $shuffled = ['010_j.php', '002_b.php', '001_a.php'];

        self::assertSame(
            ['001_a.php', '002_b.php', '010_j.php'],
            MigrationPlan::pending($shuffled, 0)
        );
    }

    public function testPendingSkipsUnparseableFilesWithoutSkippingValidOnes(): void
    {
        $files = ['README.md', '001_a.php', 'notes.txt', '002_b.php'];

        self::assertSame(['001_a.php', '002_b.php'], MigrationPlan::pending($files, 0));
    }

    public function testDuplicateVersionsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate migration version 002');

        MigrationPlan::pending(['002_b.php', '002_other.php'], 0);
    }

    public function testLatestVersion(): void
    {
        self::assertSame(3, MigrationPlan::latestVersion(['001_a.php', '003_c.php', '002_b.php']));
        self::assertSame(0, MigrationPlan::latestVersion([]));
        self::assertSame(0, MigrationPlan::latestVersion(['README.md']));
    }

    public function testRealMigrationsDirectoryMatchesTheDeclaredSchemaVersion(): void
    {
        // Guards the mistake that ships a migration without bumping DB_VERSION,
        // leaving installs that never run it.
        $files = glob(dirname(__DIR__, 2) . '/migrations/*.php') ?: [];

        self::assertSame(
            constant('AlgerianCommerce\DB_VERSION'),
            MigrationPlan::latestVersion($files),
            'DB_VERSION must equal the highest migration on disk'
        );
    }
}
