<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Validator;

use Dock\AltGenerator\Exception\InvalidImageException;
use Dock\AltGenerator\Symfony\AltText;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class GenuineImageValidator extends ConstraintValidator
{
    public function __construct(private readonly AltText $altText) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof GenuineImage) {
            throw new UnexpectedTypeException($constraint, GenuineImage::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (! $value instanceof File) {
            $this->context->buildViolation($constraint->notAFileMessage)
                ->setCode(GenuineImage::NOT_A_FILE_ERROR)
                ->addViolation();

            return;
        }

        try {
            $this->altText->prepare($value);
        } catch (InvalidImageException $e) {
            $this->context->buildViolation('dockaccess_alt.image.'.$e->getReason())
                ->setCode(GenuineImage::INVALID_IMAGE_ERROR)
                ->setCause($e)
                ->addViolation();
        }
    }
}
