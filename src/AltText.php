<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Symfony;

use Closure;
use Dock\AltGenerator\AltGenerator;
use Dock\AltGenerator\Exception\InvalidImageException;
use Dock\AltGenerator\GenerateOptions;
use Dock\AltGenerator\Image\ImagePolicy;
use Dock\AltGenerator\Image\ImageSanitizer;
use Dock\AltGenerator\Image\ImageSource;
use Dock\AltGenerator\Image\PreparedImage;
use Dock\AltGenerator\Language;
use Dock\AltGenerator\Result\GenerationResult;
use Dock\AltGenerator\Result\Usage;
use Dock\AltGenerator\Symfony\Support\ConfigFactory;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use SplFileInfo;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The generator as a Symfony service - inject `Dock\AltGenerator\Symfony\AltText`:
 *
 *     $alt = $altText->generate($request->files->get('photo'))->alt()->text();
 *     $alt = $altText->generateFromFilesystem($defaultStorage, 'products/42.jpg', ['context' => $product->getName()])->alt()->text();
 *
 * Every image is verified and sanitized by the core before it is sent; a file that is not a genuine
 * image is refused with InvalidImageException and never leaves the server.
 */
class AltText
{
    private ?AltGenerator $generator = null;

    /**
     * @param Closure(): AltGenerator $generatorFactory resolved on first use, so the bundle works before it is connected
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private readonly Closure $generatorFactory,
        private readonly ConnectionGuard $guard,
        private readonly ImagePolicy $policy,
        private readonly array $settings,
        private readonly string $defaultLocale = 'en',
        private readonly ?RequestStack $requestStack = null,
    ) {}

    /**
     * Generates alt text for an uploaded file, an absolute path inside `allowed_directories`, a
     * File, or an ImageSource built by hand.
     *
     * @param GenerateOptions|array{language?: string, tone?: string, context?: string, variants?: int, set_ref?: string}|null $options
     *
     * @throws \Dock\AltGenerator\Exception\AltGeneratorException see the core's README, plus GeneratorUnavailableException
     */
    public function generate(ImageSource|SplFileInfo|string $image, GenerateOptions|array|null $options = null): GenerationResult
    {
        $source = $this->source($image);
        // Built first: an application that was never connected hears that, not a verification failure.
        $generator = $this->generator();

        $this->guard->ensureActive();

        return $generator->generate($source, $this->options($options));
    }

    /**
     * Generates alt text for a file on a Flysystem storage - local, S3 or any other adapter.
     *
     * @param GenerateOptions|array{language?: string, tone?: string, context?: string, variants?: int, set_ref?: string}|null $options
     */
    public function generateFromFilesystem(FilesystemOperator $filesystem, string $path, GenerateOptions|array|null $options = null): GenerationResult
    {
        return $this->generate($this->sourceFromFilesystem($filesystem, $path), $options);
    }

    /**
     * Verifies and sanitizes without sending anything - no connection needed. For refusing a bad
     * file at upload time; the GenuineImage constraint does this.
     *
     * @throws InvalidImageException
     */
    public function prepare(ImageSource|SplFileInfo|string $image): PreparedImage
    {
        return (new ImageSanitizer($this->policy))->prepare($this->source($image));
    }

    /**
     * The account's usage in the current window: the day on the free plan, the month on paid plans.
     */
    public function usage(): Usage
    {
        return $this->generator()->usage();
    }

    /**
     * The configured defaults with the given overrides applied. The language follows the locale of
     * the current request (or `kernel.default_locale`) unless the configuration or the call names one.
     *
     * @param GenerateOptions|array{language?: string, tone?: string, context?: string, variants?: int, set_ref?: string}|null $options
     */
    public function options(GenerateOptions|array|null $options = null): GenerateOptions
    {
        if ($options instanceof GenerateOptions) {
            return $options;
        }

        $defaults = (array) ($this->settings['defaults'] ?? []);
        $options = array_merge(array_filter($defaults, static fn ($value) => $value !== null && $value !== ''), $options ?? []);

        $locale = ConfigFactory::string($options['language'] ?? null)
            ?? $this->requestStack?->getCurrentRequest()?->getLocale()
            ?? $this->defaultLocale;
        $fallback = ConfigFactory::string($defaults['fallback_language'] ?? null) ?? 'en';

        $result = GenerateOptions::create()
            ->withLanguage(Language::normalize($locale, $fallback))
            ->withTone((string) ($options['tone'] ?? 'neutral'))
            ->withVariants((int) ($options['variants'] ?? 1));

        if (($context = ConfigFactory::string($options['context'] ?? null)) !== null) {
            $result = $result->withContext($context);
        }

        // The site token keeps every image of this application in one set of the account, as the CMS plugins do.
        $setRef = ConfigFactory::string($options['set_ref'] ?? null) ?? ConfigFactory::string($this->settings['site_token'] ?? null);

        return $setRef === null ? $result : $result->withSetRef($setRef);
    }

    /**
     * @throws InvalidImageException
     */
    public function source(ImageSource|SplFileInfo|string $image): ImageSource
    {
        if ($image instanceof ImageSource) {
            return $image;
        }

        $maxBytes = $this->policy->maxBytes();

        if ($image instanceof UploadedFile) {
            if (! $image->isValid()) {
                throw new InvalidImageException(InvalidImageException::EMPTY_FILE, 'The upload did not arrive in full.');
            }

            // Read through the framework (test uploads are not is_uploaded_file()), size checked first.
            if ((int) $image->getSize() > $maxBytes) {
                throw new InvalidImageException(InvalidImageException::TOO_LARGE, 'The image is larger than allowed.');
            }

            $bytes = file_get_contents($image->getPathname());

            if ($bytes === false) {
                throw new InvalidImageException(InvalidImageException::NOT_ALLOWED, 'The upload cannot be read.');
            }

            return ImageSource::fromBytes($bytes, $image->getClientOriginalName(), $maxBytes);
        }

        $path = $image instanceof SplFileInfo ? $image->getPathname() : $image;

        return ImageSource::fromFile($path, $this->allowedDirectories(), $maxBytes);
    }

    /**
     * @throws InvalidImageException
     */
    public function sourceFromFilesystem(FilesystemOperator $filesystem, string $path): ImageSource
    {
        $maxBytes = $this->policy->maxBytes();

        try {
            if (! $filesystem->fileExists($path)) {
                throw new InvalidImageException(InvalidImageException::NOT_ALLOWED, 'The image does not exist on the storage.');
            }

            if ($filesystem->fileSize($path) > $maxBytes) {
                throw new InvalidImageException(InvalidImageException::TOO_LARGE, 'The image is larger than allowed.');
            }

            $bytes = $filesystem->read($path);
        } catch (FilesystemException) {
            throw new InvalidImageException(InvalidImageException::NOT_ALLOWED, 'The image cannot be read from the storage.');
        }

        return ImageSource::fromBytes($bytes, basename($path), $maxBytes);
    }

    public function guard(): ConnectionGuard
    {
        return $this->guard;
    }

    private function generator(): AltGenerator
    {
        return $this->generator ??= ($this->generatorFactory)();
    }

    /**
     * @return list<string>
     */
    private function allowedDirectories(): array
    {
        return array_values(array_filter(
            array_map(static fn ($dir) => ConfigFactory::string($dir), (array) ($this->settings['allowed_directories'] ?? [])),
            static fn (?string $dir) => $dir !== null,
        ));
    }
}
