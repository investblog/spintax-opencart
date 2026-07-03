# extension/

Holds the OpenCart extension source (Phase 0+). Planned layout mirrors the
OpenCart docroot so it deploys straight into `../opencart/upload` during dev and
zips to `spintax_seo.ocmod.zip` for release:

```
extension/
  upload/
    system/library/spintax/                          # ported engine kernel + shims (Phase 0)
    admin/controller/extension/module/spintax_seo.php
    admin/model/extension/module/spintax_seo.php
    admin/view/template/extension/module/spintax_seo.twig
    admin/language/{en-gb,ru-ru,uk-ua}/extension/module/spintax_seo.php
    catalog/...                                       # opt-in storefront credit only
  install.xml                                          # OCMOD (menu injection, form-tab)
```

Nothing here yet — see `../docs/spec-opencart.md` §9–§11 for the design and
`../CLAUDE.md` for the resume checklist.
