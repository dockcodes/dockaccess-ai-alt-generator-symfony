# Changelog

## 1.0.0 - unreleased

First version of the Symfony bundle, on the PHP core 1.1.

- `dockaccess:alt:connect`, `status`, `generate` and `disconnect` console commands; the connection
  appears in the dockaccess.org account as `symfony`.
- `AltText` service: `generate()` for uploads, absolute paths and `ImageSource`,
  `generateFromFilesystem()` for Flysystem storages, `prepare()`, `usage()` with the day or month
  window of the plan.
- A connection check before generating, cached, that stops on a disconnected, switched off or
  paused site and lets generation through when dockaccess.org cannot be reached.
- `GenuineImage` validation constraint, messages in English and Polish.
- `GenerateAltText` Messenger message with `AltTextGeneratedEvent` and `AltTextFailedEvent`;
  only what can succeed later goes back to the retry.
- `Testing\FakeTransport` for application tests.
