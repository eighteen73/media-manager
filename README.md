# Media Manager

Native media folders, private media, shared multisite media, and safety-first cleanup for WordPress.

V1A ships public hierarchical folders for the Media Library and media modal.

## Requirements

- WordPress 6.8+
- PHP 7.4+
- Node 20+ for development builds

## Development

```bash
composer install
npm install
npm run build
npm run env:start
npm run test:php
npm run test:unit
npm run test:e2e
npm run lint
```

## Documentation

- [Public folders](docs/public-folders.md)
- [ADR 0001: wp.media integration](docs/decisions/0001-wp-media-integration.md)

## License

GPL-2.0-or-later
