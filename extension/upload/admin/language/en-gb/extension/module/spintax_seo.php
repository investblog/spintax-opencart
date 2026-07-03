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
$_['text_dependents_notice'] = 'binding(s) depend on this template — run Bulk Apply to propagate.';
$_['text_lock_released']   = 'Walk lock released.';
$_['text_mode_seed']       = 'Seed once (fill empty only)';
$_['text_mode_regen']      = 'Regenerate on save';
$_['text_preserve']        = 'Preserve manual edits';
$_['text_clear']           = 'Clear target on empty render';
$_['text_confirm_delete']  = 'Delete this item?';
$_['text_confirm_init']    = 'Stamp the current target as the baseline signature. NO catalog write. Continue?';
$_['text_first_run']       = 'A ready-to-use demo binding is enabled below. It writes nothing on its own — scroll to <b>Bulk Apply</b>, run <b>Dry run</b> to preview, then <b>Apply</b> to fill empty product meta descriptions. Turn on "Run on product save" per binding when you want automatic updates.';

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
$_['entry_source']         = 'Source';
$_['entry_mode']           = 'Mode';
$_['entry_flags']          = 'Behavior';
$_['entry_active']         = 'Active';
$_['entry_entity_id']      = 'Entity ID';
$_['entry_name']           = 'Name';
$_['entry_locale']         = 'Locale';
$_['entry_trigger']        = 'Trigger';
$_['text_trigger_save']    = 'Run automatically when a product is saved';

// Help
$_['help_target']          = 'Only legal fields for the selected entity are offered; the pair is re-validated on save.';
$_['help_required_guard']  = 'Required/display columns (meta_title) are never cleared, regardless of the flag above.';
$_['help_trigger']         = 'Off = the binding only runs from Bulk Apply (nothing is written on an ordinary product save). On = also seed/regenerate whenever a product is saved.';

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
