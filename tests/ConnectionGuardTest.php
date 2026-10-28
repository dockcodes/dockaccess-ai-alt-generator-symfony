<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Tests;

use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Symfony\AltText;
use Dock\AltGenerator\Symfony\Exception\GeneratorUnavailableException;
use Dock\AltGenerator\Symfony\Testing\FakeTransport;

final class ConnectionGuardTest extends BundleTestCase
{
    public function test_it_verifies_once_and_remembers_the_answer(): void
    {
        $altText = $this->boot()->get(AltText::class);

        $altText->generate($this->upload(self::png()));
        $altText->generate($this->upload(self::png()));

        $this->assertSame(1, $this->requestsTo('/integrations/verify'));
        $this->transport()->assertGenerated(2);
    }

    public function test_a_disconnected_application_stops_generating(): void
    {
        $altText = $this->boot()->get(AltText::class);
        $this->transport()->respond('/integrations/verify', FakeTransport::json(['success' => false, 'reason' => 'disconnected', 'detail' => 'Disconnected.'], 403));

        foreach ([1, 2] as $attempt) {
            try {
                $altText->generate($this->upload(self::png()));
                $this->fail('A disconnected application generated.');
            } catch (GeneratorUnavailableException $e) {
                $this->assertSame(GeneratorUnavailableException::DISCONNECTED, $e->getReason());
            }
        }

        $this->transport()->assertNothingGenerated();
        $this->assertSame(1, $this->requestsTo('/integrations/verify'));
    }

    public function test_a_paused_plan_stops_generating(): void
    {
        $altText = $this->boot()->get(AltText::class);
        $this->transport()->respond('/integrations/verify', FakeTransport::json(['alt_enabled' => false, 'token' => ['alt_enabled' => true, 'alt_paused' => true]]));

        $this->expectExceptionObject(new GeneratorUnavailableException(GeneratorUnavailableException::PAUSED));

        $altText->generate($this->upload(self::png()));
    }

    public function test_an_outage_of_the_account_does_not_stop_the_site(): void
    {
        $altText = $this->boot()->get(AltText::class);
        $this->transport()->respond('/integrations/verify', new TransportException('Could not reach the alt generator: timeout (cURL error 28).', true, 28));

        $this->assertSame('An image', $altText->generate($this->upload(self::png()))->alt()->text());
    }

    public function test_verification_can_be_switched_off(): void
    {
        $altText = $this->boot(['verify' => ['enabled' => false]])->get(AltText::class);

        $altText->generate($this->upload(self::png()));

        $this->assertSame(0, $this->requestsTo('/integrations/verify'));
    }

    public function test_without_a_stored_connection_there_is_nothing_to_verify(): void
    {
        $altText = $this->boot(['connection_secret' => null])->get(AltText::class);

        $altText->generate($this->upload(self::png()));

        $this->assertSame(0, $this->requestsTo('/integrations/verify'));
    }
}
