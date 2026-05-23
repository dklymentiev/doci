# Contributing to DOCI

Thank you for your interest in contributing to DOCI!

## Getting Started

1. Fork the repository
2. Clone your fork locally
3. Install dependencies: `composer install`
4. Copy `.env.example` to `.env` and configure
5. Run tests: `composer test`

## Development Setup

### Requirements

- PHP 8.2+
- PostgreSQL 14+
- Docker (optional, for containerized development)

### Running Locally

```bash
# With Docker
docker-compose up -d

# Without Docker
php -S localhost:8080
```

## Code Style

- Follow PSR-12 coding standards
- Use meaningful variable and function names
- Add PHPDoc comments for public functions
- Use type hints for parameters and return types

## Pull Request Process

1. Create a feature branch from `main`
2. Make your changes
3. Add/update tests as needed
4. Run `composer test` to ensure tests pass
5. Run `composer analyse` for static analysis
6. Submit a pull request

### Commit Messages

Use clear, descriptive commit messages:

- `feat: add document tagging support`
- `fix: resolve path traversal in file upload`
- `docs: update API documentation`
- `test: add unit tests for validation`

## Reporting Issues

When reporting bugs, please include:

- PHP version
- PostgreSQL version
- Steps to reproduce
- Expected vs actual behavior
- Relevant log output

## Security

If you discover a security vulnerability, please email security@example.com instead of creating a public issue. See [SECURITY.md](SECURITY.md) for details.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
