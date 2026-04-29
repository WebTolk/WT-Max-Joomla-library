# Build

`lib_webtolk_wtmax/src` contains the permanent Joomla bootstrap class and the prepared SDK tree.
`lib_webtolk_wtmax/src/libraries/vendor` is generated from Composer packages by `build/release.php`.
Only the upstream `max` SDK is copied into the package tree; PSR interfaces stay in Joomla core.

Local packaging rule:
- install/update Composer dependencies first
- build locally through `build/release.php`

Example local flow:

```bash
composer update webtolk/max
php build/release.php package-from-lock --package=webtolk/max
```

Manual hotfix version example:

```bash
composer update webtolk/max
php build/release.php package-from-lock --package=webtolk/max --version=0.1.0.1
```

GitHub CI rule:
- GitHub Actions updates `webtolk/max` from upstream by Composer
- GitHub Actions runs `build/release.php package-from-lock`
- that command copies only `webtolk/max/src` into `lib_webtolk_wtmax/src/libraries/vendor/max/src`
- the same command reads version and time for `webtolk/max` from `composer.lock`
- by default, the same command stamps the project placeholders with that SDK version and date
- on `workflow_dispatch`, GitHub Actions may pass `package_version` to override only the Joomla package deploy version for a manual hotfix release
- the same command builds the ZIP
- GitHub Actions derives the release tag from the final Joomla package version
- GitHub Actions attaches the built ZIP directly to the GitHub Release for that Joomla package version
