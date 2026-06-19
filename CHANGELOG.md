# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## Unreleased

### Added

- Bridge `App::enableDebug()` and `App::disableDebug()` to an optional profiler service.

### Changed

- Split package-manager actions, package-manager rendering, kernel configuration, runtime container cache, and core service registration into focused collaborators.
- Adopt shared SymPress QA tooling and PHP 8.5 package constraints.

### Fixed

- Normalize mixed metadata, route, hook, environment, and WordPress context inputs before using them as typed kernel state.
