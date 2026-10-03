<?php
function settingValue(array $settings, string $key, string $default = ''): string
{
    return (string) ($settings[$key]['value'] ?? $default);
}
$oldSettings = flash('_old', []);
$mailInputReturned = is_array($oldSettings) && array_key_exists('mail_driver', $oldSettings);
$mailAuthChecked = $mailInputReturned ? array_key_exists('mail_auth', $oldSettings) : !empty($mailSettings['auth']);
$mailDriver = (string) old('mail_driver', $mailSettings['driver']);
$mailEncryption = (string) old('mail_encryption', $mailSettings['encryption_option']);
?>
<div class="page-heading admin-heading">
    <div>
        <span class="eyebrow">Configuration</span>
        <h1>College, email &amp; admission settings</h1>
        <p>Institution identity, secure email delivery, privacy controls, payment instructions and intake setup.</p>
    </div>
</div>

<form method="post" action="<?= url('admin/settings') ?>" class="settings-layout" data-section-workspace data-mail-settings>
    <?= csrf_field() ?>
    <nav class="settings-nav" data-section-tabs aria-label="Settings sections">
        <a href="#identity" class="active">College identity</a>
        <a href="#email">Email &amp; SMTP</a>
        <a href="#admission">Admission policy</a>
        <a href="#payment">Payment details</a>
        <a href="#cycles">Cycles & programmes</a>
        <a href="#security">Security & privacy</a>
    </nav>

    <div class="settings-content">
        <section class="card settings-section" id="identity" data-section-panel>
            <div class="section-title"><div><span class="eyebrow">Public identity</span><h2>College details</h2></div></div>
            <div class="form-grid">
                <label><span>College name</span><input name="college_name" value="<?= e(old('college_name', settingValue($settings, 'college_name', 'Netaji College of Pharmacy'))) ?>"></label>
                <label><span>Short name</span><input name="college_short_name" value="<?= e(old('college_short_name', settingValue($settings, 'college_short_name', 'NCP'))) ?>"></label>
                <label><span>Public email</span><input type="email" name="college_email" value="<?= e(old('college_email', settingValue($settings, 'college_email'))) ?>"></label>
                <label><span>Public phone</span><input name="college_phone" value="<?= e(old('college_phone', settingValue($settings, 'college_phone'))) ?>"></label>
                <label class="full"><span>Address</span><textarea name="college_address" rows="3"><?= e(old('college_address', settingValue($settings, 'college_address'))) ?></textarea></label>
                <label><span>Brand colour</span><input type="color" name="primary_color" value="<?= e(old('primary_color', settingValue($settings, 'primary_color', '#075e61'))) ?>"></label>
            </div>
            <div class="compliance-callout"><b>Regulatory content remains unclaimed.</b><p>Add only verified affiliation, university, PCI/AICTE approval and sanctioned-intake data supported by official records.</p></div>
        </section>

        <section class="card settings-section mail-settings-section" id="email" data-section-panel>
            <div class="section-title mail-section-title">
                <div><span class="eyebrow">Transactional communication</span><h2>Email &amp; SMTP delivery</h2><p>Connect the portal to the institution's authenticated mail server for verification links, password resets, staff sign-in codes and operational messages.</p></div>
                <span class="mail-mode-badge <?= $mailDriver === 'smtp' ? 'is-live' : 'is-log' ?>" data-mail-mode-badge><?= $mailDriver === 'smtp' ? 'SMTP enabled' : 'Local log mode' ?></span>
            </div>

            <div class="mail-setup-hero">
                <div class="mail-status-orb <?= !empty($mailSettings['complete']) ? 'is-ready' : 'is-pending' ?>" aria-hidden="true"><span>✉</span></div>
                <div class="mail-setup-copy">
                    <span class="eyebrow">Current delivery status</span>
                    <h3><?= !empty($mailSettings['complete']) ? 'SMTP is configured for live delivery' : 'Complete and test SMTP before launch' ?></h3>
                    <p><?= !empty($mailSettings['complete']) ? 'The application can use the saved SMTP connection. Send a test after any server or credential change.' : 'Local log mode is safe for development, but authentication messages containing secrets are deliberately suppressed until SMTP is enabled.' ?></p>
                </div>
                <div class="mail-config-source"><span>Configuration source</span><b><?= $mailSettings['source'] === 'admin_settings' ? 'Encrypted admin settings' : '.env fallback' ?></b><small>Admin settings take precedence after the first save.</small></div>
            </div>

            <?php if (!empty($mailSettings['credential_error'])): ?>
                <div class="warning-box"><span>!</span><div><b>Saved credential needs attention</b><p><?= e($mailSettings['credential_error']) ?></p></div></div>
            <?php endif ?>

            <div class="mail-delivery-choice">
                <label class="mail-driver-card">
                    <span>Delivery mode</span>
                    <select name="mail_driver" data-mail-driver>
                        <option value="log"<?= selected($mailDriver, 'log') ?>>Local log — development only</option>
                        <option value="smtp"<?= selected($mailDriver, 'smtp') ?>>SMTP — send real email</option>
                    </select>
                    <small>Authentication emails are never written to the local message body log.</small>
                    <?= error('mail_driver') ? '<small class="field-error">' . e(error('mail_driver')) . '</small>' : '' ?>
                </label>
                <div class="mail-delivery-explainer" data-mail-mode-copy>
                    <b><?= $mailDriver === 'smtp' ? 'Live delivery selected' : 'Development safety mode' ?></b>
                    <span><?= $mailDriver === 'smtp' ? 'Messages will be delivered through the server below after validation.' : 'Non-sensitive messages are logged; OTPs and account links are suppressed.' ?></span>
                </div>
            </div>

            <fieldset class="mail-fieldset" data-smtp-fields>
                <legend>SMTP server</legend>
                <div class="smtp-settings-grid">
                    <label class="span-2"><span>SMTP host</span><input name="mail_host" value="<?= e(old('mail_host', $mailSettings['host'])) ?>" placeholder="smtp.your-provider.com" autocomplete="off" data-smtp-required><small>Host name only—do not include https:// or a path.</small><?= error('mail_host') ? '<small class="field-error">' . e(error('mail_host')) . '</small>' : '' ?></label>
                    <label><span>Port</span><input type="number" min="1" max="65535" name="mail_port" value="<?= e(old('mail_port', $mailSettings['port'])) ?>" inputmode="numeric" data-smtp-required><?= error('mail_port') ? '<small class="field-error">' . e(error('mail_port')) . '</small>' : '' ?></label>
                    <label><span>Connection security</span><select name="mail_encryption"><option value="tls"<?= selected($mailEncryption, 'tls') ?>>STARTTLS — usually port 587</option><option value="ssl"<?= selected($mailEncryption, 'ssl') ?>>Implicit TLS — usually port 465</option><option value="none"<?= selected($mailEncryption, 'none') ?>>None — local trusted server only</option></select><?= error('mail_encryption') ? '<small class="field-error">' . e(error('mail_encryption')) . '</small>' : '' ?></label>
                    <label><span>Connection timeout</span><div class="input-suffix"><input type="number" min="5" max="60" name="mail_timeout" value="<?= e(old('mail_timeout', $mailSettings['timeout'])) ?>"><i>seconds</i></div><?= error('mail_timeout') ? '<small class="field-error">' . e(error('mail_timeout')) . '</small>' : '' ?></label>
                </div>
            </fieldset>

            <fieldset class="mail-fieldset smtp-credential-fieldset" data-smtp-credentials>
                <legend>Authentication</legend>
                <label class="decision-option mail-auth-toggle"><input type="checkbox" name="mail_auth" value="1" <?= checked($mailAuthChecked) ?> data-mail-auth><span><b>Authenticate with the SMTP server</b><small>Keep enabled for Gmail, Microsoft 365 and most hosted mail services.</small></span></label>
                <div class="smtp-settings-grid">
                    <label><span>SMTP username</span><input name="mail_username" value="<?= e(old('mail_username', $mailSettings['username'])) ?>" autocomplete="username" placeholder="admissions@college.example" data-smtp-auth-required><?= error('mail_username') ? '<small class="field-error">' . e(error('mail_username')) . '</small>' : '' ?></label>
                    <label><span>SMTP password or app password</span><div class="password-control"><input type="password" name="mail_password" value="" autocomplete="new-password" placeholder="<?= !empty($mailSettings['password_configured']) ? 'Saved — enter only to replace' : 'Enter the SMTP password' ?>" data-mail-password data-password-configured="<?= !empty($mailSettings['password_configured']) ? '1' : '0' ?>"><button type="button" data-mail-password-toggle aria-label="Show or hide SMTP password" aria-pressed="false">Show</button></div><small><?= !empty($mailSettings['password_configured']) ? 'A password is stored securely. It is never displayed again.' : 'No password is currently stored.' ?></small><?= error('mail_password') ? '<small class="field-error">' . e(error('mail_password')) . '</small>' : '' ?></label>
                    <label class="check-control full clear-mail-password"><input type="checkbox" name="mail_clear_password" value="1" data-mail-clear-password><span>Remove the stored SMTP password when saving</span></label>
                </div>
            </fieldset>

            <fieldset class="mail-fieldset">
                <legend>Sender identity</legend>
                <div class="smtp-settings-grid">
                    <label><span>From email address</span><input type="email" name="mail_from_address" value="<?= e(old('mail_from_address', $mailSettings['from_address'])) ?>" autocomplete="email" required><small>Use an address the SMTP account is permitted to send as.</small><?= error('mail_from_address') ? '<small class="field-error">' . e(error('mail_from_address')) . '</small>' : '' ?></label>
                    <label><span>From name</span><input name="mail_from_name" value="<?= e(old('mail_from_name', $mailSettings['from_name'])) ?>" required><small>Shown beside the sender address in the applicant's inbox.</small><?= error('mail_from_name') ? '<small class="field-error">' . e(error('mail_from_name')) . '</small>' : '' ?></label>
                </div>
            </fieldset>

            <div class="mail-security-grid">
                <div><i>✓</i><span><b>Password encrypted at rest</b><small>AES-256-GCM with the application's APP_KEY.</small></span></div>
                <div><i>✓</i><span><b>Secrets never returned</b><small>The saved SMTP password is not rendered in HTML or audit logs.</small></span></div>
                <div><i>✓</i><span><b>TLS verification retained</b><small>The interface does not provide an unsafe certificate-bypass option.</small></span></div>
            </div>

            <div class="mail-test-panel">
                <div><span class="eyebrow">Connection check</span><h3>Save and send a real test email</h3><p>This stores the configuration first, then uses the same delivery path as applicant and staff email.</p></div>
                <label><span>Test recipient</span><input type="email" name="mail_test_recipient" value="<?= e(old('mail_test_recipient', auth_user()['email'] ?? '')) ?>" placeholder="your-address@example.com"><?= error('mail_test_recipient') ? '<small class="field-error">' . e(error('mail_test_recipient')) . '</small>' : '' ?></label>
                <div class="mail-test-actions"><button class="button button-primary" type="submit" formaction="<?= url('admin/settings/email/test') ?>" data-mail-test>Save & send test email</button><a class="button button-outline" href="<?= url('admin/mail-log') ?>">Open delivery log</a></div>
            </div>
        </section>

        <section class="card settings-section" id="admission" data-section-panel>
            <div class="section-title"><div><span class="eyebrow">Sensitive data</span><h2>Aadhaar collection stage</h2></div></div>
            <label><span>Collection policy</span><select name="aadhaar_collection_stage"><option value="disabled"<?= selected(old('aadhaar_collection_stage', settingValue($settings, 'aadhaar_collection_stage')), 'disabled') ?>>Do not collect</option><option value="application"<?= selected(old('aadhaar_collection_stage', settingValue($settings, 'aadhaar_collection_stage')), 'application') ?>>Initial application (requires legal review)</option><option value="post_selection"<?= selected(old('aadhaar_collection_stage', settingValue($settings, 'aadhaar_collection_stage')), 'post_selection') ?>>After selection</option><option value="admission"<?= selected(old('aadhaar_collection_stage', settingValue($settings, 'aadhaar_collection_stage')), 'admission') ?>>At admission</option><option value="configurable"<?= selected(old('aadhaar_collection_stage', settingValue($settings, 'aadhaar_collection_stage')), 'configurable') ?>>Configured per intake</option></select></label>
            <div class="warning-box"><span>!</span><div><b>Compliance acknowledgement required</b><p>Collecting Aadhaar is not automatically lawful for every institution or purpose. Confirm authority, purpose, consent, access and retention with qualified counsel before enabling it.</p></div></div>
            <label class="check-control"><input type="checkbox" name="aadhaar_compliance_ack" value="1"><span>I confirm that the institution has documented a lawful purpose, approved privacy notice, restricted access, consent process and retention schedule for the selected policy.</span></label>
            <label><span>Privacy contact email</span><input type="email" name="privacy_contact" value="<?= e(old('privacy_contact', settingValue($settings, 'privacy_contact'))) ?>"></label>
        </section>

        <section class="card settings-section" id="payment" data-section-panel>
            <div class="section-title"><div><span class="eyebrow">Manual verification</span><h2>Payment instructions</h2></div></div>
            <div class="form-grid"><label><span>UPI ID</span><input name="upi_id" value="<?= e(old('upi_id', settingValue($settings, 'upi_id'))) ?>" placeholder="college@bank"></label><label class="full"><span>Bank and beneficiary details</span><textarea name="bank_details" rows="5"><?= e(old('bank_details', settingValue($settings, 'bank_details'))) ?></textarea></label></div>
        </section>

        <section class="card settings-section" id="cycles" data-section-panel>
            <div class="section-title"><div><span class="eyebrow">Admissions</span><h2>Cycle configuration has moved</h2></div></div>
            <div class="report-notice"><span>i</span><p>Cycles, programmes, seats, eligibility, forms, documents and fees are managed in the dedicated, versioned Admissions workspace.</p></div>
            <a class="button button-primary" href="<?= url('admin/admissions') ?>">Open Admissions workspace →</a>
        </section>

        <section class="card settings-section" id="security" data-section-panel>
            <div class="section-title"><div><span class="eyebrow">Production baseline</span><h2>Security status</h2></div></div>
            <div class="security-checks"><div><i>✓</i><span><b>Protected private storage</b><small>Uploads are streamed through authorisation checks</small></span></div><div><i>✓</i><span><b>AES-256-GCM field encryption</b><small>Configured identity and SMTP credential values use the application key</small></span></div><div><i>✓</i><span><b>Staff email MFA</b><small>Required when enabled in environment settings</small></span></div><div><i>✓</i><span><b>Audit trail</b><small>Settings, connection tests, sign-in and decisions are recorded without secrets</small></span></div></div>
        </section>

        <div class="settings-save"><span>Changes to sensitive settings are encrypted where required and written to the audit trail without passwords.</span><button class="button button-primary" type="submit">Save all settings</button></div>
    </div>
</form>
