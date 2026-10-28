<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The upload is a genuine image the generator will accept - checked by its content with the same
 * inspection the generator runs before sending, not by its extension or MIME type:
 *
 *     #[Assert\NotNull, GenuineImage]
 *     public ?UploadedFile $photo = null;
 *
 * A PHP file renamed to .jpg, a JPEG with code in a comment, an SVG or a decompression bomb fails
 * here. No connection to dockaccess.org is needed and nothing is sent. Messages are translation
 * keys of the `validators` domain (English and Polish ship with the bundle).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class GenuineImage extends Constraint
{
    public const NOT_A_FILE_ERROR = 'b1f0d9e4-2c7a-4f6b-9d1e-6a8c3e5f7a01';
    public const INVALID_IMAGE_ERROR = 'b1f0d9e4-2c7a-4f6b-9d1e-6a8c3e5f7a02';

    protected const ERROR_NAMES = [
        self::NOT_A_FILE_ERROR => 'NOT_A_FILE_ERROR',
        self::INVALID_IMAGE_ERROR => 'INVALID_IMAGE_ERROR',
    ];

    /** For a value that is not a file; a refused image gets `dockaccess_alt.image.<reason>`. */
    public string $notAFileMessage = 'dockaccess_alt.image.not_a_file';

    /**
     * @param list<string>|null $groups
     */
    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
