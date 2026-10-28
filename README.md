# dockaccess alt generator for Symfony

Alt text for your images from the [dockaccess.org](https://dockaccess.org) AI alt generator, in a
Symfony application: a connection to your dockaccess.org account, a service, a validation
constraint, a Messenger handler and console commands.

It is built on the [PHP core](https://github.com/dockcodes/dockaccess-ai-alt-generator), so it
inherits what the core guarantees:

- **only genuine images leave the server.** Every file is identified from its content and rebuilt
  before it is sent. A `shell.php` renamed to `photo.png`, PHP hidden in a JPEG comment, an SVG
  with a script or a decompression bomb is refused locally.
- **no metadata leaks.** GPS positions, camera serials and author names stay on the server.
- **the account key does not leak** into exceptions, logs, dumps or serialized messages.

## Requirements

- PHP 8.2 or newer, 64-bit, with `ext-curl` and `ext-json`
- Symfony 6.4, 7.4 or 8
- `ext-gd` with JPEG, PNG, GIF and WebP support, strongly recommended (see the core's README)
- a dockaccess.org account with the alt generator switched on for the site (the free plan works:
  1 site, 10 alts a day)

## Installation

```bash
composer require dockcodes/dockaccess-alt-generator-symfony
bin/console dockaccess:alt:connect
```

Symfony Flex registers the bundle; without Flex add it to `config/bundles.php`:

```php
Dock\AltGenerator\Symfony\DockAccessAltGeneratorBundle::class => ['all' => true],
```

`dockaccess:alt:connect` asks for:

1. the **site token** - in your dockaccess.org account, open the site, then the *ALT generator* tab;
2. the e-mail address and the password of the account. The password is used once and is not stored.

It connects this application as `symfony`, the way the WordPress, PrestaShop and Joomla plugins
connect, and writes three values to `.env.local` (created readable by its owner only):

```dotenv
DOCKACCESS_ALT_KEY=...
DOCKACCESS_ALT_SITE_TOKEN=...
DOCKACCESS_ALT_SECRET=...
```

The address of the application (`framework.router.default_uri`, or `--url`) has to be one of the
domains of the site in the account. A local address (`localhost`, a private IP) is accepted too,
for development.

The connected application then appears in the account, in the *ALT generator* tab of the site,
with the time it last checked in. The owner can disconnect it there.

Run `bin/console dockaccess:alt:status` to see the connection and how many alts are left.

For production, keep the three values in real environment variables or in the secrets vault
(`bin/console secrets:set DOCKACCESS_ALT_KEY`) - `--show` prints them instead of writing
`.env.local`. In CI, pass `--no-interaction` with `--token`, `--login` and the password in the
`DOCKACCESS_PASSWORD` environment variable.

## Generating

Inject `Dock\AltGenerator\Symfony\AltText`:

```php
use Dock\AltGenerator\Symfony\AltText;

public function upload(Request $request, AltText $altText): Response
{
    $alt = $altText->generate($request->files->get('photo'))->alt()->text();
    // ...
}
```

```php
// A file on a Flysystem storage (league/flysystem-bundle): local, S3, ...
$alt = $altText->generateFromFilesystem($defaultStorage, 'products/42.jpg')->alt()->text();

// With options
$result = $altText->generate($file, [
    'language' => 'de_AT',       // default: the request locale, then kernel.default_locale
    'tone' => 'descriptive',     // Dock\AltGenerator\Tone::ALL
    'context' => 'Product: oak dining table, 180 cm',
    'variants' => 3,             // 1 to 3 alternatives
]);
```

The alt is already plain text (no markup, control characters or bidirectional overrides), but it
is text from outside: escape it wherever it is printed, as Twig does.

Absolute paths are read only inside `allowed_directories` (default `%kernel.project_dir%/public/uploads`).

### In the background (Messenger)

```php
use Dock\AltGenerator\Symfony\Message\GenerateAltText;

$bus->dispatch(new GenerateAltText('products/42.jpg', 'default.storage', ['context' => $product->getName()], ['product_id' => $product->getId()]));
```

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            Dock\AltGenerator\Symfony\Message\GenerateAltText: async
```

The second argument is the service id of a Flysystem storage; storages tagged `flysystem.storage`
are known to the bundle, others can be listed in `dockaccess_alt_generator.storages`. A message
cannot reach any other service. With `null` the path is an absolute one inside `allowed_directories`.

Listen for the result:

```php
use Dock\AltGenerator\Symfony\Event\AltTextGeneratedEvent;
use Dock\AltGenerator\Symfony\Event\AltTextFailedEvent;

#[AsEventListener]
public function onGenerated(AltTextGeneratedEvent $event): void
{
    $this->products->find($event->context['product_id'])?->setImageAlt($event->alt);
    $this->entityManager->flush();
}

#[AsEventListener]
public function onFailed(AltTextFailedEvent $event): void
{
    $this->logger->notice('No alt for '.$event->path.': '.$event->reason);
}
```

Only what can succeed later goes back to Messenger's retry: the per minute cap (after the delay
the generator asks for, on Symfony 7.2+) and outages. A spent limit, a refused image or a
disconnected application are unrecoverable. `AltTextFailedEvent` comes once Messenger gives up.

### Validating uploads

```php
use Dock\AltGenerator\Symfony\Validator\GenuineImage;

#[Assert\NotNull, GenuineImage]
public ?UploadedFile $photo = null;
```

The constraint runs the core's inspection: the file is checked by its content, not by its
extension or MIME type. No connection is needed and nothing is sent. Messages are in English and
Polish (`translations/validators.*.php`, domain `validators`).

## Limits

The free plan counts alts per UTC day, paid plans per UTC calendar month (a month's alts can go in
one day).

```php
$usage = $altText->usage();

$usage->used();       // 15
$usage->limit();      // 2000
$usage->remaining();  // 1985
$usage->isMonthly();  // true on a paid plan
$usage->resetsAt();   // DateTimeImmutable, UTC
```

A spent limit throws `DailyLimitReachedException` (the name predates monthly plans;
`getPeriod()` says `day` or `month`).

## The connection check

Before generating, the bundle asks dockaccess.org whether the generator is still on for the site
and remembers the answer for 5 minutes (`verify.ttl`, in `cache.app`). Generation stops with
`GeneratorUnavailableException` when:

| `getReason()` | What happened | What to do |
|---|---|---|
| `disconnected` | The owner disconnected the application in the account | `bin/console dockaccess:alt:connect` |
| `alt_disabled` | The generator was switched off for the site | Switch it on in the account |
| `alt_paused` | The plan paused it (e.g. more sites than the plan allows) | Upgrade the plan |

When dockaccess.org cannot be reached, generation goes on (a warning is logged on the
`dockaccess_alt` channel): the generator itself still enforces the account's limits.

## Errors

Every exception implements `Dock\AltGenerator\Exception\AltGeneratorException`.

| Exception | Meaning |
|---|---|
| `InvalidImageException` | Refused locally, nothing was sent; `getReason()` says why |
| `GeneratorUnavailableException` | The account says no (see above), nothing was sent |
| `ConfigurationException` | Not connected, or a setting is invalid |
| `AuthenticationException` | The account key was not accepted - connect again |
| `DailyLimitReachedException` | The day's (free) or month's (paid) limit is spent |
| `RateLimitedException` | Too many requests per minute; `getRetryAfter()` |
| `ImageRejectedException` | The generator refused the image |
| `ServerException`, `TransportException` | Try again later |

## Commands

| Command | |
|---|---|
| `dockaccess:alt:connect` | Connect the application and write the keys to `.env.local` (`--overwrite` replaces another installation's connection, `--show` prints the keys) |
| `dockaccess:alt:status` | The connection, the sites with the generator and the usage |
| `dockaccess:alt:generate {path}` | One generation, to try the setup |
| `dockaccess:alt:disconnect` | Disconnect and remove the keys from `.env.local` |

## Configuration

Everything has a default; `bin/console config:dump-reference dockaccess_alt_generator` shows it all.

```yaml
# config/packages/dockaccess_alt_generator.yaml
dockaccess_alt_generator:
    defaults:
        language: ~            # the request locale
        tone: neutral
        set_ref: ~             # the site token
    allowed_directories:
        - '%kernel.project_dir%/public/uploads'
    image:
        mode: auto             # reencode sends only GD-written bytes
    verify:
        enabled: true
        ttl: 300
    http:
        proxy: '%env(default::DOCKACCESS_ALT_PROXY)%'
```

## Testing your application

Replace the transport in the test environment; images are still inspected as in production.

```yaml
# config/services_test.yaml
services:
    Dock\AltGenerator\Http\TransportInterface:
        class: Dock\AltGenerator\Symfony\Testing\FakeTransport
        factory: [Dock\AltGenerator\Symfony\Testing\FakeTransport, alts]
        arguments: ['A red cat on a windowsill']
        public: true
```

```php
$transport = static::getContainer()->get(TransportInterface::class);
$transport->assertGenerated(1);
```

`$transport->respond('/api/generate', FakeTransport::json([...], 429, [...]))` simulates a spent
limit or another refusal; `respond('/integrations/verify', ...)` a disconnected site.

## Development

```bash
composer install
composer test
composer analyse
```

## License

MIT, see [LICENSE](LICENSE).
