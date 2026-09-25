# BimarStop WordPress Plugin

Demo foundation for the BimarStop medical platform.

## Location

This plugin is intended to be installed at:

`wp-content/plugins/bimarstop`

## Current scope

- WordPress-native PHP plugin
- No Node.js dependency
- No external runtime service required for the demo foundation
- Feature modules will be added incrementally

## Architecture

The plugin will use WordPress authentication/roles where appropriate and MariaDB through WordPress APIs for persistent application data.

Sensitive patient documents and other private data must not be exposed as publicly guessable URLs.
