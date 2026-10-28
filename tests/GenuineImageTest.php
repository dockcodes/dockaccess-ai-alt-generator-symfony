<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Tests;

use Dock\AltGenerator\Symfony\Validator\GenuineImage;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

final class GenuineImageTest extends BundleTestCase
{
    public function test_a_genuine_image_passes(): void
    {
        $validator = $this->boot()->get(ValidatorInterface::class);

        $this->assertCount(0, $validator->validate($this->upload(self::png()), new GenuineImage()));
    }

    public function test_a_script_with_an_image_name_fails(): void
    {
        $validator = $this->boot()->get(ValidatorInterface::class);

        $violations = $validator->validate($this->upload(self::phpPretendingToBePng(), 'photo.png'), new GenuineImage());

        $this->assertCount(1, $violations);
        $this->assertSame(GenuineImage::INVALID_IMAGE_ERROR, $violations[0]->getCode());
        $this->assertStringStartsWith('The file', (string) $violations[0]->getMessage());
    }

    public function test_the_message_is_translated(): void
    {
        $container = $this->boot();
        $translator = $container->get('translator');
        \assert($translator instanceof LocaleAwareInterface);
        $translator->setLocale('pl');

        $violations = $container->get(ValidatorInterface::class)->validate('not a file', new GenuineImage());

        $this->assertSame('Ta wartość musi być przesłanym obrazkiem.', (string) $violations[0]->getMessage());
    }

    public function test_both_languages_carry_the_same_messages(): void
    {
        $this->assertSame(
            array_keys(require __DIR__.'/../translations/validators.en.php'),
            array_keys(require __DIR__.'/../translations/validators.pl.php'),
        );
    }
}
