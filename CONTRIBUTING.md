# Contributing to Laravel Model Counter

First off, thank you for considering contributing to Laravel Model Counter! It's people like you that make this package better for everyone.

## Code of Conduct

This project and everyone participating in it is governed by our Code of Conduct. By participating, you are expected to uphold this code.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues as you might find out that you don't need to create one. When you are creating a bug report, please include as many details as possible:

* **Use a clear and descriptive title**
* **Describe the exact steps which reproduce the problem**
* **Provide specific examples to demonstrate the steps**
* **Describe the behavior you observed after following the steps**
* **Explain which behavior you expected to see instead and why**
* **Include your Laravel and PHP versions**

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, please include:

* **Use a clear and descriptive title**
* **Provide a step-by-step description of the suggested enhancement**
* **Provide specific examples to demonstrate the steps**
* **Describe the current behavior and explain which behavior you expected to see instead**
* **Explain why this enhancement would be useful**

### Pull Requests

* Fill in the required template
* Do not include issue numbers in the PR title
* Follow the PHP coding standards (PSR-12)
* Include thoughtfully-worded, well-structured tests
* Document new code based on the Documentation Styleguide
* End all files with a newline

## Development Setup

### Prerequisites

* PHP 8.3 or higher
* Composer
* Redis (for testing)

### Setup

1. Fork and clone the repository
```bash
git clone https://github.com/your-username/model-counter.git
cd model-counter
```

2. Install dependencies
```bash
composer install
```

3. Run tests
```bash
composer test
```

## Coding Standards

This project follows PSR-12 coding standards. Please ensure your code adheres to these standards.

### PHP Code Style

* Use type hints for all parameters and return types
* Use strict types declaration at the top of each PHP file
* Follow PSR-4 autoloading standards
* Write descriptive variable and method names
* Keep methods focused and small

### Testing

* Write tests for all new features
* Ensure all tests pass before submitting PR
* Aim for high code coverage
* Use Pest PHP testing framework
* Follow the Arrange-Act-Assert pattern

```php
public function test_can_increment_counter(): void
{
    // Arrange
    $user = User::factory()->create();
    
    // Act
    $user->incrementCounter('downloads');
    
    // Assert
    $this->assertEquals(1, $user->counter('downloads'));
}
```

## Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test-coverage

# Run specific test file
./vendor/bin/pest tests/Feature/CounterTest.php
```

## Documentation

* Keep the README.md up to date
* Document all public methods
* Include usage examples for new features
* Update CHANGELOG.md for all changes

## Git Commit Messages

* Use the present tense ("Add feature" not "Added feature")
* Use the imperative mood ("Move cursor to..." not "Moves cursor to...")
* Limit the first line to 72 characters or less
* Reference issues and pull requests liberally after the first line

### Example

```
Add support for custom Redis connections

- Allow users to specify custom Redis connection
- Update documentation with configuration examples
- Add tests for custom connection handling

Fixes #123
```

## Release Process

1. Update CHANGELOG.md
2. Update version in composer.json
3. Create a new tag
4. Push tag to GitHub
5. Create release notes on GitHub

## Questions?

Feel free to open an issue with your question or reach out to the maintainers directly.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

