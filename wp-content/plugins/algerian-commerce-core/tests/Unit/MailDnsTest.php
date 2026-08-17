<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Notifications\MailDns;
use PHPUnit\Framework\TestCase;

final class MailDnsTest extends TestCase
{
    public function testTheSendingDomainComesOffTheFromAddress(): void
    {
        self::assertSame('boutique.dz', MailDns::domainOf('commandes@boutique.dz'));
    }

    public function testTheDomainIsFoldedSoALookupIsNotCaseSensitive(): void
    {
        self::assertSame('boutique.dz', MailDns::domainOf('Commandes@Boutique.DZ'));
    }

    /** A trailing dot is a legal FQDN and is not part of the name we query. */
    public function testATrailingDotIsStripped(): void
    {
        self::assertSame('boutique.dz', MailDns::domainOf('commandes@boutique.dz.'));
    }

    public function testAnAddressWithAPlusTagStillResolvesToTheDomain(): void
    {
        self::assertSame('boutique.dz', MailDns::domainOf('commandes+campaigns@boutique.dz'));
    }

    /**
     * Anything that is not an address returns '', so the caller reports "no From
     * address" instead of querying DNS for a fragment.
     */
    public function testSomethingThatIsNotAnAddressHasNoDomain(): void
    {
        self::assertSame('', MailDns::domainOf('boutique.dz'));
        self::assertSame('', MailDns::domainOf('commandes@'));
        self::assertSame('', MailDns::domainOf(''));
    }

    public function testAnSpfRecordIsFound(): void
    {
        $verdict = MailDns::spfVerdict(['v=spf1 include:spf.brevo.com ~all']);

        self::assertSame(MailDns::OK, $verdict['status']);
        self::assertStringContainsString('spf.brevo.com', $verdict['detail']);
    }

    public function testAMissingSpfRecordIsReported(): void
    {
        self::assertSame(MailDns::MISSING, MailDns::spfVerdict([])['status']);
    }

    /** Unrelated TXT records at the apex are normal and are not SPF. */
    public function testAVerificationTxtRecordIsNotMistakenForSpf(): void
    {
        self::assertSame(
            MailDns::MISSING,
            MailDns::spfVerdict(['google-site-verification=abc123'])['status']
        );
    }

    /**
     * The failure this check exists for. A shop adding a second provider
     * publishes a second record instead of merging includes, and RFC 7208 makes
     * that a permerror — so the SPF that used to work stops working.
     */
    public function testTwoSpfRecordsAreAProblemRatherThanTwiceAsConfigured(): void
    {
        $verdict = MailDns::spfVerdict([
            'v=spf1 include:spf.brevo.com ~all',
            'v=spf1 include:_spf.google.com ~all',
        ]);

        self::assertSame(MailDns::PROBLEM, $verdict['status']);
        self::assertStringContainsString('merge', $verdict['detail']);
    }

    public function testADkimKeyIsFound(): void
    {
        self::assertSame(
            MailDns::OK,
            MailDns::dkimVerdict(['v=DKIM1; k=rsa; p=MIIBIjANBgkq'])['status']
        );
    }

    public function testAMissingDkimKeyIsReported(): void
    {
        self::assertSame(MailDns::MISSING, MailDns::dkimVerdict([])['status']);
    }

    /**
     * An empty `p=` publishes the key as *revoked* (RFC 6376 §3.6.1). It reads
     * as configured from every angle except the one that matters, which is why
     * it gets its own verdict rather than passing as present.
     */
    public function testARevokedDkimKeyIsNotReportedAsPublished(): void
    {
        $verdict = MailDns::dkimVerdict(['v=DKIM1; k=rsa; p=']);

        self::assertSame(MailDns::PROBLEM, $verdict['status']);
        self::assertStringContainsString('revoked', $verdict['detail']);
    }

    public function testAnEnforcingDmarcPolicyIsReportedAsEnforcing(): void
    {
        $verdict = MailDns::dmarcVerdict(['v=DMARC1; p=quarantine; rua=mailto:dmarc@boutique.dz']);

        self::assertSame(MailDns::OK, $verdict['status']);
        self::assertStringContainsString('enforcing', $verdict['detail']);
    }

    /**
     * p=none is the right place to start and is not a failure — but it enforces
     * nothing, and a shop that stops there has a record that does no work. The
     * verdict passes and says so.
     */
    public function testMonitoringOnlyPassesAndSaysItIsNotEnforcing(): void
    {
        $verdict = MailDns::dmarcVerdict(['v=DMARC1; p=none; rua=mailto:dmarc@boutique.dz']);

        self::assertSame(MailDns::OK, $verdict['status']);
        self::assertStringContainsString('monitoring only', $verdict['detail']);
    }

    public function testAMissingDmarcRecordIsReported(): void
    {
        self::assertSame(MailDns::MISSING, MailDns::dmarcVerdict([])['status']);
    }

    /** Without a p= tag the record is invalid and receivers ignore it. */
    public function testADmarcRecordWithNoPolicyTagIsInvalid(): void
    {
        self::assertSame(
            MailDns::PROBLEM,
            MailDns::dmarcVerdict(['v=DMARC1; rua=mailto:dmarc@boutique.dz'])['status']
        );
    }

    public function testTwoDmarcRecordsAreAProblem(): void
    {
        self::assertSame(
            MailDns::PROBLEM,
            MailDns::dmarcVerdict(['v=DMARC1; p=none', 'v=DMARC1; p=reject'])['status']
        );
    }

    /**
     * The property that keeps this command honest offline. A resolver that
     * cannot answer returns null, and null must never render as "missing" —
     * a container with no DNS would otherwise tell an operator to fix records
     * that are already correct.
     */
    public function testAFailedLookupIsUnknownAndNeverMissing(): void
    {
        self::assertSame(MailDns::UNKNOWN, MailDns::spfVerdict(null)['status']);
        self::assertSame(MailDns::UNKNOWN, MailDns::dkimVerdict(null)['status']);
        self::assertSame(MailDns::UNKNOWN, MailDns::dmarcVerdict(null)['status']);
    }

    public function testTheSelectorAndDmarcPrefixDecideWhichHostsAreQueried(): void
    {
        $queried = [];

        $dns = new MailDns(function (string $host) use (&$queried): array {
            $queried[] = $host;

            return [];
        });

        $dns->check('boutique.dz', 's1');

        self::assertSame(
            ['boutique.dz', 's1._domainkey.boutique.dz', '_dmarc.boutique.dz'],
            $queried
        );
    }

    public function testAnEmptySelectorFallsBackToBrevosRatherThanQueryingAMalformedHost(): void
    {
        $queried = [];

        $dns = new MailDns(function (string $host) use (&$queried): array {
            $queried[] = $host;

            return [];
        });

        $dns->check('boutique.dz', '   ');

        self::assertContains('brevo._domainkey.boutique.dz', $queried);
    }

    public function testEveryRowNamesTheHostItQueried(): void
    {
        $dns = new MailDns(static fn (string $host): array => []);

        foreach ($dns->check('boutique.dz') as $row) {
            self::assertNotSame('', $row['host']);
            self::assertContains($row['record'], ['SPF', 'DKIM', 'DMARC']);
        }
    }
}
