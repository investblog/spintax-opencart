# extension/

The OpenCart 3.x extension source. It mirrors the OpenCart docroot, so it deploys
straight into a store's `upload/` and zips to `spintax_seo.ocmod.zip` for release.

```
extension/
  upload/
    system/library/spintax/     # engine kernel + shims; entity/binding/apply/cron/log engine
    admin/{controller,model,view,language}/extension/module/spintax_seo   # admin MVC-L
    catalog/...                 # storefront: opt-in credit + the tokenized cron endpoint
  install.xml                   # OCMOD (admin-menu injection, product form-tab)
  tests/                        # PHPUnit — pure-kernel + live-DB gates (self-skip off a stand)
  composer.json  phpunit.xml.dist
```

See the repository `README.md` for install/usage and `docs/ARCHITECTURE.md` for the
design. Run the suite from this directory with `composer install && vendor/bin/phpunit`.
