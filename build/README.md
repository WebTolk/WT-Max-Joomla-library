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

GitHub CI rule:
- GitHub Actions updates `webtolk/max` from upstream by Composer
- GitHub Actions runs `build/release.php package-from-lock`
- that command copies only `webtolk/max/src` into `lib_webtolk_wtmax/src/libraries/vendor/max/src`
- the same command reads version and time for `webtolk/max` from `composer.lock`
- the same command stamps the project placeholders with that SDK version and date
- the same command builds the ZIP
- GitHub Actions attaches the built ZIP directly to the GitHub Release on tag runs
- Manual `workflow_dispatch` can also publish a release, but it requires an explicit `tag_name` input
