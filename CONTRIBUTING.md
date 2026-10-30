# Contributing

Contributions are welcome: bug reports, fixes, documentation and ideas.

## Before you start

- **Bugs:** open an [issue](https://github.com/ArvidDeJong/laravel-uploads/issues/new/choose) with the steps to reproduce.
- **Features:** open an issue first, so we can agree it fits before you build it.
- **Security issues:** don't open an issue; see [SECURITY.md](SECURITY.md).

## Development

```bash
git clone https://github.com/ArvidDeJong/laravel-uploads.git
cd laravel-uploads
composer install

composer test      # Pest
composer lint      # Pint, check only (composer format fixes)
composer analyse   # Larastan, level 8
```

CI runs the tests on PHP 8.2 to 8.4 with Laravel 11, 12 and 13, on the lowest and the latest dependencies. The tests draw their images with GD and build their PDFs by hand; the PDF rendering tests skip themselves when neither Imagick nor `pdftoppm` is available.

## Pull requests

- Add or update tests for every change in behaviour.
- Keep the public API compatible within 1.x: the `Upload` model and its methods, the trait, the action, the job, the event, the route name, the policy, the component names and parameters, the config keys and the view names. Deprecate first and remove in 2.0.
- Read config through `Darvis\Uploads\Support\UploadsConfig`; never call `config('uploads.…')` elsewhere in the package.
- Config keys stay in alphabetical order.
- No Doctrine DBAL and no driver-specific SQL: use the native schema builder, because host apps run their tests on SQLite.
- Write code, comments, messages and docs in English. Texts for end users go through `__()`, with a key in `lang/nl.json` for every new string.
- Update `docs/`, `CHANGELOG.md` (under `Unreleased`) and `resources/boost/` when users will notice the change.
- The documentation in `docs/` is also the website. Don't write `{{ }}` or `{% %}` in code examples; Jekyll would render it.

## Code of conduct

This project follows the [Contributor Covenant](https://github.com/ArvidDeJong/.github/blob/main/CODE_OF_CONDUCT.md).
