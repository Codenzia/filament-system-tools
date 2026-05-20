# Changelog

All notable changes to `codenzia/filament-system-tools` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Background-worker cron detection** on the Queue & Scheduler page. The
  page now shows two status cards at the top — one for the Laravel scheduler,
  one for the queue worker — that report:
  - whether the host app actually *needs* each worker (any `Schedule::`
    events registered, any classes implementing `ShouldQueue` found under
    `app/Jobs`, `app/Events`, `app/Notifications`),
  - whether cron is currently running on the host (detected via heartbeat
    files the package touches via a one-line scheduled callback and a
    `Queue::looping()` listener), and
  - the exact cron line to paste into hPanel / cPanel when missing.
- New service `Codenzia\FilamentSystemTools\Services\BackgroundWorkerInspector`
  with a public `summary()` API so consumers can wire the same data into
  their own widgets / dashboards.
- New config block `background_workers.heartbeats_enabled` (default true,
  env `FILAMENT_SYSTEM_TOOLS_HEARTBEATS`) to opt out of the heartbeat
  registration if you prefer to run your own.

## [0.1.0] - 2026-05-20

### Added
- First tracked release. Early beta. Earlier history not recorded in this changelog — see git log for changes prior to release-tracker adoption.
