<?php
// Heading
$_['heading_title']        = 'Spintax SEO';

// Text
$_['text_extension']       = 'Extensions';
$_['text_home']            = 'Home';
$_['text_success']         = 'Success: You have modified Spintax SEO settings!';
$_['text_edit']            = 'Edit Spintax SEO';
$_['text_bindings']        = 'Bindings';
$_['text_templates']       = 'Templates';
$_['text_test']            = 'Test (dry run — no writes)';
$_['text_bulk']            = 'Bulk Apply';
$_['text_bulk_warn']       = '— writes to the live catalog';
$_['text_binding_edit']    = 'Binding';
$_['text_template_edit']   = 'Template';
$_['text_no_bindings']     = 'No bindings configured yet.';
$_['text_no_templates']    = 'No templates yet.';
$_['text_enabled']         = 'Enabled';
$_['text_disabled']        = 'Disabled';
$_['text_blocked']         = 'Blocked';
$_['text_done']            = 'done';
$_['text_stale']           = 'Config changed since the Dry run — re-run Dry run.';
$_['text_valid']           = 'No errors.';
$_['text_locale_auto']     = '(auto from target language)';
$_['text_preview_note']    = 'One sample render — live output varies (that is spintax):';
$_['text_diff']            = 'Diff (current → rendered)';
$_['text_diff_same']       = '(identical — no change)';
$_['text_dependents_notice'] = 'binding(s) depend on this template — run Bulk Apply to propagate.';
$_['text_used_by_bindings'] = 'Used by bindings:';
$_['text_included_by']     = 'Included by templates:';
$_['text_lock_released']   = 'Walk lock released.';
$_['text_mode_seed']       = 'Seed once (fill empty only)';
$_['text_mode_regen']      = 'Regenerate on save';
$_['text_preserve']        = 'Preserve manual edits';
$_['text_clear']           = 'Clear target on empty render';
$_['text_confirm_delete']  = 'Delete this item?';
$_['text_confirm_init']    = 'Stamp the current target as the baseline signature. NO catalog write. Continue?';
$_['text_first_run']       = 'A ready-to-use demo binding is enabled below. It writes nothing on its own — scroll to <b>Bulk Apply</b>, run <b>Dry run</b> to preview, then <b>Apply</b> to fill empty meta descriptions. Turn on "Run automatically when the entity is saved" per binding when you want automatic updates.';
$_['text_settings']        = 'Settings';
$_['text_saved']           = 'Saved.';
$_['text_logs']            = 'Activity log';
$_['text_no_logs']         = 'No activity yet.';
$_['text_log_written']     = 'written';
$_['text_log_skipped']     = 'skipped';
$_['text_log_blocked']     = 'blocked';
$_['column_time']          = 'When';
$_['column_origin']        = 'Trigger';
$_['column_result']        = 'Result';
$_['button_refresh']       = 'Refresh';
$_['entry_credit']         = 'Show a small "SEO by Spintax" link in the storefront footer';
$_['help_credit']          = 'Off by default. When on, a single crawlable link to spintax.net is added to the storefront footer. The extension works exactly the same with this off — it is never required. Un-tick to remove it.';
$_['button_save_settings'] = 'Save settings';
$_['text_cron']            = 'Cron (auto-refresh)';
$_['text_cron_help']       = 'Add this path (prefixed with your storefront URL) to a system/web cron to auto-apply bindings when templates change and re-seed missing SEO URLs. It self-schedules (default hourly) — calling it more often is a cheap no-op.';
$_['text_cron_note']       = 'Keep the token secret. Example crontab: 0 * * * * wget -qO- "https://your-store.com/index.php?route=extension/module/spintax_seo/cron&token=..."';

// Column
$_['column_binding']       = 'Binding';
$_['column_entity']        = 'Entity';
$_['column_target']        = 'Target';
$_['column_source']        = 'Source';
$_['column_status']        = 'Status';
$_['column_action']        = 'Action';
$_['column_name']          = 'Name';
$_['column_locale']        = 'Locale';
$_['column_used_by']       = 'Used by';
$_['column_modified']      = 'Modified';

// Entry
$_['entry_entity']         = 'Entity';
$_['entry_target_kind']    = 'Target kind';
$_['entry_target_field']   = 'Target field';
$_['entry_source_mode']    = 'Source mode';
$_['entry_source']         = 'Template';
$_['entry_mode']           = 'Mode';
$_['text_mode_template']   = 'Template (same source for every entity)';
$_['text_mode_per_entity'] = 'Per entity (each entity can override in its "Spintax SEO" tab; falls back to the template)';
$_['help_source_mode']     = 'Template = one shared source. Per entity = each entity may set its own source on the product form; the template below is the fallback when it is blank.';
$_['help_template_fallback'] = 'Used as the source in Template mode, and as the fallback in Per-entity mode.';
$_['entry_seo_disambiguate'] = 'On keyword collision';
$_['text_seo_disambiguate'] = 'Append -<id> to keep a URL';
$_['help_seo_disambiguate'] = 'seo_keyword writes the entity\'s SEO-URL slug. If the rendered slug is already taken by another URL, off = skip (no URL for this entity), on = append -<entity_id> so it still gets a unique URL.';
$_['entry_attribute']      = 'Attribute';
$_['help_attribute']       = 'The product custom attribute (oc_product_attribute) to fill. If the attribute is later deleted, the binding safely skips it.';
$_['entry_store_scope']    = 'Stores';
$_['help_store_scope']     = 'Which stores to write SEO URLs for: ALL (every store the entity is assigned to) or a comma-separated list of store ids.';
$_['entry_flags']          = 'Behavior';
$_['entry_active']         = 'Active';
$_['entry_entity_id']      = 'Entity ID';
$_['entry_name']           = 'Name';
$_['entry_locale']         = 'Locale';
$_['entry_trigger']        = 'Trigger';
$_['text_trigger_save']    = 'Run automatically when the entity is saved';

// Help
$_['help_target']          = 'Only legal fields for the selected entity are offered; the pair is re-validated on save.';
$_['help_required_guard']  = 'Required/display columns (meta_title) are never cleared, regardless of the flag above.';
$_['help_trigger']         = 'Off = the binding only runs from Bulk Apply (nothing is written on an ordinary save). On = also seed/regenerate whenever the entity (product / category / information) is saved.';
$_['entry_cadence']        = 'Run on cron';
$_['text_cadence_off']     = 'Off (manual / save-event only)';
$_['text_cadence_auto']    = 'Auto (include in the cron run)';
$_['help_cadence']         = 'Off (default) = the cron never touches this binding — it runs only from Bulk Apply or the save event. Auto = the cron re-applies it when a template changes and re-seeds missing SEO URLs.';

// Button
$_['button_add_binding']   = 'Add Binding';
$_['button_add_template']  = 'Add Template';
$_['button_save']          = 'Save';
$_['button_cancel']        = 'Cancel';
$_['button_back']          = 'Back';
$_['button_test']          = 'Test';
$_['button_dry_run']       = 'Dry run';
$_['button_apply']         = 'Apply';
$_['button_release_lock']  = 'Force release lock';
$_['button_init_baseline'] = 'Initialize from current value';
$_['button_validate']      = 'Validate';
$_['button_preview']       = 'Preview';

// Error
$_['error_permission']     = 'Warning: You do not have permission to modify Spintax SEO!';
