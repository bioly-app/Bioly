# Security Policy

## Reporting a vulnerability

Please do **not** open a public issue for security problems.

Report them privately:

- on GitHub: [Report a vulnerability](https://github.com/bioly-app/bioly/security/advisories/new) (Security tab of this repository)
- or by email to **security@benediktjany.de**

Please describe the problem and, if possible, how to reproduce it. You will get an answer within a few days. Once the issue is fixed, a new release is published and you will be credited if you like.

## Supported versions

Only the latest release receives security fixes. Installations update themselves from the backend (Updates).

## How releases are protected

Every release zip contains a manifest with the SHA-256 of each file, signed with the Bioly key (Ed25519). The updater installs nothing that is not signed with this key or that differs from its manifest.
