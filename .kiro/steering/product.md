# Product Overview

This is a Laravel wrapper package for Strapi CMS, providing a fluent PHP interface to interact with Strapi's REST API.

## Purpose

Simplifies integration between Laravel applications and Strapi headless CMS by:
- Abstracting HTTP requests to Strapi API endpoints
- Handling authentication (public, password, token-based)
- Managing data transformation and caching
- Supporting Strapi versions 4 and 5

## Key Features

- Fluent query builder for Strapi collections
- Automatic response flattening and data squashing
- Image/file URL conversion (relative to absolute)
- Built-in caching with Laravel's cache system
- Support for filtering, sorting, pagination, and population
- File upload handling with multipart requests
- Multiple authentication methods
