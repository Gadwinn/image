# Guidelines for AI Assistance

## Project Context

This is an image manipulation library (`gadwinn/image`) that provides a fluent API for common image operations using PHP's GD library. The library supports various image formats (PNG, JPEG, GIF, WEBP, etc.) and offers operations like resizing, cropping, rotating, and applying filters.

## Technical Architecture

- Server-side: PHP 8.2+, GD extension
- Design pattern: Fluent interface (method chaining), Driver pattern, Strategy pattern for image operations
- Framework: Standalone library with PSR-4 autoloading
- Structure:
  - Drivers (GD implementation, etc.)
  - Interfaces for abstraction
  - Enums for constants (Fit, Format, Position)
  - Exception handling for errors

## Best Practices

- Respect the existing project structure.
- Use modern PHP 8.2+ techniques.
- Optimize performance for image processing operations.
- Ensure proper resource handling (image resources must be released).
- Respect the PSR-12 coding style guidelines.
- Document code using PHPDoc comments.
- Avoid outdated or deprecated GD functions.
- Prioritize readability and maintainability over complex or magical solutions.
- Maintain proper error handling with specific exceptions.
- Support transparency where applicable in image operations.
- Use fluent interfaces consistently (method chaining).
- Follow naming conventions established in the project.

## Instructions for GitHub Copilot

- If you notice anything about codebase while using Copilot Edits, add it to 'GitHub Copilot comments' section of this file (`.github/copilot-instructions.md`).
- If you notice anything while using Chat, add it to the output as a 'GitHub Copilot comments' section.
- DO NOT REMOVE any comments in 'GitHub Copilot comments' section.
- Provide code, documentation, and comments in English.

## GitHub Copilot Comments

- Image resize operations support different fitting modes (CONTAIN, COVER, FILL) and positioning options.
- The library currently handles common image formats (JPEG, PNG, GIF, WEBP) with partial implementation for advanced formats.
- Some methods are declared but not fully implemented yet (crop, rotate, toFormat, etc.).
- Error handling uses specific exception types (ImageException, InvalidArgumentException).
- The project uses development tools like PHPStan, PHP_CodeSniffer, PHPUnit, and PHPMND.
- Transparency handling is implemented for operations where applicable.
