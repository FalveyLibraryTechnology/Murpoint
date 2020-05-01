# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 3.0 - 2020-05-01

### Added

- --log option for logging to file instead of console.
- Progress bar.

### Changed

- Refactored to use Symfony\Console framework.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Bug: last URL accessed was dropped when resuming interrupted job.

## 2.0.1 - 2020-03-11

### Added

- Nothing.

### Changed

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Bug: output filename was not defined when handling errors during a resumed job.

## 2.0 - 2019-06-19

### Added

- State save on exception, with --resume option.

### Changed

- Constructor parameters for Crawler class have changed to support new functionality.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.0 - 2016-08-01

Initial release.