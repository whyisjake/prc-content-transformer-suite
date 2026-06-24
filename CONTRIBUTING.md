# Contributing

Thank you for your interest in contributing to the PRC Content Transformer Suite.

## Getting started

1. Fork this repository and clone your fork.
2. Create a feature branch: `git checkout -b feat/your-feature-name`
3. Install dependencies: `npm install`
4. Make your changes and build: `npm run build`
5. Open a pull request against `main`.

## Code standards

- **PHP:** PSR-12, namespaced under `PRC\Platform\*`
- **TypeScript/JavaScript:** Follow existing patterns in each plugin's `src/`
- **Commits:** Conventional Commits format (`feat:`, `fix:`, `chore:`, etc.)

## Security

Do **not** open public issues for security vulnerabilities. See [SECURITY.md](SECURITY.md).

## Credential hygiene

Never commit API keys, tokens, or credentials. The CI workflow checks for `PRC_PLATFORM_FONTAWESOME_TOKEN` and `@fortawesome/pro` references. Use environment variables or WordPress options for all secrets.

## License

By contributing, you agree that your contributions will be licensed under the GPL-2.0-or-later license.
