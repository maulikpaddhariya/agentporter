# AgentPorter

AgentPorter is a WordPress and WooCommerce plugin that helps site owners **see, control, improve and serve** AI traffic and AI agents:

- **See** which AI tools send visitors and which AI crawlers visit.
- **Control** which AI bots may crawl, by purpose (search, training, user-triggered agents).
- **Improve** how ready your pages are to be cited in AI answers.
- **Serve** AI agents a small, owner-chosen set of read-only abilities through the WordPress Abilities API and MCP Adapter.

Everything is off by default. Nothing is exposed to agents and no outbound request is made until the site owner opts in.

> Status: early development (Phase 0 — foundation). Not ready for production sites.

## Requirements

- WordPress 6.9 or later
- PHP 8.1 or later (tested on 8.1–8.5)
- WooCommerce 10.9 or later (optional)

## Development

```bash
composer install && npm ci
composer lint      # coding standards
composer analyse   # static analysis
composer test      # PHPUnit
npm run lint
npm run test:unit
```

## Security

Please report security issues privately through GitHub's "Report a vulnerability" button on this repository rather than opening a public issue.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
